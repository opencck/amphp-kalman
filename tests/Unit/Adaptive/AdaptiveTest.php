<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Adaptive;

use OpenCCK\Kalman\Domain\Adaptive\AdaptiveNoise;
use OpenCCK\Kalman\Domain\Adaptive\InnovationAdaptive;
use OpenCCK\Kalman\Domain\Adaptive\SageHusa;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Generic\HeteroscedasticMotionModel;
use OpenCCK\Kalman\Domain\Model\Observation\MutableObservation;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Simulation\LinearGaussianSimulator;
use PHPUnit\Framework\TestCase;

final class AdaptiveTest extends TestCase
{
	private const TRUE_R = 0.01;   // truth: σ_r = 0.1
	private const WRONG_R = 0.04;  // filter assumes σ_r = 0.2

	/**
	 * Runs a CV filter with the given R on data generated with the true R and
	 * returns [iae, sageHusa] estimators after $steps.
	 *
	 * @return array{0: InnovationAdaptive, 1: SageHusa}
	 */
	private static function simulateWithR(float $assumedR, int $steps, int $seed): array
	{
		$motion = new ConstantVelocity(0.3);
		$truthObs = new StaticObservation([[0 => 1.0]], [self::TRUE_R]);
		$filterObs = new StaticObservation([[0 => 1.0]], [$assumedR]);
		$filter = new KalmanFilter($motion, $filterObs, [0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$sim = new LinearGaussianSimulator($motion, $truthObs, [0.0, 0.0], $seed);
		$sim->drawInitialFrom([0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$iae = new InnovationAdaptive(1, window: 500);
		$sh = new SageHusa([$assumedR], forgetting: 0.998);
		$ts = 0;
		for ($k = 0; $k < $steps; $k++) {
			$ts += 100_000_000;
			$sim->advance(0.1);
			$result = $filter->step($sim->observe($ts));
			$iae->record($result, [0 => $assumedR]);
			$sh->record($result, [0 => $assumedR]);
		}
		return [$iae, $sh];
	}

	/**
	 * A single IAE pass uses the filter's own (wrong) P⁻ in R̂ = Ĉ − hP⁻hᵀ, so with
	 * a 4× wrong R the first estimate is biased. Iterating (re-run with R̂) is the
	 * standard use and converges to the truth in a few passes.
	 */
	public function testInnovationAdaptiveConvergesByIteration(): void
	{
		$r = self::WRONG_R;
		$history = [];
		for ($pass = 0; $pass < 4; $pass++) {
			[$iae] = self::simulateWithR($r, 3000, 71 + $pass);
			self::assertSame(500, $iae->samples(0));
			if ($pass === 0) {
				self::assertLessThan(1.0, $iae->suggestedQScale());   // over-estimated R → realised < predicted
			}
			$r = $iae->suggestedR(0);
			$history[] = $r;
		}
		self::assertLessThan(self::WRONG_R, $history[0]);              // moves in the right direction
		self::assertEqualsWithDelta(self::TRUE_R, $r, 0.003, 'iterated R̂: ' . \implode(', ', \array_map(static fn (float $v): string => \sprintf('%.4f', $v), $history)));
	}

	public function testSageHusaConvergesByIteration(): void
	{
		$r = self::WRONG_R;
		for ($pass = 0; $pass < 4; $pass++) {
			[, $sh] = self::simulateWithR($r, 3000, 81 + $pass);
			self::assertSame(3000, $sh->updates(0));
			$r = $sh->estimatedR(0);
			self::assertGreaterThan(0.0, $sh->estimatedRs()[0]);
		}
		self::assertEqualsWithDelta(self::TRUE_R, $r, 0.004, "iterated R̂ = $r");
	}

	public function testAdaptiveNoiseClosesTheLoop(): void
	{
		$inner = new ConstantVelocity(0.3);
		$motion = new HeteroscedasticMotionModel($inner);
		$obs = new MutableObservation([[0 => 1.0]], [self::WRONG_R]);
		$filter = new KalmanFilter($motion, $obs, [0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$adaptive = new AdaptiveNoise($obs, $motion, forgetting: 0.99, damping: 0.2, warmup: 50);

		$sim = new LinearGaussianSimulator($inner, new StaticObservation([[0 => 1.0]], [self::TRUE_R]), [0.0, 0.0], 73);
		$sim->drawInitialFrom([0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$ts = 0;
		for ($k = 0; $k < 4000; $k++) {
			$ts += 100_000_000;
			$sim->advance(0.1);
			$adaptive->record($filter->step($sim->observe($ts)));
		}
		$r = $obs->channelVariance(0);
		self::assertEqualsWithDelta(self::TRUE_R, $r, 0.004, "adapted R = $r");
		// with R corrected, the Q multiplier should settle near 1 (the process model was right)
		self::assertEqualsWithDelta(1.0, $adaptive->multiplier(), 0.35);
	}
}
