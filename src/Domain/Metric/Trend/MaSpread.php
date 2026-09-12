<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Trend;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;

/**
 * Spread between a short and a long moving average (ROADMAP §4.3 M-12, the
 * reference `SMA_2h_2d`).
 *
 * The difference of two averages is a band-pass filter: it removes what both
 * share (the level) and what neither resolves (the tick noise), leaving the
 * band of frequencies between the two windows. Positive means the short
 * horizon leads the long one.
 *
 * What this class deliberately does not provide is the reference's `dSMA` and
 * `ddSMA` — finite differences of this spread taken 15 minutes apart on a
 * 2-hour window. Those two terms share 87.5 % of their data, so the difference
 * is almost entirely the noise that did not cancel, amplified by division by a
 * small step; the ×100 and ×1000 scale factors in the reference formulas are a
 * symptom of that. `Filtered\Derivative` gives the same derivative from the
 * filter, with an error bar attached.
 *
 * `relative()` divides by the slow average, which makes the spread comparable
 * across instruments and across time.
 */
final class MaSpread implements PriceMetric
{
	private MovingAverage $fastMa;

	private MovingAverage $slowMa;

	public function __construct(
		public readonly int $fast = 120,
		public readonly int $slow = 2880,
		public readonly MaType $type = MaType::Sma,
	) {
		if ($fast < 1 || $slow < 1) {
			throw new InvalidArgument('MaSpread periods must be >= 1');
		}
		if ($fast >= $slow) {
			throw new InvalidArgument(\sprintf('MaSpread fast period (%d) must be shorter than the slow one (%d)', $fast, $slow));
		}
		$this->fastMa = new MovingAverage($fast, $type);
		$this->slowMa = new MovingAverage($slow, $type);
	}

	public static function type(): string
	{
		return 'ma-spread';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-12',
			symbol: 'dSMA',
			category: MetricCategory::Trend,
			inputs: [MetricInput::Ticks, MetricInput::Bars],
			kernels: ['MaSpread::compute()', 'MaSpread::relative()'],
			nameEn: 'Moving-average spread',
			nameRu: 'Разность скользящих средних',
			algoEn: 'A band-pass view of the trend: positive means the short horizon leads the long one. Its finite differences, which the reference also computes, are mostly noise.',
			algoRu: 'Полосовой взгляд на тренд: положительное значение означает, что короткий горизонт ведёт длинный. Его конечные разности, которые тоже считает референс, состоят в основном из шума.',
			plainEn: 'Difference between a short and a long moving average, optionally divided by the long one.',
			plainRu: 'Разность короткой и длинной скользящих средних, при необходимости делённая на длинную.',
			example: 'examples/trend-ma-spread.php',
		);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		$this->fastMa->updatePrice($timestampNs, $price);
		$this->slowMa->updatePrice($timestampNs, $price);
	}

	public function isReady(): bool
	{
		return $this->slowMa->isReady();
	}

	public function value(): float
	{
		$fast = $this->fastMa->value();
		$slow = $this->slowMa->value();
		return \is_nan($fast) || \is_nan($slow) ? \NAN : $fast - $slow;
	}

	/** @return array{spread: float, relative: float, fast: float, slow: float} */
	public function values(): array
	{
		$fast = $this->fastMa->value();
		$slow = $this->slowMa->value();
		$spread = \is_nan($fast) || \is_nan($slow) ? \NAN : $fast - $slow;
		return [
			'spread' => $spread,
			'relative' => \is_nan($spread) || $slow <= 0.0 ? \NAN : $spread / $slow,
			'fast' => $fast,
			'slow' => $slow,
		];
	}

	public function reset(): void
	{
		$this->fastMa->reset();
		$this->slowMa->reset();
	}

	/** @return array{type: string, fast: int, slow: int, ma: string} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'fast' => $this->fast, 'slow' => $this->slow, 'ma' => $this->type->value];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['fast']) && \is_int($config['fast']) ? $config['fast'] : 120,
			isset($config['slow']) && \is_int($config['slow']) ? $config['slow'] : 2880,
			isset($config['ma']) && \is_string($config['ma']) ? MaType::from($config['ma']) : MaType::Sma,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> aligned with the input, NAN until the long window is full
	 */
	public static function compute(array $prices, int $fast, int $slow, string $ma = 'sma'): array
	{
		$metric = new self($fast, $slow, MaType::from($ma));
		$out = [];
		foreach ($prices as $price) {
			$metric->updatePrice(0, $price);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * The spread divided by the long average — dimensionless, so thresholds
	 * carry between instruments.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function relative(array $prices, int $fast, int $slow, string $ma = 'sma'): array
	{
		$metric = new self($fast, $slow, MaType::from($ma));
		$out = [];
		foreach ($prices as $price) {
			$metric->updatePrice(0, $price);
			$out[] = $metric->values()['relative'];
		}
		return $out;
	}
}
