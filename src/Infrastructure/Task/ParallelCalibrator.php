<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Task;

use Amp\Parallel\Worker\WorkerPool;
use OpenCCK\Kalman\App\Calibration\NelderMead;
use OpenCCK\Kalman\App\Calibration\Optimizer;
use OpenCCK\Kalman\App\Calibration\Parametrization;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use function Amp\Future\await;
use function Amp\Parallel\Worker\workerPool;

/**
 * §5.10 maximum-likelihood calibration with the simplex evaluated on a worker
 * pool: every batch of candidate points becomes one LikelihoodTask per point,
 * submitted concurrently and awaited together. Speed-up is close to linear in
 * the number of workers because points are independent.
 */
final class ParallelCalibrator
{
	private readonly WorkerPool $pool;

	public function __construct(
		private readonly Parametrization $parametrization,
		?WorkerPool $pool = null,
		private readonly Optimizer $optimizer = new NelderMead(),
	) {
		$this->pool = $pool ?? workerPool();
	}

	/**
	 * @param array<string, mixed> $modelConfig
	 * @param array<string, mixed>|null $filterConfig
	 * @param string|array<int, array{ts: int, values: array<int, float>}> $history file path (workers read it) or in-memory ticks
	 * @param array<int, float>|null $step
	 * @return array{config: array<string, mixed>, theta: array<int, float>, logLikelihood: float, iterations: int, evaluations: int, converged: bool}
	 */
	public function calibrate(array $modelConfig, ?array $filterConfig, string|array $history, ?array $step = null): array
	{
		if ($history === []) {
			throw new InvalidArgument('History is empty');
		}
		$theta0 = $this->parametrization->toTheta($modelConfig);
		$step ??= \array_fill(0, \count($theta0), 0.5);
		$param = $this->parametrization->toArray();
		$pool = $this->pool;
		$path = \is_string($history) ? $history : null;
		$ticks = \is_array($history) ? $history : null;

		/**
		 * @param array<int, array<int, float>> $points
		 * @return array<int, float>
		 */
		$evaluate = static function (array $points) use ($pool, $modelConfig, $filterConfig, $param, $path, $ticks): array {
			/** @var array<int, array<int, float>> $points */
			$futures = [];
			foreach ($points as $i => $theta) {
				$futures[$i] = $pool->submit(new LikelihoodTask($modelConfig, $filterConfig, $param, $theta, $path, $ticks))->getFuture();
			}
			/** @var array<int, float> $values */
			$values = await($futures);
			$out = [];
			foreach ($points as $i => $_) {
				$out[] = -$values[$i];
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
}
