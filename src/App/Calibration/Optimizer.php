<?php declare(strict_types=1);

namespace OpenCCK\Kalman\App\Calibration;

/**
 * Derivative-free minimiser driven by a BATCH evaluator: the optimiser hands
 * out several candidate points at once and receives their values in the same
 * order, so the evaluations can run concurrently (worker pool, queue
 * consumers). Implementations: NelderMead (one simplex, 4 points per
 * iteration) and MultiStartNelderMead (K simplexes in lockstep, 4K points).
 */
interface Optimizer
{
	/**
	 * Implementations may add diagnostic keys (MultiStartNelderMead reports
	 * `starts`, `bestStart` and the per-start `results`); callers must not rely
	 * on them being present.
	 *
	 * @param callable(array<int, array<int, float>>): array<int, float> $evaluate batch objective (minimised)
	 * @param array<int, float> $x0 starting point
	 * @param array<int, float> $step initial simplex step per coordinate
	 * @return array{x: array<int, float>, f: float, iterations: int, evaluations: int, converged: bool, starts?: int, bestStart?: int, results?: array<int, array{x: array<int, float>, f: float, iterations: int, evaluations: int, converged: bool}>}
	 */
	public function minimize(callable $evaluate, array $x0, array $step): array;
}
