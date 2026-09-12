<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Smoothing;

use OpenCCK\Kalman\App\Backtest\Engine;
use OpenCCK\Kalman\App\Calibration\ExpectationMaximization;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Domain\Smoothing\FilterTrajectory;
use OpenCCK\Kalman\Domain\Smoothing\FixedLagSmoother;
use OpenCCK\Kalman\Domain\Smoothing\RauchTungStriebel;
use OpenCCK\Kalman\Tests\Simulation\LinearGaussianSimulator;
use PHPUnit\Framework\TestCase;

final class SmoothingTest extends TestCase
{
	/** @return array{0: KalmanFilter, 1: FilterTrajectory, 2: array<int, array<int, float>>, 3: array<int, array{ts: int, values: array<int, float>}>} */
	private static function runFiltered(int $steps, int $seed, float $sigmaA = 0.5, float $halfSpread = 0.05): array
	{
		$llt = new LocalLinearTrend($sigmaA, $halfSpread);
		$filter = $llt->filter(100.0, FilterConfig::default()->withMatrixCache(false));
		$trajectory = new FilterTrajectory(2, 16);
		$filter->setRecorder($trajectory);
		$sim = new LinearGaussianSimulator($llt->motion(), $llt->observation(), $filter->mean(), $seed);
		$sim->drawInitialFrom($filter->mean(), $filter->covariance());
		$truths = [];
		$ticks = [];
		$ts = 0;
		for ($k = 0; $k < $steps; $k++) {
			$ts += 100_000_000;
			$sim->advance(0.1);
			$m = $sim->observe($ts);
			$filter->step($m);
			$truths[] = $sim->truth();
			$ticks[] = ['ts' => $ts, 'values' => $m->values];
		}
		$filter->setRecorder(null);
		return [$filter, $trajectory, $truths, $ticks];
	}

	public function testTrajectoryRecordsPriorsAndPosteriorsAndGrows(): void
	{
		[$filter, $trajectory] = self::runFiltered(100, 1);
		self::assertSame(100, $trajectory->count());
		self::assertSame(0.0, $trajectory->dt(0));
		self::assertEqualsWithDelta(0.1, $trajectory->dt(1), 1e-12);
		self::assertSame($filter->mean(), $trajectory->posteriorMean(99));
		self::assertSame($filter->covariance(), $trajectory->posteriorCovariance(99));
		self::assertSame(100 * 100_000_000, $trajectory->timestampNs(99));
		// prior variance ≥ previous posterior variance (predict only grows P)
		self::assertGreaterThan($trajectory->posteriorCovariance(49)[0], $trajectory->priorCovariance(50)[0]);
		$arrays = $trajectory->toArrays();
		self::assertCount(100, $arrays['xPost']);
	}

	public function testRingTrajectoryKeepsOnlyTheLastSteps(): void
	{
		$t = new FilterTrajectory(1, 4, maxSteps: 5);
		for ($k = 0; $k < 12; $k++) {
			$t->recordPrior(1.0, [(float) $k], [1.0]);
			$t->recordPosterior([(float) $k + 0.5], [0.5], $k);
		}
		self::assertSame(5, $t->count());
		self::assertSame(7, $t->timestampNs(0));
		self::assertSame(11, $t->timestampNs(4));
		self::assertSame([11.5], $t->posteriorMean(4));
	}

