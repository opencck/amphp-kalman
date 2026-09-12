<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Momentum;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RingBuffer;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;
use OpenCCK\Kalman\Domain\Metric\Trend\MaType;

/**
 * Relative strength index (ROADMAP §4.2 M-05).
 *
 * Wilder's definition, and the only one the 70/30 thresholds belong to:
 *
 *   U_t = max(P_t − P_{t−1}, 0)        gains between CONSECUTIVE observations
 *   D_t = max(P_{t−1} − P_t, 0)
 *   Ū_t = Ū_{t−1} + (U_t − Ū_{t−1})/N  Wilder's RMA, α = 1/N
 *   RSI = 100 · Ū / (Ū + D̄)
 *
 * The class is parameterised by the two knobs that the reference PromQL
 * formula turns differently, because the difference is the whole story:
 *
 *  - `lagSteps`: the canonical index compares each observation with the
 *    previous one. The reference compares it with the one 30 minutes back,
 *    which makes the input a momentum series rather than a series of
 *    increments — and the consequence runs the opposite way to the intuition.
 *    Under a trend with noise, individual steps still go both ways, so the
 *    canonical index keeps a working range and keeps discriminating. A
 *    difference against a distant past, though, is positive almost every time
 *    the drift outweighs the noise over that span: no losses remain in the
 *    window, D̄ falls to zero, and the lagged variant pins at 100.
 *    `examples/momentum-rsi.php` measures it — on a steady uptrend Wilder's
 *    RSI spans 52 to 90 and never pins, while the lagged variant returns
 *    exactly 100 on all 357 of its samples, and over an up-chop-down cycle it
 *    sits at 0 or 100 on 649 bars out of 857. The 70/30 thresholds do not
 *    transfer to it — not because they are offset, but because the quantity
 *    has stopped carrying information.
 *  - `smoothing`: Wilder's RMA has a centre of mass of N−1 observations and a
 *    long tail. A plain rolling average (`avg_over_time`) cuts the tail off at
 *    N. After a spike the two disagree by several points for many bars.
 *
 * `Rsi::wilder()` is the canonical kernel; `Rsi::lagged()` reproduces the
 * reference exactly, under a name that says what it is.
 *
 * Degenerate windows are resolved explicitly rather than by dividing by zero:
 * no losses gives 100, no gains gives 0, and a perfectly flat window gives 50.
 */
final class Rsi implements PriceMetric
{
	private RingBuffer $lagged;

	private ?RollingMoments $gainWindow = null;

	private ?RollingMoments $lossWindow = null;

	private float $avgGain = \NAN;

	private float $avgLoss = \NAN;

	private float $seedGain = 0.0;

	private float $seedLoss = 0.0;

	private int $diffs = 0;

	public function __construct(
		public readonly int $period = 14,
		public readonly int $lagSteps = 1,
		public readonly MaType $smoothing = MaType::Rma,
	) {
		if ($period < 1) {
			throw new InvalidArgument(\sprintf('RSI period must be >= 1, got %d', $period));
		}
		if ($lagSteps < 1) {
			throw new InvalidArgument(\sprintf('RSI lag must be >= 1, got %d', $lagSteps));
		}
		if ($smoothing !== MaType::Rma && $smoothing !== MaType::Sma) {
			throw new InvalidArgument('RSI smoothing must be MaType::Rma (Wilder) or MaType::Sma (the reference variant)');
		}
		$this->lagged = new RingBuffer($lagSteps + 1);
		if ($smoothing === MaType::Sma) {
			$this->gainWindow = new RollingMoments($period);
			$this->lossWindow = new RollingMoments($period);
		}
	}

	/** The reference PromQL variant: lagged differences, plain rolling average. */
	public static function reference(int $window, int $lagSteps): self
	{
		return new self($window, $lagSteps, MaType::Sma);
	}

	public static function type(): string
	{
		return 'rsi';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-05',
			symbol: 'RSI',
			category: MetricCategory::Momentum,
			inputs: [MetricInput::Ticks, MetricInput::Bars],
			kernels: ['Rsi::wilder()', 'Rsi::wilderTimed()', 'Rsi::lagged()', 'Rsi::compute()'],
			nameEn: 'Relative strength index',
			nameRu: 'Индекс относительной силы',
			algoEn: 'Overbought above 70, oversold below 30 — but only with Wilder smoothing on consecutive closes. The lagged PromQL variant is a momentum series and carries none of those thresholds.',
			algoRu: 'Перекупленность выше 70, перепроданность ниже 30 — но только со сглаживанием Уайлдера по соседним закрытиям. Лаговый вариант из PromQL это ряд моментума, и эти пороги к нему неприменимы.',
			plainEn: 'Ratio of average gain to average loss over a window, scaled to 0-100.',
			plainRu: 'Отношение среднего роста к среднему падению за окно, приведённое к шкале 0-100.',
			example: 'examples/momentum-rsi.php',
		);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		$this->lagged->push($price);
		if (!$this->lagged->isFull()) {
			return;
		}
		$change = $price - $this->lagged->oldest();
		$gain = $change > 0.0 ? $change : 0.0;
		$loss = $change < 0.0 ? -$change : 0.0;
		$this->diffs++;

		if ($this->smoothing === MaType::Sma) {
			$gainWindow = $this->gainWindow;
			$lossWindow = $this->lossWindow;
			\assert($gainWindow !== null && $lossWindow !== null);
			$gainWindow->push($gain);
			$lossWindow->push($loss);
			if ($gainWindow->isFull()) {
				$this->avgGain = $gainWindow->mean();
				$this->avgLoss = $lossWindow->mean();
			}
			return;
		}

