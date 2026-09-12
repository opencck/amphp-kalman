<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Volatility;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Realised and exponentially weighted volatility from a price series
 * (ROADMAP §4.6 M-22).
 *
 *   realised σ² = Σ r_i² over the window        r = ln(P_t / P_{t−1})
 *   EWMA     σ²_t = λ·σ²_{t−1} + (1−λ)·r_t²     λ = 0.94, RiskMetrics
 *
 * Realised volatility is what actually happened: no model, no distribution,
 * just the sum of squared returns. Its weakness is the flat window — a shock
 * counts exactly as much on its last day inside the window as on its first,
 * and then vanishes entirely, so the series steps down for no reason a window
 * later. The EWMA has no such edge: the shock decays smoothly, and with
 * λ = 0.94 roughly half the weight sits in the most recent eleven
 * observations, which is why RiskMetrics picked it for daily data.
 *
 * Note that squared returns are a very noisy proxy for variance — that is the
 * whole reason the range-based estimators in `OhlcVolatility` exist, and the
 * reason `StochasticVolatility` treats volatility as a hidden state to be
 * filtered rather than a number to be read off.
 */
final class RealizedVolatility implements PriceMetric
{
	public const RISKMETRICS_LAMBDA = 0.94;

	private RollingMoments $squares;

	private float $ewmaVariance = \NAN;

	private float $previous = \NAN;

	private float $lastReturn = \NAN;

	public function __construct(
		public readonly int $window = 60,
		public readonly float $lambda = self::RISKMETRICS_LAMBDA,
	) {
		if ($window < 2) {
			throw new InvalidArgument(\sprintf('RealizedVolatility window must be >= 2, got %d', $window));
		}
		if (!($lambda > 0.0) || $lambda >= 1.0) {
			throw new InvalidArgument('RealizedVolatility lambda must be in (0, 1)');
		}
		$this->squares = new RollingMoments($window);
	}

	public static function type(): string
	{
		return 'realized-volatility';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-37',
			symbol: 'RV',
			category: MetricCategory::Volatility,
			inputs: [MetricInput::Ticks],
			kernels: ['RealizedVolatility::realized()', 'RealizedVolatility::ewma()', 'RealizedVolatility::logReturns()'],
			nameEn: 'Realised and EWMA volatility',
			nameRu: 'Реализованная и EWMA волатильность',
			algoEn: 'The volatility that actually happened. The flat window steps down a window after a shock for no reason; the EWMA decays it smoothly instead.',
			algoRu: 'Волатильность, которая действительно случилась. Прямоугольное окно даёт беспричинную ступеньку через окно после шока; EWMA вместо этого затухает плавно.',
			plainEn: 'Root mean square of log returns over a window, and its exponentially weighted counterpart.',
			plainRu: 'Среднеквадратичное лог-доходностей за окно и его экспоненциально взвешенный аналог.',
			example: 'examples/volatility-realized.php',
		);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		if ($price <= 0.0) {
			throw new InvalidArgument('RealizedVolatility needs strictly positive prices');
		}
		if (\is_nan($this->previous)) {
			$this->previous = $price;
			return;
		}
		$r = \log($price / $this->previous);
		$this->previous = $price;
		$this->lastReturn = $r;
		$square = $r * $r;
		$this->squares->push($square);
		$this->ewmaVariance = \is_nan($this->ewmaVariance)
			? $square
			: $this->lambda * $this->ewmaVariance + (1.0 - $this->lambda) * $square;
	}

	public function isReady(): bool
	{
		return $this->squares->isFull();
	}

	/** Realised standard deviation per observation. */
	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		$mean = $this->squares->mean();
		return $mean > 0.0 ? \sqrt($mean) : 0.0;
	}

	public function ewmaValue(): float
	{
		return \is_nan($this->ewmaVariance) ? \NAN : \sqrt($this->ewmaVariance);
	}

	/** @return array{realized: float, ewma: float, lastReturn: float} */
	public function values(): array
	{
		return [
			'realized' => $this->value(),
			'ewma' => $this->ewmaValue(),
			'lastReturn' => $this->lastReturn,
		];
	}

	/** Scales the per-observation standard deviation to a year. */
	public function annualised(float $observationsPerYear): float
	{
		$sigma = $this->value();
		return \is_nan($sigma) ? \NAN : $sigma * \sqrt($observationsPerYear);
	}

	public function reset(): void
	{
		$this->squares->reset();
		$this->ewmaVariance = \NAN;
		$this->previous = \NAN;
		$this->lastReturn = \NAN;
	}

	/** @return array{type: string, window: int, lambda: float} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'window' => $this->window, 'lambda' => $this->lambda];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 60,
			isset($config['lambda']) && (\is_float($config['lambda']) || \is_int($config['lambda']))
				? (float) $config['lambda']
				: self::RISKMETRICS_LAMBDA,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> aligned with the input, NAN until the window is full
	 */
	public static function realized(array $prices, int $window = 60): array
	{
		$metric = new self($window);
		$out = [];
		foreach ($prices as $price) {
			$metric->updatePrice(0, $price);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> defined from the second price onwards
	 */
	public static function ewma(array $prices, float $lambda = self::RISKMETRICS_LAMBDA): array
	{
		$metric = new self(2, $lambda);
		$out = [];
		foreach ($prices as $price) {
			$metric->updatePrice(0, $price);
			$out[] = $metric->ewmaValue();
		}
		return $out;
	}

	/**
	 * ln(P_t / P_{t−1}) for the series; NAN for the first entry.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function logReturns(array $prices): array
	{
		$out = [];
		$previous = \NAN;
		foreach ($prices as $price) {
			$out[] = \is_nan($previous) || $price <= 0.0 || $previous <= 0.0 ? \NAN : \log($price / $previous);
			$previous = $price;
		}
		return $out;
	}
}
