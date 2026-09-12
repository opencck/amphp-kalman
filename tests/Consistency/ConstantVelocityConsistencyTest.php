<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Consistency;

use OpenCCK\Kalman\Domain\Diagnostics\ConsistencyMonitor;
use OpenCCK\Kalman\Domain\Diagnostics\Nees;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Domain\Model\Generic\RandomWalk;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Reference\SequentialVsNaiveTest;
use OpenCCK\Kalman\Tests\Simulation\LinearGaussianSimulator;
use PHPUnit\Framework\TestCase;

/**
 * §7.4 / Phase 1 DoD: LLT on its own simulation, 50 000 steps →
 * mean NEES ∈ [0.9n, 1.1n] = [1.8, 2.2], mean NIS ∈ [0.9, 1.1],
 * innovations white, χ²(α=0.01) gate rejects ≈ 1 %.
 */
final class ConstantVelocityConsistencyTest extends TestCase
{
	private const STEPS = 50_000;

	/** @return iterable<string, array{FilterForm}> */
	public function forms(): iterable
	{
		foreach (SequentialVsNaiveTest::availableForms() as $form) {
			yield $form->value => [$form];
		}
	}

	/** @dataProvider forms */
	public function testNeesAndNisAreConsistent(FilterForm $form): void
	{
		$llt = new LocalLinearTrend(sigmaA: 0.5, halfSpread: 0.05, tick: 0.01);
		$filter = $llt->filter(100.0, FilterConfig::default()->withForm($form), velocityPriorStd: 0.5);
		$x0 = $filter->mean();
		$P0 = $filter->covariance();

		$sim = new LinearGaussianSimulator($llt->motion(), $llt->observation(), $x0, seed: 2024);
		$sim->drawInitialFrom($x0, $P0);
		$monitor = new ConsistencyMonitor(1, window: 100, lags: 10);

		$neesSum = 0.0;
		$ts = 0;
		for ($k = 0; $k < self::STEPS; $k++) {
			$dtNs = $sim->rng()->int(50_000_000, 150_000_000);   // 50–150 ms irregular ticks
			$ts += $dtNs;
			$sim->advance($dtNs / 1e9);
			$result = $filter->step($sim->observe($ts));
			$monitor->record($result);
			$neesSum += Nees::compute($sim->truth(), $filter->mean(), $filter->covariance(), 2);
		}

		$meanNees = $neesSum / self::STEPS;
		self::assertGreaterThan(1.8, $meanNees, "mean NEES $meanNees too low (filter too pessimistic)");
		self::assertLessThan(2.2, $meanNees, "mean NEES $meanNees too high (filter over-confident)");

		$meanNis = $monitor->meanNis();
		self::assertGreaterThan(0.9, $meanNis);
		self::assertLessThan(1.1, $meanNis);

		$band = $monitor->channel(0)->whitenessBand();
		foreach ($monitor->channel(0)->autocorrelations() as $lag => $rho) {
			self::assertLessThan($band * 1.5, \abs($rho), 'innovation autocorrelation at lag ' . ($lag + 1) . " = $rho");
		}
		self::assertFalse($monitor->modelBreakSuspected());
	}

	/**
	 * Without feedback, the χ²₁(0.99) threshold cuts off exactly ≈ 1 % of NIS
	 * values of a consistent filter — this validates the gate threshold.
	 */
	public function testUngatedNisExceedsChiSquareThresholdAboutOnePercent(): void
	{
		$llt = new LocalLinearTrend(sigmaA: 0.5, halfSpread: 0.05, tick: 0.01);
		$filter = $llt->filter(100.0, null, velocityPriorStd: 0.5);
		$sim = new LinearGaussianSimulator($llt->motion(), $llt->observation(), $filter->mean(), seed: 7);
		$sim->drawInitialFrom($filter->mean(), $filter->covariance());
		$threshold = GatingPolicy::chiSquare(0.01)->threshold();

		$ts = 0;
		$exceed = 0;
		for ($k = 0; $k < self::STEPS; $k++) {
			$ts += 100_000_000;
			$sim->advance(0.1);
			if ($filter->step($sim->observe($ts))->outcomes[0]->nis() > $threshold) {
				$exceed++;
			}
		}
		$rate = $exceed / self::STEPS;
		self::assertGreaterThan(0.007, $rate, "exceedance rate $rate");
		self::assertLessThan(0.013, $rate, "exceedance rate $rate");
	}

