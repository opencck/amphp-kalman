<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Invariant;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Reference\SequentialVsNaiveTest;
use OpenCCK\Kalman\Tests\Support\RandomLinearModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * §7.3: after every step P is bitwise symmetric, finite and positive definite.
 * Runs with zend.assertions=1 so the filter's own assert() fires too.
 */
final class CovarianceInvariantTest extends TestCase
{
	/** @return iterable<string, array{FilterForm}> */
	public function forms(): iterable
	{
		foreach (SequentialVsNaiveTest::availableForms() as $form) {
			yield $form->value => [$form];
		}
	}

	/** @dataProvider forms */
	public function testLongRunOnDenseRandomModel(FilterForm $form): void
	{
		$rng = new Rng(77);
		$n = 6;
		$m = 3;
		$model = new RandomLinearModel($rng, $n, $m);
		$obs = new StaticObservation($model->rows(), $model->variances());
		$f = new KalmanFilter($model, $obs, $rng->vector($n), $rng->spdMatrix($n), FilterConfig::default()->withForm($form));

		for ($k = 0; $k < 3000; $k++) {
			$f->predict($rng->uniformBetween(0.01, 0.5));
			$values = [];
			for ($c = 0; $c < $m; $c++) {
				if ($rng->uniform() < 0.6) {
					$values[$c] = $rng->normal() * 5.0;
				}
			}
			$f->correct(Measurement::at($k, $values));
			if ($k % 250 === 0) {
				$P = $f->covariance();
				self::assertTrue(Flat::isSymmetric($P, $n), "symmetry lost at step $k");
				self::assertTrue(Flat::allFinite($P), "non-finite at step $k");
				self::assertTrue(Cholesky::isPositiveDefinite($P, $n), "not PD at step $k");
			}
		}
		self::assertTrue($f->invariantsHold());
	}

	/** @dataProvider forms */
	public function testSparsePathKeepsExactSymmetry(FilterForm $form): void
	{
		$f = new KalmanFilter(
			new ConstantVelocity(0.3),
			new StaticObservation([[0 => 1.0]], [0.01]),
			[0.0, 0.0],
			[1.0, 0.0, 0.0, 1.0],
			FilterConfig::default()->withForm($form),
		);
		$rng = new Rng(5);
		for ($k = 1; $k <= 20000; $k++) {
			$f->step(Measurement::at($k * 1_000_000 + $rng->int(0, 500_000), [0 => \sin($k / 100.0)]));
		}
		$P = $f->covariance();
		self::assertSame($P[1], $P[2]);
		self::assertTrue(Cholesky::isPositiveDefinite($P, 2));
		self::assertGreaterThan(0.0, $f->variance(0));
		self::assertGreaterThan(0.0, $f->variance(1));
	}
}
