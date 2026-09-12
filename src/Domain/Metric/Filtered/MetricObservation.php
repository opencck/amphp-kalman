<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Filtered;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\Metric;

/**
 * Turns a metric into an observation the filter can consume (ROADMAP M7).
 *
 * This is the bridge that makes the metric layer part of the library rather
 * than an accessory to it. A metric is a noisy measurement of something
 * unobservable — buying pressure, fair value, volatility — and the filter's
 * job is exactly to combine noisy measurements of a hidden state. Once a
 * metric can produce a `Measurement`, three things become possible:
 *
 *  - **Multi-channel fusion.** RSI, order-book imbalance and volume delta are
 *    three noisy views of one latent quantity. Handed to the filter as three
 *    channels with three different `r` values, the sequential scalar update
 *    weights each by how much it is actually worth, which no hand-written
 *    blend of indicators does.
 *  - **Better volatility.** `StochasticVolatility` normally observes `ln r²`,
 *    whose noise variance is π²/2 ≈ 4.93. A range estimator from
 *    `OhlcVolatility` measures the same quantity with five to eight times less
 *    noise; passing it here, with a matching `r`, improves the filtered
 *    volatility by that factor.
 *  - **Recursive regressions.** Kyle's λ, a hedge ratio and a beta are all
 *    slopes estimated from noisy pairs. As observations they become filter
 *    states with a covariance, updated per tick instead of per window.
 *
 * The noise variance is the caller's responsibility, and deliberately so: only
 * the caller knows what the metric's units mean. Passing a wrong `r` does not
 * break the filter, it just weights the channel badly — which is why
 * `variance()` is explicit rather than guessed.
 *
 * A metric that is not ready produces no measurement at all. A blind step
 * (`Measurement::blind()`) is the right way to advance time without
 * pretending an observation arrived.
 */
final readonly class MetricObservation
{
	public function __construct(
		public Metric $metric,
		public int $channel = 0,
		public float $variance = 1.0,
		public string $output = '',
	) {
		if ($channel < 0) {
			throw new InvalidArgument('MetricObservation channel must be >= 0');
		}
		if (!($variance > 0.0) || !\is_finite($variance)) {
			throw new InvalidArgument('MetricObservation variance must be finite and > 0');
		}
	}

	/** The current reading, or NAN when the metric has nothing to say yet. */
	public function reading(): float
	{
		if (!$this->metric->isReady()) {
			return \NAN;
		}
		if ($this->output === '') {
			return $this->metric->value();
		}
		$values = $this->metric->values();
		return $values[$this->output] ?? \NAN;
	}

	/** A single-channel measurement, or null while the metric is warming up. */
	public function measurement(int $timestampNs): ?Measurement
	{
		$value = $this->reading();
		if (\is_nan($value)) {
			return null;
		}
		return Measurement::at($timestampNs, [$this->channel => $value]);
	}

	/**
	 * Combines several observations into one multi-channel measurement,
	 * skipping the ones that are not ready. Returns a blind measurement when
	 * none of them are — the filter still advances its clock.
	 *
	 * @param list<self> $observations
	 */
	public static function combine(int $timestampNs, array $observations): Measurement
	{
		$values = [];
		foreach ($observations as $observation) {
			$value = $observation->reading();
			if (!\is_nan($value)) {
				$values[$observation->channel] = $value;
			}
		}
		return $values === [] ? Measurement::blind($timestampNs) : Measurement::at($timestampNs, $values);
	}

	/**
	 * The per-channel variances, in channel order — the `R` diagonal for a
	 * filter built from a set of observations.
	 *
	 * @param list<self> $observations
	 * @return list<float>
	 */
	public static function variances(array $observations): array
	{
		$byChannel = [];
		foreach ($observations as $observation) {
			$byChannel[$observation->channel] = $observation->variance;
		}
		\ksort($byChannel);
		return \array_values($byChannel);
	}
}