	/**
	 * With the gate active, a rejected innovation that was partly a genuine
	 * state deviation leaves x̂ lagging while P stays put, so the next
	 * innovations are inflated and rejections cascade. On the LLT model
	 * (h·P·hᵀ comparable to r) the measured rate is ≈ 2 % for α = 0.01.
	 * When measurement noise dominates (r ≫ h·P·hᵀ) the rate is ≈ 1 %.
	 */
	public function testChiSquareGateRejectionRates(): void
	{
		// LLT: state uncertainty comparable to measurement noise → feedback inflates the rate
		$llt = new LocalLinearTrend(sigmaA: 0.5, halfSpread: 0.05, tick: 0.01);
		$filter = $llt->filter(100.0, FilterConfig::default()->withGating(GatingPolicy::chiSquare(0.01)), velocityPriorStd: 0.5);
		$sim = new LinearGaussianSimulator($llt->motion(), $llt->observation(), $filter->mean(), seed: 7);
		$sim->drawInitialFrom($filter->mean(), $filter->covariance());
		$monitor = new ConsistencyMonitor(1);
		$ts = 0;
		for ($k = 0; $k < self::STEPS; $k++) {
			$ts += 100_000_000;
			$sim->advance(0.1);
			$monitor->record($filter->step($sim->observe($ts)));
		}
		$rate = $monitor->rejectionRate();
		self::assertGreaterThan(0.007, $rate, "LLT rejection rate $rate");
		self::assertLessThan(0.03, $rate, "LLT rejection rate $rate");

		// Random walk with tiny Q: r ≫ h·P·hᵀ → rate ≈ α
		$motion = new RandomWalk(0.001);
		$obs = new StaticObservation([[0 => 1.0]], [1.0]);
		$rw = new KalmanFilter($motion, $obs, [0.0], [1.0], FilterConfig::default()->withGating(GatingPolicy::chiSquare(0.01)));
		$sim2 = new LinearGaussianSimulator($motion, $obs, [0.0], seed: 5);
		$sim2->drawInitialFrom([0.0], [1.0]);
		$monitor2 = new ConsistencyMonitor(1);
		$ts = 0;
		for ($k = 0; $k < self::STEPS; $k++) {
			$ts += 100_000_000;
			$sim2->advance(0.1);
			$monitor2->record($rw->step($sim2->observe($ts)));
		}
		$rate2 = $monitor2->rejectionRate();
		self::assertGreaterThan(0.007, $rate2, "RW rejection rate $rate2");
		self::assertLessThan(0.013, $rate2, "RW rejection rate $rate2");
	}

	public function testOverconfidentFilterIsDetected(): void
	{
		// Filter assumes σ_a ten times smaller than the truth → NIS ≫ 1.
		$truth = new LocalLinearTrend(sigmaA: 1.0, halfSpread: 0.05);
		$wrong = new LocalLinearTrend(sigmaA: 0.1, halfSpread: 0.05);
		$filter = $wrong->filter(100.0);
		$sim = new LinearGaussianSimulator($truth->motion(), $truth->observation(), $filter->mean(), seed: 3);
		$monitor = new ConsistencyMonitor(1, window: 200);
		$ts = 0;
		for ($k = 0; $k < 2000; $k++) {
			$ts += 100_000_000;
			$sim->advance(0.1);
			$monitor->record($filter->step($sim->observe($ts)));
		}
		self::assertTrue($monitor->isOverconfident());
		self::assertTrue($monitor->isInconsistent());
		self::assertGreaterThan(1.3, $monitor->rollingNis());
	}
}