		$period = $this->period;
		if ($this->diffs < $period) {
			$this->seedGain += $gain;
			$this->seedLoss += $loss;
			return;
		}
		if ($this->diffs === $period) {
			$this->seedGain += $gain;
			$this->seedLoss += $loss;
			$this->avgGain = $this->seedGain / $period;
			$this->avgLoss = $this->seedLoss / $period;
			return;
		}
		$alpha = 1.0 / $period;
		$this->avgGain += $alpha * ($gain - $this->avgGain);
		$this->avgLoss += $alpha * ($loss - $this->avgLoss);
	}

	public function isReady(): bool
	{
		return $this->diffs >= $this->period;
	}

	public function value(): float
	{
		return $this->isReady() ? self::index($this->avgGain, $this->avgLoss) : \NAN;
	}

	/** @return array{rsi: float, avgGain: float, avgLoss: float} */
	public function values(): array
	{
		return [
			'rsi' => $this->value(),
			'avgGain' => $this->isReady() ? $this->avgGain : \NAN,
			'avgLoss' => $this->isReady() ? $this->avgLoss : \NAN,
		];
	}

	public function reset(): void
	{
		$this->lagged->reset();
		$this->gainWindow?->reset();
		$this->lossWindow?->reset();
		$this->avgGain = \NAN;
		$this->avgLoss = \NAN;
		$this->seedGain = 0.0;
		$this->seedLoss = 0.0;
		$this->diffs = 0;
	}

	/** @return array{type: string, period: int, lagSteps: int, smoothing: string} */
	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'period' => $this->period,
			'lagSteps' => $this->lagSteps,
			'smoothing' => $this->smoothing->value,
		];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['period']) && \is_int($config['period']) ? $config['period'] : 14,
			isset($config['lagSteps']) && \is_int($config['lagSteps']) ? $config['lagSteps'] : 1,
			isset($config['smoothing']) && \is_string($config['smoothing']) ? MaType::from($config['smoothing']) : MaType::Rma,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Wilder's RSI: consecutive differences, RMA smoothing. This is the one the
	 * textbooks, TA-Lib and every charting package mean by "RSI".
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> aligned with the input, NAN during warm-up
	 */
	public static function wilder(array $prices, int $period = 14): array
	{
		return self::compute($prices, $period, 1, MaType::Rma->value);
	}

	/**
	 * The reference PromQL variant: differences against an observation
	 * `lagSteps` back, smoothed by a plain rolling average. Reproduced so that
	 * models trained on it keep working — not because it is the RSI.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function lagged(array $prices, int $lagSteps, int $window): array
	{
		return self::compute($prices, $window, $lagSteps, MaType::Sma->value);
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param string $smoothing "rma" (Wilder) or "sma" (the reference)
	 * @return list<float>
	 */
	public static function compute(array $prices, int $period, int $lagSteps, string $smoothing): array
	{
		$metric = new self($period, $lagSteps, MaType::from($smoothing));
		$out = [];
		foreach ($prices as $price) {
			$metric->updatePrice(0, $price);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * Wilder's RSI on an irregular stream: α = 1 − exp(−dt/τ) instead of 1/N,
	 * so the averaging horizon is τ seconds of market time regardless of how
	 * many ticks arrive in it. Values are NAN until τ seconds have elapsed.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<int> $timestampsNs
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function wilderTimed(array $timestampsNs, array $prices, float $tauSeconds): array
	{
		if (!($tauSeconds > 0.0)) {
			throw new InvalidArgument('wilderTimed tau must be > 0');
		}
		if (\count($timestampsNs) !== \count($prices)) {
			throw new InvalidArgument('wilderTimed needs one timestamp per price');
		}
		$out = [];
		$avgGain = 0.0;
		$avgLoss = 0.0;
		$previous = \NAN;
		$lastNs = 0;
		$elapsed = 0.0;
		foreach ($prices as $i => $price) {
			$ts = $timestampsNs[$i];
			if (\is_nan($previous)) {
				$previous = $price;
				$lastNs = $ts;
				$out[] = \NAN;
				continue;
			}
			$dt = ($ts - $lastNs) / 1e9;
			$lastNs = $ts;
			if ($dt > 0.0) {
				$change = $price - $previous;
				$gain = $change > 0.0 ? $change : 0.0;
				$loss = $change < 0.0 ? -$change : 0.0;
				$alpha = 1.0 - \exp(-$dt / $tauSeconds);
				$avgGain += $alpha * ($gain - $avgGain);
				$avgLoss += $alpha * ($loss - $avgLoss);
				$elapsed += $dt;
			}
			$previous = $price;
			$out[] = $elapsed >= $tauSeconds ? self::index($avgGain, $avgLoss) : \NAN;
		}
		return $out;
	}

	/**
	 * 100 · Ū / (Ū + D̄), with the degenerate cases resolved: a window with no
	 * losses is 100, one with no gains is 0, and a flat window is 50 rather
	 * than a division of zero by zero.
	 */
	private static function index(float $avgGain, float $avgLoss): float
	{
		if (\is_nan($avgGain) || \is_nan($avgLoss)) {
			return \NAN;
		}
		$total = $avgGain + $avgLoss;
		if ($total <= 0.0) {
			return 50.0;
		}
		return 100.0 * $avgGain / $total;
	}
}
