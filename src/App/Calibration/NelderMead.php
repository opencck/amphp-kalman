<?php declare(strict_types=1);

namespace OpenCCK\Kalman\App\Calibration;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Derivative-free minimisation (Nelder & Mead 1965) written against a BATCH
 * evaluator so that the candidate points of one iteration can be computed
 * concurrently (worker pool, queue consumers):
 *
 *   - the initial simplex (d+1 points) is evaluated in one batch;
 *   - every iteration evaluates the reflection, expansion, outside and inside
 *     contraction points in one batch of 4 and picks by the standard rules;
 *   - a shrink step evaluates d points in one batch.
 *
 * The evaluator receives array<int, array<int, float>> points and returns
 * array<int, float> values in the same order. Minimises; pass −ℓ for MLE.
 *
 * stepper() exposes the same algorithm as a coroutine (a Generator that
 * yields point batches and is sent their values) so that several simplexes
 * can be advanced in lockstep and their batches merged — see
 * MultiStartNelderMead. minimize() drives a single stepper.
 */
final class NelderMead implements Optimizer
{
	public function __construct(
		private readonly float $tolerance = 1e-6,
		private readonly int $maxIterations = 500,
		private readonly float $reflection = 1.0,
		private readonly float $expansion = 2.0,
		private readonly float $contraction = 0.5,
		private readonly float $shrink = 0.5,
	) {
		if ($tolerance <= 0.0 || $maxIterations < 1) {
			throw new InvalidArgument('tolerance must be > 0 and maxIterations >= 1');
		}
	}

	public function maxIterations(): int
	{
		return $this->maxIterations;
	}

	public function minimize(callable $evaluate, array $x0, array $step): array
	{
		$stepper = $this->stepper($x0, $step);
		while ($stepper->valid()) {
			/** @var array<int, array<int, float>> $points */
			$points = $stepper->current();
			/** @var array<int, float> $values */
			$values = $evaluate($points);
			if (\count($values) !== \count($points)) {
				throw new InvalidArgument('evaluator must return one value per point');
			}
			$stepper->send(\array_values($values));
		}
		/** @var array{x: array<int, float>, f: float, iterations: int, evaluations: int, converged: bool} $result */
		$result = $stepper->getReturn();
		return $result;
	}

	/**
	 * The algorithm as a coroutine: yields a batch of points, expects their
	 * values (same order) via send(), returns the result when done.
	 *
	 * @param array<int, float> $x0
	 * @param array<int, float> $step
	 * @return \Generator<int, array<int, array<int, float>>, array<int, float>, array{x: array<int, float>, f: float, iterations: int, evaluations: int, converged: bool}>
	 */
	public function stepper(array $x0, array $step): \Generator
	{
		$d = \count($x0);
		if ($d < 1 || \count($step) !== $d) {
			throw new InvalidArgument('x0 and step must have the same positive length');
		}
		$evaluations = 0;

		// initial simplex
		$simplex = [\array_values($x0)];
		for ($i = 0; $i < $d; $i++) {
			$p = \array_values($x0);
			$p[$i] += $step[$i];
			$simplex[] = $p;
		}
		$evaluations += \count($simplex);
		$values = \array_values(yield $simplex);

		$converged = false;
		for ($iter = 1; $iter <= $this->maxIterations; $iter++) {
			// order
			$order = \array_keys($values);
			\usort($order, static fn (int $a, int $b): int => $values[$a] <=> $values[$b]);
			$simplex = \array_map(static fn (int $i): array => $simplex[$i], $order);
			$values = \array_map(static fn (int $i): float => $values[$i], $order);

			$best = $values[0];
			$worst = $values[$d];
			$spread = \abs($worst - $best);
			$size = 0.0;
			for ($i = 1; $i <= $d; $i++) {
				for ($j = 0; $j < $d; $j++) {
					$size = \max($size, \abs($simplex[$i][$j] - $simplex[0][$j]));
				}
			}
			if ($spread <= $this->tolerance * \max(1.0, \abs($best)) && $size <= $this->tolerance) {
				$converged = true;
				break;
			}

			// centroid of all but the worst
			$centroid = \array_fill(0, $d, 0.0);
			for ($i = 0; $i < $d; $i++) {
				for ($j = 0; $j < $d; $j++) {
					$centroid[$j] += $simplex[$i][$j] / $d;
				}
			}
			$worstPoint = $simplex[$d];
			$reflected = self::combine($centroid, $worstPoint, $this->reflection);
			$expanded = self::combine($centroid, $worstPoint, $this->expansion);
			$outside = self::combine($centroid, $worstPoint, $this->contraction);
			$inside = self::combine($centroid, $worstPoint, -$this->contraction);

			// speculative batch: all four candidates at once
			$evaluations += 4;
			$candidates = \array_values(yield [$reflected, $expanded, $outside, $inside]);
			if (\count($candidates) !== 4) {
				throw new InvalidArgument('evaluator must return one value per point');
			}
			[$fr, $fe, $fo, $fi] = $candidates;

			if ($fr < $values[0]) {
				// expansion
				if ($fe < $fr) {
					$simplex[$d] = $expanded;
					$values[$d] = $fe;
				} else {
					$simplex[$d] = $reflected;
					$values[$d] = $fr;
				}
				continue;
			}
			if ($fr < $values[$d - 1]) {
				$simplex[$d] = $reflected;
				$values[$d] = $fr;
				continue;
			}
			if ($fr < $values[$d]) {
				// outside contraction
				if ($fo <= $fr) {
					$simplex[$d] = $outside;
					$values[$d] = $fo;
					continue;
				}
			} else {
				// inside contraction
				if ($fi < $values[$d]) {
					$simplex[$d] = $inside;
					$values[$d] = $fi;
					continue;
				}
			}
			// shrink towards the best point
			$points = [];
			for ($i = 1; $i <= $d; $i++) {
				$points[] = self::combine($simplex[0], $simplex[$i], -$this->shrink);
			}
			$evaluations += $d;
			$shrunk = \array_values(yield $points);
			for ($i = 1; $i <= $d; $i++) {
				$simplex[$i] = $points[$i - 1];
				$values[$i] = $shrunk[$i - 1];
			}
		}

		$bestIndex = 0;
		foreach ($values as $i => $v) {
			if ($v < $values[$bestIndex]) {
				$bestIndex = $i;
			}
		}
		return [
			'x' => $simplex[$bestIndex],
			'f' => $values[$bestIndex],
			'iterations' => \min($iter, $this->maxIterations),
			'evaluations' => $evaluations,
			'converged' => $converged,
		];
	}

	/**
	 * centroid + coef·(centroid − point)  (coef > 0 reflects away from the point)
	 *
	 * @param array<int, float> $centroid
	 * @param array<int, float> $point
	 * @return array<int, float>
	 */
	private static function combine(array $centroid, array $point, float $coef): array
	{
		$out = [];
		foreach ($centroid as $j => $c) {
			$out[] = $c + $coef * ($c - $point[$j]);
		}
		return $out;
	}
}