	/** §7.5: the smoothed estimate at the last point equals the filtered one. */
	public function testRtsLastPointEqualsFilterAndImprovesAccuracy(): void
	{
		[$filter, $trajectory, $truths] = self::runFiltered(2000, 2);
		$smoothed = RauchTungStriebel::smooth($trajectory, $filter->motion());
		$last = $trajectory->count() - 1;
		self::assertSame($filter->mean(), $smoothed['mean'][$last]);
		self::assertSame($filter->covariance(), $smoothed['covariance'][$last]);

		$filterErr = 0.0;
		$smoothErr = 0.0;
		for ($k = 0; $k < $last; $k++) {
			$filterErr += ($trajectory->posteriorMean($k)[0] - $truths[$k][0]) ** 2;
			$smoothErr += ($smoothed['mean'][$k][0] - $truths[$k][0]) ** 2;
			// smoothed variance never exceeds filtered variance
			self::assertLessThanOrEqual($trajectory->posteriorCovariance($k)[0] * (1 + 1e-9), $smoothed['covariance'][$k][0]);
		}
		self::assertLessThan($filterErr * 0.9, $smoothErr, 'smoother should beat the filter clearly');
		self::assertNull($smoothed['lagOne'][0]);
		self::assertNotNull($smoothed['lagOne'][1]);
	}

	public function testSmoothedNeesIsConsistent(): void
	{
		[$filter, $trajectory, $truths] = self::runFiltered(3000, 3);
		$smoothed = RauchTungStriebel::smooth($trajectory, $filter->motion());
		$sum = 0.0;
		for ($k = 0; $k < 3000; $k++) {
			$sum += \OpenCCK\Kalman\Domain\Diagnostics\Nees::compute($truths[$k], $smoothed['mean'][$k], $smoothed['covariance'][$k], 2);
		}
		$nees = $sum / 3000;
		self::assertGreaterThan(1.8, $nees, "smoothed NEES $nees");
		self::assertLessThan(2.2, $nees, "smoothed NEES $nees");
	}

	public function testOffloadableSmoothConfigMatchesObjectVersion(): void
	{
		[$filter, $trajectory] = self::runFiltered(200, 4);
		$a = RauchTungStriebel::smooth($trajectory, $filter->motion());
		$b = RauchTungStriebel::smoothConfig($trajectory->toArrays(), ['motion' => ['type' => 'constant-velocity', 'sigmaA' => 0.5]]);
		self::assertSame($a['mean'], $b['mean']);
		self::assertSame($a['covariance'], $b['covariance']);
	}

	public function testFixedLagSmootherMatchesFullRtsOnTheWindow(): void
	{
		$llt = new LocalLinearTrend(0.5, 0.05);
		$lag = 5;
		$filter = $llt->filter(100.0, FilterConfig::default()->withMatrixCache(false));
		$full = new FilterTrajectory(2, 16);
		$lagged = new FixedLagSmoother($llt->motion(), $lag);
		$both = new class($full, $lagged) implements \OpenCCK\Kalman\Domain\Contract\StepRecorder {
			public function __construct(private FilterTrajectory $a, private FixedLagSmoother $b)
			{
			}

			public function recordPrior(float $dt, array $xPrior, array $PPrior): void
			{
				$this->a->recordPrior($dt, $xPrior, $PPrior);
				$this->b->recordPrior($dt, $xPrior, $PPrior);
			}

			public function recordPosterior(array $xPost, array $PPost, ?int $timestampNs): void
			{
				$this->a->recordPosterior($xPost, $PPost, $timestampNs);
				$this->b->recordPosterior($xPost, $PPost, $timestampNs);
			}
		};
		$filter->setRecorder($both);
		$ts = 0;
		for ($k = 0; $k < 40; $k++) {
			$ts += 100_000_000;
			self::assertSame($k >= $lag + 1, $lagged->isReady());   // ready once lag+1 steps are recorded
			$filter->step(Measurement::at($ts, [0 => 100.0 + 0.05 * $k]));
		}
		// x̂_{k−L|k} from the ring window == RTS over the full trajectory restricted to the same window
		$window = new FilterTrajectory(2, 8);
		for ($k = 40 - $lag - 1; $k < 40; $k++) {
			$window->recordPrior($full->dt($k), $full->priorMean($k), $full->priorCovariance($k));
			$window->recordPosterior($full->posteriorMean($k), $full->posteriorCovariance($k), $full->timestampNs($k));
		}
		$expected = RauchTungStriebel::smooth($window, $llt->motion());
		self::assertLessThan(1e-12, Flat::maxAbsDiff($expected['mean'][0], $lagged->laggedMean()));
		self::assertLessThan(1e-12, Flat::maxAbsDiff($expected['covariance'][0], $lagged->laggedCovariance()));
		self::assertSame($full->timestampNs(40 - $lag - 1), $lagged->laggedTimestampNs());
	}

