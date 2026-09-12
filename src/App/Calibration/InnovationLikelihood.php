<?php declare(strict_types=1);

namespace OpenCCK\Kalman\App\Calibration;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Filter\FilterBatch;

/**
 * §2.11.2 innovation log-likelihood of a history under a model config:
 *
 *   ℓ(θ) = −½ Σ_k [ ỹ_k²/s_k + ln s_k + ln 2π ]      (summed over channels)
 *
 * One evaluation = one filter pass over the history. Gating is forced off
 * (rejected measurements would silently drop out of the sum). This is the
 * objective for NelderMead and the unit of work of LikelihoodTask.
 */
final class InnovationLikelihood
{
	private function __construct()
	{
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param array<string, mixed> $modelConfig FilterFactory::fromConfig() shape
	 * @param array<string, mixed>|null $filterConfig FilterConfig::toArray() shape (gating is overridden to none)
	 * @param array<int, array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>}> $ticks
	 */
	public static function evaluate(array $modelConfig, ?array $filterConfig, array $ticks): float
	{
		$config = $filterConfig === null ? FilterConfig::default() : FilterConfig::fromArray($filterConfig);
		$config = $config->withGating(GatingPolicy::none());
		$result = FilterBatch::run($modelConfig, $config->toArray(), null, $ticks);
		return $result['logLikelihood'];
	}

	/**
	 * Likelihood at a parameter vector θ under a parametrization.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<string, mixed> $modelConfig
	 * @param array<string, mixed>|null $filterConfig
	 * @param array<string, string> $parametrization Parametrization::toArray()
	 * @param array<int, float> $theta
	 * @param array<int, array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>}> $ticks
	 */
	public static function evaluateTheta(array $modelConfig, ?array $filterConfig, array $parametrization, array $theta, array $ticks): float
	{
		$applied = Parametrization::fromArray($parametrization)->apply($modelConfig, $theta);
		try {
			return self::evaluate($applied, $filterConfig, $ticks);
		} catch (\OpenCCK\Kalman\Domain\Exception\KalmanException) {
			// numerically infeasible point (e.g. covariance collapsed): worst possible objective
			return -\INF;
		}
	}
}
