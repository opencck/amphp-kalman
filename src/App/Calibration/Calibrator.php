<?php declare(strict_types=1);

namespace OpenCCK\Kalman\App\Calibration;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * In-process maximum-likelihood calibration: Nelder–Mead over θ with the
 * innovation likelihood as objective, evaluated sequentially. The parallel
 * counterpart is Infrastructure\Task\ParallelCalibrator (same algorithm,
 * batch evaluated on a worker pool).
 */
final class Calibrator
{
	public function __construct(
		private readonly Parametrization $parametrization,
		private readonly Optimizer $optimizer = new NelderMead(),
	) {
	}

	/**
	 * @param array<string, mixed> $modelConfig starting config (θ₀ is read from it)
	 * @param array<string, mixed>|null $filterConfig
	 * @param array<int, array{ts: int, values: array<int, float>}> $ticks
	 * @param array<int, float>|null $step initial simplex step in θ-space (default 0.5 = factor e^0.5 for log params)
	 * @return array{config: array<string, mixed>, theta: array<int, float>, logLikelihood: float, iterations: int, evaluations: int, converged: bool}
	 */
	public function calibrate(array $modelConfig, ?array $filterConfig, array $ticks, ?array $step = null): array
	{
		if ($ticks === []) {
			throw new InvalidArgument('History is empty');
		}
		$theta0 = $this->parametrization->toTheta($modelConfig);
		$step ??= \array_fill(0, \count($theta0), 0.5);
		$param = $this->parametrization->toArray();

		/**
		 * @param array<int, array<int, float>> $points
		 * @return array<int, float>
		 */
		$evaluate = static function (array $points) use ($modelConfig, $filterConfig, $param, $ticks): array {
			/** @var array<int, array<int, float>> $points */
			$out = [];
			foreach ($points as $theta) {
				$out[] = -InnovationLikelihood::evaluateTheta($modelConfig, $filterConfig, $param, $theta, $ticks);
			}
			return $out;
		};
		$result = $this->optimizer->minimize($evaluate, $theta0, $step);
		return [
			'config' => $this->parametrization->apply($modelConfig, $result['x']),
			'theta' => $result['x'],
			'logLikelihood' => -$result['f'],
			'iterations' => $result['iterations'],
			'evaluations' => $result['evaluations'],
			'converged' => $result['converged'],
		];
	}

	public function parametrization(): Parametrization
	{
		return $this->parametrization;
	}
}
