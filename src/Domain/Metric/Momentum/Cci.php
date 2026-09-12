<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Momentum;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BarMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Commodity channel index (ROADMAP §4.2 M-08).
 *
 *   TP  = (H + L + C) / 3                       typical price
 *   CCI = (TP − SMA_N(TP)) / (0.015 · MAD_N)
 *   MAD = mean of |TP_i − SMA_N(TP)|            MEAN ABSOLUTE deviation
 *
 * Two constants carry the whole calibration, and both are easy to get wrong.
 *
 * Lambert chose 0.015 so that roughly 70-80 % of values land inside ±100;
 * that is what makes ±100 a meaningful threshold rather than an arbitrary
 * number.
 *
 * The denominator is the **mean absolute** deviation, not the standard
 * deviation. Substituting the standard deviation — the usual shortcut, since
 * every statistics library has it and it updates in O(1) — divides by a
 * quantity about 1/0.7979 ≈ 1.2533 times larger for normally distributed
 * input, so every reading shrinks by a quarter and the ±100 band stops
 * matching the distribution it was calibrated against. `MAD` costs an O(N)
 * scan per bar; at N = 20 that is nothing, and it is the definition.
 */
final class Cci implements BarMetric
{
	public const LAMBERT_CONSTANT = 0.015;

	private RollingMoments $window;

	private float $typical = \NAN;

	public function __construct(
		public readonly int $period = 20,
		public readonly float $constant = self::LAMBERT_CONSTANT,
	) {
		if ($period < 1) {
			throw new InvalidArgument(\sprintf('CCI period must be >= 1, got %d', $period));
		}
		if (!($constant > 0.0)) {
			throw new InvalidArgument('CCI constant must be > 0');
		}
		$this->window = new RollingMoments($period);
	}

	public static function type(): string
	{
		return 'cci';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-08',
			symbol: 'CCI',
			category: MetricCategory::Momentum,
			inputs: [MetricInput::Bars],
			kernels: ['Cci::compute()', 'Cci::typicalPrices()'],
			nameEn: 'Commodity channel index',
			nameRu: 'Commodity Channel Index',
			algoEn: 'Readings beyond ±100 flag a move out of the typical range. The calibration only holds with the mean absolute deviation in the denominator, not the standard deviation.',
			algoRu: 'Выход за ±100 отмечает уход из типичного диапазона. Калибровка работает только со средним абсолютным отклонением в знаменателе, не со стандартным.',
			plainEn: 'Deviation of the typical price from its moving average, measured in mean absolute deviations and scaled by Lambert\'s 0.015.',
			plainRu: 'Отклонение типичной цены от своей скользящей средней, измеренное в средних абсолютных отклонениях и масштабированное константой Ламберта 0.015.',
			example: 'examples/momentum-cci.php',
		);
	}

	public function updateBar(Bar $bar): void
	{
		$this->feed($bar->typicalPrice());
	}

	private function feed(float $typical): void
	{
		$this->typical = $typical;
		$this->window->push($typical);
	}

	public function isReady(): bool
	{
		return $this->window->isFull();
	}

	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		return self::index($this->typical, $this->window->mean(), $this->window->meanAbsoluteDeviation(), $this->constant);
	}

	/** @return array{cci: float, typical: float, mad: float} */
	public function values(): array
	{
		if (!$this->isReady()) {
			return ['cci' => \NAN, 'typical' => $this->typical, 'mad' => \NAN];
		}
		return [
			'cci' => $this->value(),
			'typical' => $this->typical,
			'mad' => $this->window->meanAbsoluteDeviation(),
		];
	}

	public function reset(): void
	{
		$this->window->reset();
		$this->typical = \NAN;
	}

	/** @return array{type: string, period: int, constant: float} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'period' => $this->period, 'constant' => $this->constant];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['period']) && \is_int($config['period']) ? $config['period'] : 20,
			isset($config['constant']) && (\is_float($config['constant']) || \is_int($config['constant']))
				? (float) $config['constant']
				: self::LAMBERT_CONSTANT,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float> aligned with the input, NAN until the window is full
	 */
	public static function compute(array $highs, array $lows, array $closes, int $period = 20, float $constant = self::LAMBERT_CONSTANT): array
	{
		$typical = self::typicalPrices($highs, $lows, $closes);
		$metric = new self($period, $constant);
		$out = [];
		foreach ($typical as $tp) {
			$metric->feed($tp);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * (H + L + C) / 3 for each bar — the input of the CCI and of the bar form
	 * of VWAP.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	public static function typicalPrices(array $highs, array $lows, array $closes): array
	{
		$n = \count($closes);
		if (\count($highs) !== $n || \count($lows) !== $n) {
			throw new InvalidArgument('CCI needs highs, lows and closes of equal length');
		}
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$out[] = ($highs[$i] + $lows[$i] + $closes[$i]) / 3.0;
		}
		return $out;
	}

	private static function index(float $typical, float $mean, float $mad, float $constant): float
	{
		// A window with no dispersion has no scale to measure a deviation in.
		return $mad > 0.0 ? ($typical - $mean) / ($constant * $mad) : 0.0;
	}
}
