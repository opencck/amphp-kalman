<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Factory;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Diagnostics\JitSanity;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * Builds a KalmanFilter from a fully serialisable description:
 *
 *   [
 *     'motion'      => ['type' => 'constant-velocity', 'sigmaA' => 0.5],
 *     'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [0.01]],
 *     'x0'          => [100.0, 0.0],
 *     'P0'          => [1.0, 0.0, 0.0, 1.0],      // or 'P0diag' => [1.0, 1.0]
 *   ]
 *
 * This is the entry point for worker processes and queue consumers
 * (ADR-005): nothing here requires objects from the parent process.
 */
final class FilterFactory
{
	private function __construct()
	{
	}

	/**
	 * @param array<string, mixed> $modelConfig
	 * @param array<string, mixed>|null $filterConfig FilterConfig::toArray() shape
	 * @param array<string, mixed>|null $snapshot StateSnapshot::toArray() shape; overrides x0/P0 and restores the clock
	 */
	public static function fromConfig(array $modelConfig, ?array $filterConfig = null, ?array $snapshot = null): KalmanFilter
	{
		JitSanity::verify();
		$motion = self::motion($modelConfig);
		$observation = self::observation($modelConfig);
		$config = $filterConfig === null ? FilterConfig::default() : FilterConfig::fromArray($filterConfig);

		if ($snapshot !== null) {
			/** @var array{n: int, x: list<float>, P: list<float>, ts?: int|null, ll?: float, steps?: int} $snapshot */
			return KalmanFilter::fromSnapshot($motion, $observation, StateSnapshot::fromArray($snapshot), $config);
		}

		$n = $motion->stateSize();
		/** @var list<float|int>|null $x0 */
		$x0 = $modelConfig['x0'] ?? null;
		if ($x0 === null) {
			$x0 = \array_fill(0, $n, 0.0);
		}
		if (isset($modelConfig['P0'])) {
			/** @var list<float|int> $P0 */
			$P0 = $modelConfig['P0'];
		} elseif (isset($modelConfig['P0diag'])) {
			/** @var list<float|int> $diag */
			$diag = $modelConfig['P0diag'];
			$P0 = Flat::diagonal(\array_map('floatval', $diag));
		} else {
			$P0 = Flat::identity($n);
		}

		return new KalmanFilter(
			$motion,
			$observation,
			\array_map('floatval', $x0),
			\array_map('floatval', $P0),
			$config,
		);
	}

	/** @param array<string, mixed> $modelConfig */
	public static function motion(array $modelConfig): MotionModel
	{
		if (!isset($modelConfig['motion']) || !\is_array($modelConfig['motion'])) {
			throw new InvalidArgument('Model config requires a "motion" array');
		}
		/** @var array<string, mixed> $spec */
		$spec = $modelConfig['motion'];
		$model = ModelRegistry::build($spec);
		if (!$model instanceof MotionModel) {
			throw new InvalidArgument(\sprintf('"%s" is not a motion model', $model::type()));
		}
		return $model;
	}

	/** @param array<string, mixed> $modelConfig */
	public static function observation(array $modelConfig): ObservationModel
	{
		if (!isset($modelConfig['observation']) || !\is_array($modelConfig['observation'])) {
			throw new InvalidArgument('Model config requires an "observation" array');
		}
		/** @var array<string, mixed> $spec */
		$spec = $modelConfig['observation'];
		$model = ModelRegistry::build($spec);
		if (!$model instanceof ObservationModel) {
			throw new InvalidArgument(\sprintf('"%s" is not an observation model', $model::type()));
		}
		return $model;
	}

	/**
	 * Inverse of fromConfig for models that are serialisable.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(KalmanFilter $filter): array
	{
		$motion = $filter->motion();
		$observation = $filter->observation();
		if (!$motion instanceof \OpenCCK\Kalman\Domain\Contract\SerializableModel
			|| !$observation instanceof \OpenCCK\Kalman\Domain\Contract\SerializableModel) {
			throw new InvalidArgument('Filter models are not serialisable');
		}
		return [
			'motion' => $motion->toArray(),
			'observation' => $observation->toArray(),
			'x0' => $filter->mean(),
			'P0' => $filter->covariance(),
		];
	}
}
