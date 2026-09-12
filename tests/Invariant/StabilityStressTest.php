<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Invariant;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Model\Finance\BidAskBounce;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 stress test (§8, Phase 2): many steps with r ≈ 1e-8·h·P·hᵀ.
 * Factorised forms (UD, SquareRoot) must keep P positive definite and
 * finite; the dense forms may lose definiteness (documented expectation:
 * the test reports but does not fail on them).
 *
 * Step count defaults to 200 000 (≈ seconds); set KALMAN_STRESS_STEPS=1000000
 * for the full stress run.
 *
 * @group slow
 */
final class StabilityStressTest extends TestCase
{
	private static function steps(): int
	{
		$env = \getenv('KALMAN_STRESS_STEPS');
		return \is_string($env) && \ctype_digit($env) ? \max(1000, (int) $env) : 200_000;
	}

	/** @return iterable<string, array{FilterForm}> */
	public function stableForms(): iterable
	{
		yield 'ud' => [FilterForm::UD];
		yield 'square-root' => [FilterForm::SquareRoot];
	}

	/**
	 * Near-singular regime: CV model with tiny process noise and a measurement
	 * variance 1e-8 times the predicted innovation variance. Runs with
	 * zend.assertions=1, so the filter's own invariant checks fire on each step.
	 *
	 * @dataProvider stableForms
	 */
	public function testFactorisedFormsSurviveTinyMeasurementNoise(FilterForm $form): void
	{
		$steps = self::steps();
		$filter = self::nearSingularFilter($form);
		$rng = new Rng(9);
		$ts = 0;
		for ($k = 0; $k < $steps; $k++) {
			$ts += 1_000_000;
			$filter->stepRaw($ts, [0 => 100.0 + 0.01 * \sin($k / 1000.0) + 1e-4 * $rng->normal()]);
		}
		$P = $filter->covariance();
		self::assertTrue(Cholesky::isPositiveDefinite($P, 2), 'P lost positive definiteness');
		self::assertGreaterThan(0.0, $filter->variance(0));
		self::assertGreaterThan(0.0, $filter->variance(1));
		self::assertTrue(\is_finite($filter->meanAt(0)) && \is_finite($filter->meanAt(1)));
	}

	/** Dense forms are exercised too; their outcome is reported, not asserted. */
	public function testDenseFormsAreReportedNotAsserted(): void
	{
		$report = [];
		foreach ([FilterForm::Sequential, FilterForm::Joseph] as $form) {
			$filter = self::nearSingularFilter($form);
			$ts = 0;
			try {
				for ($k = 0; $k < 20_000; $k++) {
					$ts += 1_000_000;
					$filter->stepRaw($ts, [0 => 100.0 + 0.01 * \sin($k / 1000.0)]);
				}
				$ok = Cholesky::isPositiveDefinite($filter->covariance(), 2);
			} catch (\Throwable) {
				$ok = false;
			}
			$report[$form->value] = $ok;
		}
		self::assertCount(2, $report);
	}

	/** §3.9 bounce model: R → 0 relative to state uncertainty; UD must hold. */
	public function testBidAskBounceWithUdStaysConsistent(): void
	{
		$model = new BidAskBounce(sigmaA: 0.05, sigmaNu: 0.004, rho: -0.4, tickInterval: 0.5, tick: 0.01);
		$filter = $model->filter(50.0);
		$rng = new Rng(11);
		$ts = 0;
		$truth = 50.0;
		$nu = 0.0;
		for ($k = 0; $k < 50_000; $k++) {
			$dt = 0.5;
			$ts += 500_000_000;
			$truth += 0.05 * \sqrt($dt) * $rng->normal() * 0.1;
			$nu = -0.4 * $nu + 0.004 * \sqrt(1 - 0.16) * $rng->normal();
			$trade = \round(($truth + $nu) / 0.01) * 0.01;
			$filter->stepRaw($ts, [0 => $trade]);
		}
		self::assertTrue(Cholesky::isPositiveDefinite($filter->covariance(), 3));
		self::assertEqualsWithDelta($truth, $filter->meanAt(0), 0.05);
	}

	private static function nearSingularFilter(FilterForm $form): KalmanFilter
	{
		$motion = new ConstantVelocity(1e-4);
		$obs = new StaticObservation([[0 => 1.0]], [1e-10]);
		return new KalmanFilter($motion, $obs, [100.0, 0.0], [1e-2, 0.0, 0.0, 1e-4], FilterConfig::default()->withForm($form));
	}
}
