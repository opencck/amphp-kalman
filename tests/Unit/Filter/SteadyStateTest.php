<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Filter;

use OpenCCK\Kalman\Domain\Diagnostics\ObservabilityCheck;
use OpenCCK\Kalman\Domain\Diagnostics\SteadyStateSolver;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\UnsupportedOperation;
use OpenCCK\Kalman\Domain\Filter\AlphaBetaFilter;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Filter\SteadyStateFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Generic\DiscreteWhiteNoiseAcceleration;
use OpenCCK\Kalman\Domain\Model\Generic\RandomWalk;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class SteadyStateTest extends TestCase
{
	public function testDareMatchesConvergedKalmanFilter(): void
	{
		$motion = new ConstantVelocity(0.3);
		$obs = new StaticObservation([[0 => 1.0]], [0.04]);
		$dt = 0.5;
		$dare = SteadyStateSolver::forModels($motion, $obs, $dt);

		$kf = new KalmanFilter($motion, $obs, [0.0, 0.0], [10.0, 0.0, 0.0, 10.0], FilterConfig::default()->withUnrolledKernels(false));
		for ($k = 0; $k < 500; $k++) {
			$kf->predict($dt);
			$prior = $kf->covariance();
			$kf->correct(Measurement::at($k, [0 => 0.0]));
		}
		self::assertLessThan(1e-9, Flat::maxAbsDiff($dare['P'], $prior));
		self::assertLessThan(1e-9, Flat::maxAbsDiff($dare['posterior'], $kf->covariance()));
		self::assertGreaterThan(10, $dare['iterations']);
	}

	/** Kalata closed form == DARE gains of the discrete white-noise acceleration model. */
	public function testKalataAlphaBetaEqualsSteadyStateGainOfDwnaModel(): void
	{
		foreach ([[0.5, 0.2, 0.1], [2.0, 0.05, 1.0], [0.01, 1.0, 0.5]] as [$sigmaA, $sigmaR, $dt]) {
			$ab = new AlphaBetaFilter($sigmaA, $sigmaR, $dt);
			$dare = SteadyStateSolver::forModels(new DiscreteWhiteNoiseAcceleration($sigmaA), new StaticObservation([[0 => 1.0]], [$sigmaR * $sigmaR]), $dt);
			[$kp, $kv] = $ab->kalmanGain();
			self::assertEqualsWithDelta($dare['K'][0], $kp, 1e-9, 'alpha');
			self::assertEqualsWithDelta($dare['K'][1], $kv, 1e-9, 'beta/dt');
			self::assertGreaterThan(0.0, $ab->alpha());
			self::assertLessThan(1.0, $ab->alpha());
			self::assertEqualsWithDelta($sigmaA * $dt * $dt / $sigmaR, $ab->trackingIndex(), 1e-15);
		}
	}

	/** forConstantVelocity() == DARE gains of the continuous model (ConstantVelocity). */
	public function testAlphaBetaForConstantVelocityEqualsDare(): void
	{
		$ab = AlphaBetaFilter::forConstantVelocity(0.5, 0.2, 0.1);
		$dare = SteadyStateSolver::forModels(new ConstantVelocity(0.5), new StaticObservation([[0 => 1.0]], [0.04]), 0.1);
		[$kp, $kv] = $ab->kalmanGain();
		self::assertEqualsWithDelta($dare['K'][0], $kp, 1e-12);
		self::assertEqualsWithDelta($dare['K'][1], $kv, 1e-12);
		// Kalata (DWNA) and CWNA gains differ — documented in the class
		$kalata = new AlphaBetaFilter(0.5, 0.2, 0.1);
		self::assertNotEqualsWithDelta($kalata->alpha(), $ab->alpha(), 1e-3);
	}

	public function testAlphaBetaTracksLikeConvergedKalmanFilter(): void
	{
		$sigmaA = 0.5;
		$sigmaR = 0.2;
		$dt = 0.1;
		$motion = new ConstantVelocity($sigmaA);
		$obs = new StaticObservation([[0 => 1.0]], [$sigmaR * $sigmaR]);
		$kf = new KalmanFilter($motion, $obs, [0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$ab = AlphaBetaFilter::forConstantVelocity($sigmaA, $sigmaR, $dt);
		$rng = new Rng(21);
		// let the KF converge first
		$ts = 0;
		for ($k = 0; $k < 2000; $k++) {
			$ts += 100_000_000;
			$z = 0.01 * $k + $rng->normal() * $sigmaR;
			$kf->step(Measurement::at($ts, [0 => $z]));
			$ab->step($z);
		}
		// sync alpha-beta state to KF and compare the next steps
		$ab->reset($kf->meanAt(0), $kf->meanAt(1));
		for ($k = 0; $k < 200; $k++) {
			$ts += 100_000_000;
			$z = 20.0 + 0.01 * $k + $rng->normal() * $sigmaR;
			$kf->step(Measurement::at($ts, [0 => $z]));
			$ab->step($z);
			self::assertEqualsWithDelta($kf->meanAt(0), $ab->position(), 1e-8);
			self::assertEqualsWithDelta($kf->meanAt(1), $ab->velocity(), 1e-7);
		}
	}

	public function testSteadyStateFilterMatchesKalmanFilterAfterConvergence(): void
	{
		$motion = new ConstantVelocity(0.3);
		$obs = new StaticObservation([[0 => 1.0], [1 => 1.0]], [0.04, 0.09]);
		$dt = 0.25;
		$kf = new KalmanFilter($motion, $obs, [0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$rng = new Rng(22);
		$ts = 0;
		for ($k = 0; $k < 1000; $k++) {
			$ts += 250_000_000;
			$kf->step(Measurement::at($ts, [0 => $rng->normal(), 1 => $rng->normal()]));
		}
		$ss = SteadyStateFilter::fromModels($motion, $obs, $dt, $kf->mean());
		for ($k = 0; $k < 100; $k++) {
			$ts += 250_000_000;
			$z = [0 => 3.0 + $rng->normal() * 0.2, 1 => 0.1 + $rng->normal() * 0.3];
			$kf->step(Measurement::at($ts, $z));
			$ss->step($z);
			self::assertLessThan(1e-8, Flat::maxAbsDiff($kf->mean(), $ss->mean()));
		}
		self::assertCount(2, $ss->lastInnovations());
		self::assertCount(4, $ss->innovationCovariance());
	}

	public function testSteadyStateFilterRejectsMissingChannels(): void
	{
		$ss = SteadyStateFilter::fromModels(new ConstantVelocity(0.3), new StaticObservation([[0 => 1.0], [1 => 1.0]], [0.04, 0.09]), 0.25, [0.0, 0.0]);
		$this->expectException(UnsupportedOperation::class);
		$ss->step([0 => 1.0]);
	}

	public function testObservability(): void
	{
		// CV observed through position: observable
		self::assertTrue(ObservabilityCheck::isObservable([1.0, 0.1, 0.0, 1.0], [1.0, 0.0], 2, 1));
		// CV observed through velocity only: position unobservable
		self::assertSame(1, ObservabilityCheck::rank([1.0, 0.1, 0.0, 1.0], [0.0, 1.0], 2, 1));
		self::assertSame(2, ObservabilityCheck::forModels(new ConstantVelocity(1.0), new StaticObservation([[0 => 1.0]], [1.0]), 0.1));
		self::assertSame(1, ObservabilityCheck::forModels(new RandomWalk(1.0), new StaticObservation([[0 => 1.0]], [1.0]), 0.1));
	}
}
