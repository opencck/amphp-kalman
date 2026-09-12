<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Calibration;

use OpenCCK\Kalman\App\Calibration\MultiStartNelderMead;
use OpenCCK\Kalman\App\Calibration\NelderMead;
use PHPUnit\Framework\TestCase;

final class MultiStartNelderMeadTest extends TestCase
{
	/**
	 * @param array<int, array<int, float>> $points
	 * @return array<int, float>
	 */
	private static function rosenbrock(array $points): array
	{
		$out = [];
		foreach ($points as [$x, $y]) {
			$out[] = (1.0 - $x) ** 2 + 100.0 * ($y - $x * $x) ** 2;
		}
		return $out;
	}

	public function testStepperReproducesMinimize(): void
	{
		$nm = new NelderMead(tolerance: 1e-10, maxIterations: 5000);
		$direct = $nm->minimize(self::rosenbrock(...), [-1.2, 1.0], [0.5, 0.5]);
		$stepper = $nm->stepper([-1.2, 1.0], [0.5, 0.5]);
		while ($stepper->valid()) {
			$points = $stepper->current();
			self::assertIsArray($points);
			$stepper->send(self::rosenbrock($points));
		}
		self::assertSame($direct, $stepper->getReturn());
	}

	public function testBatchesMergeAcrossStartsAndBestIsReturned(): void
	{
		$sizes = [];
		$evaluate = static function (array $points) use (&$sizes): array {
			$sizes[] = \count($points);
			return self::rosenbrock($points);
		};
		$starts = 4;
		$ms = new MultiStartNelderMead(new NelderMead(tolerance: 1e-10, maxIterations: 5000), starts: $starts, spread: 2.0, seed: 7);
		$result = $ms->minimize($evaluate, [-1.2, 1.0], [0.5, 0.5]);

		self::assertTrue($result['converged']);
		self::assertEqualsWithDelta(1.0, $result['x'][0], 1e-4);
		self::assertEqualsWithDelta(1.0, $result['x'][1], 1e-4);
		self::assertSame($starts, $result['starts']);
		self::assertCount($starts, $result['results']);
		// first round: 4 simplexes × 3 points; later rounds: up to 4 × 4 candidates while all starts are alive
		self::assertGreaterThanOrEqual(2, \count($sizes));
		self::assertSame(3 * $starts, $sizes[0] ?? null);
		self::assertSame(4 * $starts, $sizes[1] ?? null);
		self::assertSame(\array_sum(\array_column($result['results'], 'evaluations')), $result['evaluations']);
		// every start converged to the same optimum
		foreach ($result['results'] as $r) {
			self::assertEqualsWithDelta(1.0, $r['x'][0], 1e-3);
		}
	}

	public function testStartingPointsAreDeterministicAndBracketTheOrigin(): void
	{
		$ms = new MultiStartNelderMead(starts: 5, spread: 1.0, seed: 42);
		$a = $ms->startingPoints([0.0, 10.0], [1.0, 2.0]);
		$b = $ms->startingPoints([0.0, 10.0], [1.0, 2.0]);
		self::assertSame($a, $b);
		self::assertSame([0.0, 10.0], $a[0]);
		foreach (\array_slice($a, 1) as $p) {
			self::assertLessThanOrEqual(1.0, \abs($p[0]));
			self::assertLessThanOrEqual(2.0, \abs($p[1] - 10.0));
		}
	}

	public function testSingleStartEqualsPlainNelderMead(): void
	{
		$nm = new NelderMead(tolerance: 1e-8, maxIterations: 2000);
		$plain = $nm->minimize(self::rosenbrock(...), [-1.2, 1.0], [0.5, 0.5]);
		$single = (new MultiStartNelderMead($nm, starts: 1))->minimize(self::rosenbrock(...), [-1.2, 1.0], [0.5, 0.5]);
		self::assertSame($plain['x'], $single['x']);
		self::assertSame($plain['evaluations'], $single['evaluations']);
	}
}
