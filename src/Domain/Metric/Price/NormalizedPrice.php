<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Price;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Price in dimensionless form (ROADMAP §4.1 M-01), in the three shapes that
 * are actually used, computed from one rolling window:
 *
 *   z     = (P − μ) / σ        standardised
 *   ratio = P / μ              the reference PromQL form
 *   log   = ln(P / μ)          symmetric and additive
 *
 * `ratio` is the form the reference stack uses, and it is the weakest of the
 * three: it has no fixed scale, so its spread is whatever σ/μ happens to be
 * for this instrument today. A threshold tuned on one asset does not carry to
 * another, and as a neural-network input it is a constant near 1 with a tiny
 * perturbation — badly conditioned by construction. The z-score has unit
 * variance by definition, which is why it is the default here.
 *
 * The library's own answer to "what is the mean of this price" is better still
 * than any rolling window: `LocalLinearTrend` gives μ with less lag and hands
 * back √P_pp as σ. `Filtered\Smoothed` wires that up.
 */
final class NormalizedPrice implements PriceMetric
{
	private RollingMoments $window;

	private float $price = \NAN;

	public function __construct(public readonly int $period = 120)
	{
		if ($period < 2) {
			throw new InvalidArgument(\sprintf('NormalizedPrice period must be >= 2, got %d', $period));
		}
		$this->window = new RollingMoments($period);
	}

	public static function type(): string
	{
		return 'normalized-price';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-01',
			symbol: 'NP',
			category: MetricCategory::Price,
			inputs: [MetricInput::Ticks],
			kernels: ['NormalizedPrice::zScore()', 'NormalizedPrice::ratio()', 'NormalizedPrice::logRatio()'],
			nameEn: 'Normalised price',
			nameRu: 'Нормализованная цена',
			algoEn: 'A scale-free input for models and thresholds that transfer between instruments. The ratio form the reference uses has no fixed scale, so its thresholds do not transfer.',
			algoRu: 'Безразмерный вход для моделей и порогов, переносимый между инструментами. Форма отношения из референса не имеет фиксированной шкалы, поэтому её пороги не переносятся.',
			plainEn: 'Price expressed in standard deviations from its rolling mean, as a ratio to that mean, or as the log of that ratio.',
			plainRu: 'Цена, выраженная в стандартных отклонениях от скользящего среднего, в виде отношения к нему или логарифма этого отношения.',
			example: 'examples/price-normalized.php',
		);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		$this->price = $price;
		$this->window->push($price);
	}

	public function isReady(): bool
	{
		return $this->window->isFull();
	}

	/** The z-score. */
	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		return self::standardise($this->price, $this->window->mean(), $this->window->populationStdDev());
	}

	/** @return array{z: float, ratio: float, log: float} */
	public function values(): array
	{
		if (!$this->isReady()) {
			return ['z' => \NAN, 'ratio' => \NAN, 'log' => \NAN];
		}
		$mean = $this->window->mean();
		return [
			'z' => self::standardise($this->price, $mean, $this->window->populationStdDev()),
			'ratio' => self::divide($this->price, $mean),
			'log' => self::logDivide($this->price, $mean),
		];
	}

	public function reset(): void
	{
		$this->window->reset();
		$this->price = \NAN;
	}

	/** @return array{type: string, period: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'period' => $this->period];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(isset($config['period']) && \is_int($config['period']) ? $config['period'] : 120);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * (P − μ) / σ over a rolling window, with the population σ.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> aligned with the input, NAN until the window is full
	 */
	public static function zScore(array $prices, int $period): array
	{
		$window = new RollingMoments($period);
		$out = [];
		foreach ($prices as $price) {
			$window->push($price);
			$out[] = $window->isFull()
				? self::standardise($price, $window->mean(), $window->populationStdDev())
				: \NAN;
		}
		return $out;
	}

	/**
	 * P / μ — the reference form. Kept because existing models are trained on
	 * it, not because it is the better normalisation.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function ratio(array $prices, int $period): array
	{
		$window = new RollingMoments($period);
		$out = [];
		foreach ($prices as $price) {
			$window->push($price);
			$out[] = $window->isFull() ? self::divide($price, $window->mean()) : \NAN;
		}
		return $out;
	}

	/**
	 * ln(P / μ): symmetric around zero and additive over time, unlike the ratio.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function logRatio(array $prices, int $period): array
	{
		$window = new RollingMoments($period);
		$out = [];
		foreach ($prices as $price) {
			$window->push($price);
			$out[] = $window->isFull() ? self::logDivide($price, $window->mean()) : \NAN;
		}
		return $out;
	}

	private static function standardise(float $price, float $mean, float $sigma): float
	{
		// A flat window has σ = 0: the z-score is undefined, not zero and not
		// infinite (ROADMAP M4 — guard the division explicitly).
		return $sigma > 0.0 ? ($price - $mean) / $sigma : \NAN;
	}

	private static function divide(float $price, float $mean): float
	{
		return $mean > 0.0 ? $price / $mean : \NAN;
	}

	private static function logDivide(float $price, float $mean): float
	{
		return $mean > 0.0 && $price > 0.0 ? \log($price / $mean) : \NAN;
	}
}
