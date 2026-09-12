<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Volatility;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\MonotonicWindow;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Relative price range over a rolling window (ROADMAP §4.6 M-21, the
 * reference RPR):
 *
 *   RPR = (max − min) / mean
 *
 * It is a perfectly usable volatility reading, but it is **not** a standard
 * deviation, and the conversion is worth stating because the reference does
 * not. Parkinson's estimator says σ = ln(H/L) / (2√ln2); for a small range
 * ln(H/L) ≈ (H−L)/mid = RPR, so
 *
 *   σ ≈ RPR / 1.6651 ≈ 0.6 · RPR
 *
 * Read as a volatility, RPR overstates σ by about two thirds. That does not
 * matter while it is only compared against itself, and it matters a great deal
 * the moment it is compared with anything on a σ scale — a filter's Q, an
 * implied volatility, a risk limit.
 *
 * `sigma()` applies the conversion; `value()` returns the raw range so the
 * reference figure stays reproducible.
 *
 * One more caveat the reference hides: it smooths the price with a 5-minute
 * average before taking the extremes, which by construction clips the very
 * spikes the range is meant to measure. This class exposes `smoothing` so the
 * effect can be seen rather than assumed — set it to 1 for the true range.
 */
final class RangeVolatility implements PriceMetric
{
	/** 2·√(ln 2) — the Parkinson scale factor between a log range and σ. */
	public const PARKINSON_FACTOR = 1.6651092223153954;

	private MonotonicWindow $highs;

	private MonotonicWindow $lows;

	private RollingMoments $level;

	private ?RollingMoments $smoother = null;

	public function __construct(
		public readonly int $window = 120,
		public readonly int $smoothing = 1,
	) {
		if ($window < 2) {
			throw new InvalidArgument(\sprintf('RangeVolatility window must be >= 2, got %d', $window));
		}
		if ($smoothing < 1) {
			throw new InvalidArgument('RangeVolatility smoothing must be >= 1');
		}
		$this->highs = MonotonicWindow::max($window);
		$this->lows = MonotonicWindow::min($window);
		$this->level = new RollingMoments($window);
		if ($smoothing > 1) {
			$this->smoother = new RollingMoments($smoothing);
		}
	}

	public static function type(): string
	{
		return 'range-volatility';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-21',
			symbol: 'RPR',
			category: MetricCategory::Volatility,
			inputs: [MetricInput::Ticks],
			kernels: ['RangeVolatility::compute()', 'RangeVolatility::sigmaSeries()', 'RangeVolatility::toSigma()'],
			nameEn: 'Relative price range',
			nameRu: 'Относительный диапазон цены',
			algoEn: 'A quick volatility reading, but it overstates sigma by about 1.67x until converted. Pre-smoothing the price, as the reference does, clips the very spikes the range is meant to measure.',
			algoRu: 'Быстрая оценка волатильности, но она завышает сигму примерно в 1.67 раза до пересчёта. Предварительное сглаживание цены, как в референсе, срезает те самые всплески, которые диапазон и должен измерять.',
			plainEn: 'High-low range over a rolling window divided by the window mean, with the Parkinson conversion to a standard deviation.',
			plainRu: 'Размах максимума и минимума за скользящее окно, делённый на среднее по окну, с пересчётом Паркинсона в стандартное отклонение.',
			example: 'examples/volatility-range.php',
		);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		$smoother = $this->smoother;
		if ($smoother !== null) {
			$smoother->push($price);
			if (!$smoother->isFull()) {
				return;
			}
			$price = $smoother->mean();
		}
		$this->highs->push($price);
		$this->lows->push($price);
		$this->level->push($price);
	}

	public function isReady(): bool
	{
		return $this->level->isFull();
	}

	/** The raw relative range — the reference figure. */
	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		$mean = $this->level->mean();
		return $mean > 0.0 ? ($this->highs->value() - $this->lows->value()) / $mean : \NAN;
	}

	/** The range converted to a standard deviation via Parkinson. */
	public function sigma(): float
	{
		return self::toSigma($this->value());
	}

	/** @return array{rpr: float, sigma: float, high: float, low: float} */
	public function values(): array
	{
		return [
			'rpr' => $this->value(),
			'sigma' => $this->sigma(),
			'high' => $this->highs->value(),
			'low' => $this->lows->value(),
		];
	}

	public function reset(): void
	{
		$this->highs->reset();
		$this->lows->reset();
		$this->level->reset();
		$this->smoother?->reset();
	}

	/** @return array{type: string, window: int, smoothing: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'window' => $this->window, 'smoothing' => $this->smoothing];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 120,
			isset($config['smoothing']) && \is_int($config['smoothing']) ? $config['smoothing'] : 1,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> aligned with the input, NAN until the window is full
	 */
	public static function compute(array $prices, int $window = 120, int $smoothing = 1): array
	{
		$metric = new self($window, $smoothing);
		$out = [];
		foreach ($prices as $price) {
			$metric->updatePrice(0, $price);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * The same series converted to standard deviations.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function sigmaSeries(array $prices, int $window = 120, int $smoothing = 1): array
	{
		$out = [];
		foreach (self::compute($prices, $window, $smoothing) as $range) {
			$out[] = self::toSigma($range);
		}
		return $out;
	}

	/**
	 * Parkinson conversion of a relative range into a standard deviation.
	 *
	 * @deterministic
	 * @offloadable
	 */
	public static function toSigma(float $relativeRange): float
	{
		return \is_nan($relativeRange) ? \NAN : $relativeRange / self::PARKINSON_FACTOR;
	}
}
