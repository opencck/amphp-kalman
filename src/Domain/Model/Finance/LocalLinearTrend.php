<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;

/**
 * §3.1 Local linear trend: de-noised price and its current velocity.
 *
 *   x = [p, v]ᵀ,  F = [1 dt; 0 1],  Q = σ_a²·[dt³/3 dt²/2; dt²/2 dt]
 *   z = [quote],  H = [1 0],        R = half_spread² + tick²/12
 *
 * Trend signal: v̂ / √P_vv (t-statistic).
 */
final class LocalLinearTrend implements SerializableModel
{
	public function __construct(
		private readonly float $sigmaA,
		private readonly float $halfSpread,
		private readonly float $tick = 0.0,
	) {
		if ($halfSpread < 0.0 || $tick < 0.0) {
			throw new InvalidArgument('halfSpread and tick must be >= 0');
		}
		if ($halfSpread === 0.0 && $tick === 0.0) {
			throw new InvalidArgument('At least one of halfSpread / tick must be > 0 (R must be positive)');
		}
	}

	public function motion(): ConstantVelocity
	{
		return new ConstantVelocity($this->sigmaA);
	}

	public function observation(): StaticObservation
	{
		return new StaticObservation([[0 => 1.0]], [$this->measurementVariance()], ['price']);
	}

	public function measurementVariance(): float
	{
		return $this->halfSpread * $this->halfSpread + $this->tick * $this->tick / 12.0;
	}

	/**
	 * Filter initialised at a first quote with zero velocity; the prior is
	 * wide on velocity so the first few ticks determine it.
	 */
	public function filter(float $firstPrice, ?FilterConfig $config = null, float $velocityPriorStd = 1.0): KalmanFilter
	{
		$r = $this->measurementVariance();
		return new KalmanFilter(
			$this->motion(),
			$this->observation(),
			[$firstPrice, 0.0],
			[$r, 0.0, 0.0, $velocityPriorStd * $velocityPriorStd],
			$config,
		);
	}

	/** t-statistic of the trend: v̂ / √P_vv. */
	public static function trendScore(KalmanFilter $filter): float
	{
		return $filter->meanAt(1) / \sqrt($filter->variance(1));
	}

	public static function type(): string
	{
		return 'local-linear-trend';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'sigmaA' => $this->sigmaA, 'halfSpread' => $this->halfSpread, 'tick' => $this->tick];
	}

	public static function fromArray(array $config): static
	{
		return new self(
			ConfigReader::float($config, 'sigmaA'),
			ConfigReader::float($config, 'halfSpread'),
			ConfigReader::float($config, 'tick', 0.0),
		);
	}
}