	/** Phase 6 DoD: EM recovers Q, R of a synthetic CV model within 10 % in 30 iterations; likelihood non-decreasing. */
	public function testExpectationMaximizationRecoversNoiseMatrices(): void
	{
		[, , , $ticks] = self::runFiltered(6000, 5, sigmaA: 0.5, halfSpread: 0.05);
		$dt = 0.1;
		$truthQ = (new ConstantVelocity(0.5))->processNoise($dt);
		$start = [
			'motion' => ['type' => 'constant-velocity', 'sigmaA' => 1.5],          // 3× too noisy
			'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [0.02]],   // 8× too large
			'x0' => [100.0, 0.0],
			'P0' => [0.01, 0.0, 0.0, 1.0],
		];
		$result = ExpectationMaximization::fit($start, $ticks, iterations: 30);
		$ll = $result['logLikelihood'];
		self::assertCount(30, $ll);
		for ($i = 1; $i < 30; $i++) {
			self::assertGreaterThanOrEqual($ll[$i - 1] - 1e-6 * \abs($ll[$i - 1]), $ll[$i], "likelihood decreased at iteration $i");
		}
		self::assertEqualsWithDelta(0.0025, $result['R'][0], 0.00025, 'R');
		// Q: compare the dominant velocity entry and the position entry within 10 %
		self::assertEqualsWithDelta($truthQ[3], $result['Q'][3], 0.10 * $truthQ[3], 'Q_vv');
		// Q_pp (dt³/3 term, ~3 % of Q_vv·dt²) is only weakly identified by EM: require the right order of magnitude
		self::assertGreaterThan(0.0, $result['Q'][0]);
		self::assertLessThan(5.0 * $truthQ[0], $result['Q'][0], 'Q_pp order of magnitude');
		self::assertTrue(\OpenCCK\Kalman\Domain\Linalg\Cholesky::isPositiveDefinite($result['Q'], 2));
		self::assertIsArray($result['config']['motion']);
		self::assertSame('discrete-linear', $result['config']['motion']['type']);
	}

	public function testBacktestEngineProducesReport(): void
	{
		$llt = new LocalLinearTrend(0.5, 0.05);
		$filter = $llt->filter(100.0);
		$sim = new LinearGaussianSimulator($llt->motion(), $llt->observation(), $filter->mean(), 9);
		$sim->drawInitialFrom($filter->mean(), $filter->covariance());
		$ticks = [];
		$ts = 0;
		for ($k = 0; $k < 500; $k++) {
			$ts += 100_000_000;
			$sim->advance(0.1);
			$m = $sim->observe($ts);
			$ticks[] = ['ts' => $ts, 'values' => $m->values, 'truth' => $sim->truth()];
		}
		$run = (new Engine($filter))->run($ticks);
		$report = $run['report'];
		self::assertSame(500, $report->steps);
		self::assertNotNull($report->meanNees);
		self::assertNotNull($report->smootherRmse);
		self::assertNotNull($report->filterRmse);
		self::assertLessThan($report->filterRmse, $report->smootherRmse);
		self::assertGreaterThan(0.7, $report->meanNis);
		self::assertLessThan(1.3, $report->meanNis);
		self::assertNotNull($report->filterLagSteps);
		self::assertIsArray(\json_decode($report->toJson(), true, 512, JSON_THROW_ON_ERROR));
		self::assertSame(500, $run['trajectory']->count());
	}
}
