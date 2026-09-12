<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Trend;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RingBuffer;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Simple, exponential, Wilder and weighted moving averages (ROADMAP §4.3
 * M-10), plus the time-based exponential average that is the correct form on
 * an irregular tick stream.
 *
 * The recursive averages are seeded with the simple average of the first
 * `period` observations, as Wilder defined them and as TA-Lib and TradingView
 * implement them. Seeding with the first observation instead leaves a
 * transient that takes several periods to decay and makes reference values
 * impossible to reproduce.
 *
 * The time-based form replaces the fixed α with `1 − exp(−dt/τ)`. On a tick
 * stream the number of observations per second is a property of the market,
 * not of the indicator, so a fixed α means the effective averaging horizon
 * breathes with activity: the same "EMA(20)" spans two seconds in a burst and
 * two minutes in a lull (ROADMAP M2).
 */
final class MovingAverage implements PriceMetric
{
	private ?RollingMoments $window = null;

	private ?RingBuffer $weights = null;

	private float $value = \NAN;

	private float $seedSum = 0.0;

	private int $seen = 0;

	private int $lastNs = 0;

	private float $elapsedSeconds = 0.0;

	public function __construct(
		public readonly int $period,
		public readonly MaType $type = MaType::Ema,
		public readonly float $tauSeconds = 0.0,
	) {
		if ($period < 1) {
			throw new InvalidArgument(\sprintf('MovingAverage period must be >= 1, got %d', $period));
		}
		if ($tauSeconds < 0.0 || !\is_finite($tauSeconds)) {
			throw new InvalidArgument('MovingAverage tau must be finite and >= 0');
		}
		if ($this->isTimed() && $type !== MaType::Ema) {
			throw new InvalidArgument('The time-based moving average is only defined for MaType::Ema');
		}
		if ($type === MaType::Sma) {
			$this->window = new RollingMoments($period);
		} elseif ($type === MaType::Wma) {
			$this->weights = new RingBuffer($period);
		}
	}

	public static function timed(float $tauSeconds): self
	{
		return new self(1, MaType::Ema, $tauSeconds);
	}

