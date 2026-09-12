<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Momentum;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BarMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\MonotonicWindow;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Stochastic oscillator (ROADMAP §4.2 M-06): where the close sits inside the
 * recent range.
 *
 *   %K = 100 · (C − L_N) / (H_N − L_N)
 *   %D = SMA_d(%K)                        the signal line
 *
 * Two things the reference PromQL formula leaves out, both of which matter.
 *
 * **%D.** Lane's oscillator is a pair of lines, and the tradable event is
 * their crossing — %K alone is half an indicator with no signal in it. The
 * `dPeriod` smoothing is what turns a jittery ratio into something you can act
 * on.
 *
 * **The range.** The extremes must come from the bars' own highs and lows, not
 * from `min_over_time` / `max_over_time` over sampled quotes. A scraper sees
 * the price every few seconds and misses the wick that made the high, so the
 * sampled range is systematically too narrow and the oscillator reads too
 * extreme — it says "overbought" because it never saw how far the market
 * actually traded.
 *
 * `kSmooth` selects the variant: 1 is the fast stochastic, 3 the slow one.
 * A flat range (H_N = L_N, a bar series stuck on one price) yields 50, the
 * midpoint, rather than a division by zero.
 */
final class Stochastic implements BarMetric
{
	private MonotonicWindow $highs;

	private MonotonicWindow $lows;

	private ?RollingMoments $kSmoother = null;

	private RollingMoments $dSmoother;

	private float $rawK = \NAN;

	private float $k = \NAN;

	private int $bars = 0;

	public function __construct(
		public readonly int $kPeriod = 14,
		public readonly int $kSmooth = 1,
		public readonly int $dPeriod = 3,
	) {
		if ($kPeriod < 1 || $kSmooth < 1 || $dPeriod < 1) {
			throw new InvalidArgument('Stochastic periods must be >= 1');
		}
		$this->highs = MonotonicWindow::max($kPeriod);
		$this->lows = MonotonicWindow::min($kPeriod);
		if ($kSmooth > 1) {
			$this->kSmoother = new RollingMoments($kSmooth);
		}
		$this->dSmoother = new RollingMoments($dPeriod);
	}

	/** Fast stochastic: raw %K with a 3-bar signal line. */
	public static function fast(int $kPeriod = 14, int $dPeriod = 3): self
	{
		return new self($kPeriod, 1, $dPeriod);
	}

	/** Slow stochastic: %K smoothed over 3 bars, then a 3-bar signal line. */
	public static function slow(int $kPeriod = 14, int $dPeriod = 3): self
	{
		return new self($kPeriod, 3, $dPeriod);
	}

	public static function type(): string
	{
		return 'stochastic';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-06',
			symbol: 'SO',
			category: MetricCategory::Momentum,
			inputs: [MetricInput::Bars],
			kernels: ['Stochastic::percentK()', 'Stochastic::percentD()', 'Stochastic::full()'],
			nameEn: 'Stochastic oscillator',
			nameRu: 'Стохастический осциллятор',
			algoEn: 'The signal is the crossing of %K and %D; %K alone, as the reference computes it, carries no crossover signal at all.',
			algoRu: 'Сигнал даёт пересечение %K и %D; один %K, как его считает референс, вообще не даёт сигналов пересечения.',
			plainEn: 'Position of the close inside the high-low range of the last N bars, with a smoothed signal line.',
			plainRu: 'Положение закрытия внутри диапазона максимума и минимума за N баров, со сглаженной сигнальной линией.',
			example: 'examples/momentum-stochastic.php',
		);
	}

	public function updateBar(Bar $bar): void
	{
		$this->feed($bar->high, $bar->low, $bar->close);
	}

	public function isReady(): bool
	{
		return $this->dSmoother->isFull();
	}

	/** %K — smoothed when kSmooth > 1. */
	public function value(): float
	{
		return $this->k;
	}

	/** @return array{k: float, d: float, rawK: float} */
	public function values(): array
	{
		return [
			'k' => $this->k,
			'd' => $this->dSmoother->isFull() ? $this->dSmoother->mean() : \NAN,
			'rawK' => $this->rawK,
		];
	}

	public function reset(): void
	{
		$this->highs->reset();
		$this->lows->reset();
		$this->kSmoother?->reset();
		$this->dSmoother->reset();
		$this->rawK = \NAN;
		$this->k = \NAN;
		$this->bars = 0;
	}

	public function barsSeen(): int
	{
		return $this->bars;
	}

	/** @return array{type: string, kPeriod: int, kSmooth: int, dPeriod: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'kPeriod' => $this->kPeriod, 'kSmooth' => $this->kSmooth, 'dPeriod' => $this->dPeriod];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['kPeriod']) && \is_int($config['kPeriod']) ? $config['kPeriod'] : 14,
			isset($config['kSmooth']) && \is_int($config['kSmooth']) ? $config['kSmooth'] : 1,
			isset($config['dPeriod']) && \is_int($config['dPeriod']) ? $config['dPeriod'] : 3,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Raw %K over the bar series.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	public static function percentK(array $highs, array $lows, array $closes, int $period = 14): array
	{
		$result = self::full($highs, $lows, $closes, $period, 1, 1);
		return $result['k'];
	}

	/**
	 * The signal line: SMA of %K.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	public static function percentD(array $highs, array $lows, array $closes, int $period = 14, int $dPeriod = 3): array
	{
		$result = self::full($highs, $lows, $closes, $period, 1, $dPeriod);
		return $result['d'];
	}

	/**
	 * Both lines at once — the form actually used for trading.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return array{k: list<float>, d: list<float>}
	 */
	public static function full(
		array $highs,
		array $lows,
		array $closes,
		int $kPeriod = 14,
		int $kSmooth = 1,
		int $dPeriod = 3,
	): array {
		$n = \count($closes);
		if (\count($highs) !== $n || \count($lows) !== $n) {
			throw new InvalidArgument('Stochastic needs highs, lows and closes of equal length');
		}
		$metric = new self($kPeriod, $kSmooth, $dPeriod);
		$k = [];
		$d = [];
		for ($i = 0; $i < $n; $i++) {
			$metric->feed($highs[$i], $lows[$i], $closes[$i]);
			$values = $metric->values();
			$k[] = $values['k'];
			$d[] = $values['d'];
		}
		return ['k' => $k, 'd' => $d];
	}

	/**
	 * Bar-free update for the kernels: the oscillator only ever reads H, L and
	 * C, so building a validated `Bar` per row would be pure overhead.
	 */
	private function feed(float $high, float $low, float $close): void
	{
		$this->bars++;
		$this->highs->push($high);
		$this->lows->push($low);
		if (!$this->highs->isFull()) {
			return;
		}
		$this->rawK = self::position($close, $this->lows->value(), $this->highs->value());
		$smoother = $this->kSmoother;
		if ($smoother === null) {
			$this->k = $this->rawK;
		} else {
			$smoother->push($this->rawK);
			$this->k = $smoother->isFull() ? $smoother->mean() : \NAN;
		}
		if (!\is_nan($this->k)) {
			$this->dSmoother->push($this->k);
		}
	}

	private static function position(float $close, float $low, float $high): float
	{
		$range = $high - $low;
		// A window with no range is not 0 % and not 100 %: the close is neither
		// at the top nor at the bottom of nothing. 50 is the honest answer.
		return $range > 0.0 ? 100.0 * ($close - $low) / $range : 50.0;
	}
}
