<?php declare(strict_types=1);

namespace OpenCCK\Kalman\App\Calibration;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * K Nelder–Mead simplexes advanced in lockstep. Every round collects the
 * pending point batch of each live simplex, evaluates them all in ONE
 * evaluator call (4K points per iteration instead of 4) and hands the values
 * back — the way to keep 8+ workers busy with a 2–3 parameter likelihood.
 * The extra starts also make the search robust to local optima and flat
 * regions of the likelihood.
 *
 * Start k = 0 is x0 itself; the others are x0 + spread · step ⊙ u_k with u_k
 * a deterministic pseudo-random vector in [−1, 1]^d (seeded LCG), so a
 * calibration is reproducible. The result is the best final point across
 * starts; 'evaluations' counts all starts, 'iterations' is the maximum.
 */
final class MultiStartNelderMead implements Optimizer
{
	public function __construct(
		private readonly NelderMead $inner = new NelderMead(),
		private readonly int $starts = 4,
		private readonly float $spread = 1.0,
		private readonly int $seed = 12345,
	) {
		if ($starts < 1) {
			throw new InvalidArgument('starts must be >= 1');
		}
		if ($spread < 0.0) {
			throw new InvalidArgument('spread must be >= 0');
		}
	}

	public function starts(): int
	{
		return $this->starts;
	}

	/**
	 * @param callable(array<int, array<int, float>>): array<int, float> $evaluate
	 * @param array<int, float> $x0
	 * @param array<int, float> $step
	 * @return array{x: array<int, float>, f: float, iterations: int, evaluations: int, converged: bool, starts: int, bestStart: int, results: array<int, array{x: array<int, float>, f: float, iterations: int, evaluations: int, converged: bool}>}
	 */
	public function minimize(callable $evaluate, array $x0, array $step): array
	{
		$d = \count($x0);
		if ($d < 1 || \count($step) !== $d) {
			throw new InvalidArgument('x0 and step must have the same positive length');
		}

		/** @var array<int, \Generator<int, array<int, array<int, float>>, array<int, float>, array{x: array<int, float>, f: float, iterations: int, evaluations: int, converged: bool}>> $steppers */
		$steppers = [];
		foreach ($this->startingPoints($x0, $step) as $k => $start) {
			$steppers[$k] = $this->inner->stepper($start, $step);
		}

		/** @var array<int, array{x: array<int, float>, f: float, iterations: int, evaluations: int, converged: bool}> $results */
		$results = [];
		while ($steppers !== []) {
			$points = [];
			$owners = [];
			foreach ($steppers as $k => $stepper) {
				/** @var array<int, array<int, float>> $batch */
				$batch = $stepper->current();
				foreach ($batch as $p) {
					$points[] = $p;
					$owners[] = $k;
				}
			}
			/** @var array<int, float> $values */
			$values = \array_values($evaluate($points));
			if (\count($values) !== \count($points)) {
				throw new InvalidArgument('evaluator must return one value per point');
			}
			$perStart = [];
			foreach ($owners as $i => $k) {
				$perStart[$k][] = $values[$i];
			}
			foreach ($steppers as $k => $stepper) {
				$stepper->send($perStart[$k] ?? []);
				if (!$stepper->valid()) {
					$results[$k] = $stepper->getReturn();
					unset($steppers[$k]);
				}
			}
		}

		$bestKey = \array_key_first($results);
		if ($bestKey === null) {
			throw new InvalidArgument('no start produced a result');
		}
		$evaluations = 0;
		$iterations = 0;
		foreach ($results as $k => $r) {
			$evaluations += $r['evaluations'];
			$iterations = \max($iterations, $r['iterations']);
			if ($r['f'] < $results[$bestKey]['f']) {
				$bestKey = $k;
			}
		}
		$best = $results[$bestKey];
		return [
			'x' => $best['x'],
			'f' => $best['f'],
			'iterations' => $iterations,
			'evaluations' => $evaluations,
			'converged' => $best['converged'],
			'starts' => $this->starts,
			'bestStart' => $bestKey,
			'results' => $results,
		];
	}

	/**
	 * @param array<int, float> $x0
	 * @param array<int, float> $step
	 * @return array<int, array<int, float>>
	 */
	public function startingPoints(array $x0, array $step): array
	{
		$points = [\array_values($x0)];
		$state = $this->seed & 0x7fffffff;
		for ($k = 1; $k < $this->starts; $k++) {
			$p = [];
			foreach (\array_values($x0) as $j => $v) {
				$state = ($state * 1103515245 + 12345) & 0x7fffffff;
				$u = ($state % 2000000) / 1000000.0 - 1.0;   // [-1, 1)
				$p[] = $v + $this->spread * $step[$j] * $u;
			}
			$points[] = $p;
		}
		return $points;
	}
}