	public static function type(): string
	{
		return 'moving-average';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-10',
			symbol: 'SMA/EMA/RMA/WMA',
			category: MetricCategory::Trend,
			inputs: [MetricInput::Ticks, MetricInput::Bars],
			kernels: [
				'MovingAverage::sma()',
				'MovingAverage::ema()',
				'MovingAverage::rma()',
				'MovingAverage::wma()',
				'MovingAverage::emaTimed()',
			],
			nameEn: 'Moving averages',
			nameRu: 'Скользящие средние',
			algoEn: 'The primitive under MACD, RSI, CCI and ADX. The time-based variant is the one that is correct on irregular tick streams, where a fixed alpha lets the averaging horizon breathe with activity.',
			algoRu: 'Примитив под MACD, RSI, CCI и ADX. Вариант по времени — единственный корректный на нерегулярном потоке тиков, где фиксированная альфа заставляет горизонт усреднения дышать вместе с активностью.',
			plainEn: 'Weighted averages of a series over a window: equal weights (SMA), exponential decay (EMA), Wilder decay (RMA) and linear weights (WMA).',
			plainRu: 'Взвешенные средние ряда по окну: равные веса (SMA), экспоненциальное затухание (EMA), затухание Уайлдера (RMA) и линейные веса (WMA).',
			example: 'examples/trend-moving-averages.php',
		);
	}

	public function isTimed(): bool
	{
		return $this->tauSeconds > 0.0;
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		if ($this->isTimed()) {
			$this->updateTimed($timestampNs, $price);
			return;
		}
		$this->seen++;
		switch ($this->type) {
			case MaType::Sma:
				$window = $this->window;
				\assert($window !== null);
				$window->push($price);
				$this->value = $window->isFull() ? $window->mean() : \NAN;
				break;
			case MaType::Wma:
				$weights = $this->weights;
				\assert($weights !== null);
				$weights->push($price);
				$this->value = $weights->isFull() ? self::weightedMean($weights) : \NAN;
				break;
			case MaType::Ema:
			case MaType::Rma:
				$this->updateRecursive($price);
				break;
		}
	}

	private function updateRecursive(float $price): void
	{
		$period = $this->period;
		if ($this->seen < $period) {
			$this->seedSum += $price;
			$this->value = \NAN;
			return;
		}
		if ($this->seen === $period) {
			$this->seedSum += $price;
			$this->value = $this->seedSum / $period;
			return;
		}
		$this->value += $this->type->alpha($period) * ($price - $this->value);
	}

	private function updateTimed(int $timestampNs, float $price): void
	{
		if ($this->seen === 0) {
			$this->value = $price;
			$this->lastNs = $timestampNs;
			$this->seen = 1;
			return;
		}
		$dt = ($timestampNs - $this->lastNs) / 1e9;
		$this->lastNs = $timestampNs;
		if ($dt <= 0.0) {
			return;
		}
		$this->elapsedSeconds += $dt;
		$this->seen++;
		$alpha = 1.0 - \exp(-$dt / $this->tauSeconds);
		$this->value += $alpha * ($price - $this->value);
	}

	private static function weightedMean(RingBuffer $window): float
	{
		$n = $window->count();
		$sum = 0.0;
		$weight = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$w = (float) ($i + 1);
			$sum += $window->at($i) * $w;
			$weight += $w;
		}
		return $sum / $weight;
	}

	public function isReady(): bool
	{
		return $this->isTimed() ? $this->elapsedSeconds >= $this->tauSeconds : $this->seen >= $this->period;
	}

	public function value(): float
	{
		return $this->isReady() ? $this->value : \NAN;
	}

	/** @return array{value: float} */
	public function values(): array
	{
		return ['value' => $this->value()];
	}

	public function reset(): void
	{
		$this->window?->reset();
		$this->weights?->reset();
		$this->value = \NAN;
		$this->seedSum = 0.0;
		$this->seen = 0;
		$this->lastNs = 0;
		$this->elapsedSeconds = 0.0;
	}

	/** @return array{type: string, period: int, ma: string, tau: float} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'period' => $this->period, 'ma' => $this->type->value, 'tau' => $this->tauSeconds];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		$period = isset($config['period']) && \is_int($config['period']) ? $config['period'] : 1;
		$ma = isset($config['ma']) && \is_string($config['ma']) ? MaType::from($config['ma']) : MaType::Ema;
		$tau = isset($config['tau']) && (\is_float($config['tau']) || \is_int($config['tau'])) ? (float) $config['tau'] : 0.0;
		return new self($period, $ma, $tau);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Simple moving average.
	 *
	 * Every kernel here drives the streaming object above, so the batch path
	 * and the live path cannot drift apart: there is one definition of each
	 * average, exercised two ways. Correctness comes from the reference tests
	 * in `tests/Reference/Metric`, which check these against independent naive
	 * implementations and published values (BRIEF §2 p.8).
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $values
	 * @return list<float> aligned with the input, NAN until the window is full
	 */
	public static function sma(array $values, int $period): array
	{
		return self::run($values, new self($period, MaType::Sma));
	}

	/**
	 * Exponential moving average, α = 2/(period+1), seeded with the simple
	 * average of the first `period` values.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $values
	 * @return list<float>
	 */
	public static function ema(array $values, int $period): array
	{
		return self::run($values, new self($period, MaType::Ema));
	}

	/**
	 * Wilder's smoothing, α = 1/period. Equivalent to an EMA of period
	 * 2·period−1, and the average RSI, ATR, ADX and DMI are defined on.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $values
	 * @return list<float>
	 */
	public static function rma(array $values, int $period): array
	{
		return self::run($values, new self($period, MaType::Rma));
	}

	/**
	 * Linearly weighted moving average: weight i on the i-th oldest value.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $values
	 * @return list<float>
	 */
	public static function wma(array $values, int $period): array
	{
		return self::run($values, new self($period, MaType::Wma));
	}

	/**
	 * Time-based exponential average: α = 1 − exp(−dt/τ), with dt taken from
	 * the timestamps. Values are NAN until τ seconds of stream have elapsed.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<int> $timestampsNs exchange time, nanoseconds
	 * @param list<float> $values
	 * @return list<float>
	 */
	public static function emaTimed(array $timestampsNs, array $values, float $tauSeconds): array
	{
		if (\count($timestampsNs) !== \count($values)) {
			throw new InvalidArgument('emaTimed needs one timestamp per value');
		}
		$metric = self::timed($tauSeconds);
		$out = [];
		foreach ($values as $i => $value) {
			$metric->updatePrice($timestampsNs[$i], $value);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * @param list<float> $values
	 * @return list<float>
	 */
	private static function run(array $values, self $metric): array
	{
		$out = [];
		foreach ($values as $value) {
			$metric->updatePrice(0, $value);
			$out[] = $metric->value();
		}
		return $out;
	}}
