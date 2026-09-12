<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\MultiModel;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Domain\MultiModel\InteractingMultipleModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class InteractingMultipleModelTest extends TestCase
{
	/** Phase 7 DoD: regime probabilities follow the true regime with a delay ≤ 5 steps. */
	public function testRegimeProbabilitiesTrackSwitchingVolatility(): void
	{
		$calm = 0.05;
		$volatile = 2.0;
		$r = 1e-4;
		$obs = new StaticObservation([[0 => 1.0]], [$r]);
		$make = static fn (float $sigmaA): KalmanFilter => new KalmanFilter(new ConstantVelocity($sigmaA), $obs, [0.0, 0.0], [0.01, 0.0, 0.0, 0.01], FilterConfig::default()->withMatrixCache(false));
		$imm = new InteractingMultipleModel([$make($calm), $make($volatile)], [0.95, 0.05, 0.05, 0.95]);

		$rng = new Rng(1301);
		$p = 0.0;
		$v = 0.0;
		$dt = 0.1;
		$ts = 0;
		$regime = 0;
		$switches = [];
		$delays = [];
		$pendingSwitch = null;
		$correct = 0;
		$total = 0;
		for ($k = 0; $k < 3000; $k++) {
			if ($k > 0 && $k % 500 === 0) {
				$regime = 1 - $regime;
				$pendingSwitch = $k;
				$switches[] = $k;
			}
			$sigmaA = $regime === 0 ? $calm : $volatile;
			$Q = (new ConstantVelocity($sigmaA))->processNoise($dt);
			$w = $rng->multivariateNormal($Q, 2);
			$p += $dt * $v + $w[0];
			$v += $w[1];
			$ts += 100_000_000;
			$imm->step(Measurement::at($ts, [0 => $p + \sqrt($r) * $rng->normal()]));
			$likely = $imm->mostLikelyRegime();
			if ($pendingSwitch !== null && $likely === $regime) {
				$delays[] = $k - $pendingSwitch;
				$pendingSwitch = null;
			}
			if ($k % 500 >= 50) {   // away from switches the regime must be identified
				$total++;
				if ($likely === $regime) {
					$correct++;
				}
			}
		}
		self::assertCount(5, $switches);
		self::assertCount(5, $delays, 'every switch must be detected');
		foreach ($delays as $delay) {
			self::assertLessThanOrEqual(5, $delay, 'detection delays: ' . \implode(',', $delays));
		}
		self::assertGreaterThan(0.9, $correct / $total, 'regime accuracy away from switches');
		$mu = $imm->regimeProbabilities();
		self::assertEqualsWithDelta(1.0, \array_sum($mu), 1e-12);
		self::assertSame(2, $imm->snapshot()->size);
		self::assertEqualsWithDelta($p, $imm->mean()[0], 0.5);
	}

	public function testMixtureCovarianceExceedsEveryComponentWhenMeansDisagree(): void
	{
		$obs = new StaticObservation([[0 => 1.0]], [1.0]);
		$a = new KalmanFilter(new ConstantVelocity(0.1), $obs, [0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$b = new KalmanFilter(new ConstantVelocity(2.0), $obs, [0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$imm = new InteractingMultipleModel([$a, $b], [0.9, 0.1, 0.1, 0.9], [0.5, 0.5]);
		$imm->step(Measurement::at(1_000_000_000, [0 => 0.0]));
		$imm->step(Measurement::at(2_000_000_000, [0 => 5.0]));   // a jump: the volatile model wins
		$mu = $imm->regimeProbabilities();
		self::assertGreaterThan($mu[0], $mu[1]);
		$P = $imm->covariance();
		self::assertGreaterThan(0.0, $P[0]);
		self::assertSame($P[1], $P[2]);
	}

	public function testRejectsBadTransitionMatrix(): void
	{
		$obs = new StaticObservation([[0 => 1.0]], [1.0]);
		$a = new KalmanFilter(new ConstantVelocity(0.1), $obs, [0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$b = new KalmanFilter(new ConstantVelocity(2.0), $obs, [0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$this->expectException(InvalidArgument::class);
		new InteractingMultipleModel([$a, $b], [0.9, 0.2, 0.1, 0.9]);
	}
}
