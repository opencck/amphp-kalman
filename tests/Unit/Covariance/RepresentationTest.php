<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Covariance;

use OpenCCK\Kalman\Domain\Covariance\DenseSequential;
use OpenCCK\Kalman\Domain\Covariance\Joseph;
use OpenCCK\Kalman\Domain\Covariance\SquareRoot;
use OpenCCK\Kalman\Domain\Covariance\UD;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Factory\CovarianceFactory;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * Every representation implements the same algebra: checked directly
 * against dense formulas on random SPD matrices.
 */
final class RepresentationTest extends TestCase
{
	/** @return iterable<string, array{FilterForm}> */
	public function forms(): iterable
	{
		foreach (FilterForm::cases() as $form) {
			yield $form->value => [$form];
		}
	}

	public function testFactoryMapsForms(): void
	{
		self::assertInstanceOf(DenseSequential::class, CovarianceFactory::create(FilterForm::Sequential, 3));
		self::assertInstanceOf(Joseph::class, CovarianceFactory::create(FilterForm::Joseph, 3));
		self::assertInstanceOf(UD::class, CovarianceFactory::create(FilterForm::UD, 3));
		self::assertInstanceOf(SquareRoot::class, CovarianceFactory::create(FilterForm::SquareRoot, 3));
	}

	/** @dataProvider forms */
	public function testDenseRoundTrip(FilterForm $form): void
	{
		$rng = new Rng(41);
		foreach ([1, 2, 4, 7] as $n) {
			$P = $rng->spdMatrix($n);
			$rep = CovarianceFactory::create($form, $n);
			$rep->fromDense($P);
			self::assertLessThan(1e-12, Flat::maxAbsDiff($P, $rep->toDense()));
			self::assertTrue(Flat::isSymmetric($rep->toDense(), $n));
			for ($i = 0; $i < $n; $i++) {
				self::assertEqualsWithDelta($P[$i * $n + $i], $rep->variance($i), 1e-12);
			}
		}
	}

	/** @dataProvider forms */
	public function testPredictDenseMatchesFormula(FilterForm $form): void
	{
		$rng = new Rng(42);
		$n = 5;
		$P = $rng->spdMatrix($n);
		$F = $rng->matrix($n, $n);
		$Q = $rng->spdMatrix($n, 0.3, 0.01);
		$rep = CovarianceFactory::create($form, $n);
		$rep->fromDense($P);
		$rep->predictDense($F, $Q);
		$expected = Flat::add(Flat::sandwich($F, $P, $n), $Q);
		self::assertLessThan(1e-10, Flat::maxAbsDiff($expected, $rep->toDense()));
	}

	/** @dataProvider forms */
	public function testPredictWithSingularQ(FilterForm $form): void
	{
		// Q has a zero row/col (noise-free state) — must not break factorised forms
		$n = 3;
		$P = [2.0, 0.1, 0.0, 0.1, 1.0, 0.2, 0.0, 0.2, 3.0];
		$F = [1.0, 0.5, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.9];
		$Q = [0.1, 0.05, 0.0, 0.05, 0.2, 0.0, 0.0, 0.0, 0.0];
		$rep = CovarianceFactory::create($form, $n);
		$rep->fromDense($P);
		$rep->predictDense($F, $Q);
		$expected = Flat::add(Flat::sandwich($F, $P, $n), $Q);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($expected, $rep->toDense()));
	}

	/** @dataProvider forms */
	public function testPredictSparseMatchesDense(FilterForm $form): void
	{
		$model = new ConstantVelocity(0.7);
		$P = [2.0, 0.3, 0.3, 1.0];
		$dt = 0.4;
		$Q = $model->processNoise($dt);
		$F = $model->transition($dt);

		$sparse = CovarianceFactory::create($form, 2);
		$sparse->fromDense($P);
		$x = [1.0, 2.0];
		$sparse->predictSparse($model, $dt, $x, $Q);

		$expected = Flat::add(Flat::sandwich($F, $P, 2), $Q);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($expected, $sparse->toDense()));
		self::assertEqualsWithDelta(1.8, $x[0], 1e-15);
		self::assertSame(2.0, $x[1]);
	}

	/** @dataProvider forms */
	public function testScalarCorrectionMatchesFormula(FilterForm $form): void
	{
		$rng = new Rng(43);
		$n = 4;
		$P = $rng->spdMatrix($n);
		$h = [0 => 1.0, 2 => -0.5];
		$r = 0.3;
		$rep = CovarianceFactory::create($form, $n);
		$rep->fromDense($P);

		$hd = Flat::denseRow($h, $n);
		$phiExpected = Flat::matVec($P, $hd, $n, $n);
		$sExpected = Flat::dot($hd, $phiExpected) + $r;

		$s = $rep->prepareScalar($h, $r);
		self::assertEqualsWithDelta($sExpected, $s, 1e-12);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($phiExpected, $rep->gain()));
		// nothing mutated yet
		self::assertLessThan(1e-12, Flat::maxAbsDiff($P, $rep->toDense()));

		$rep->commitScalar($r);
		$expected = Flat::subtract($P, Flat::scale(Flat::outer($phiExpected, $phiExpected), 1.0 / $sExpected));
		self::assertLessThan(1e-11, Flat::maxAbsDiff($expected, $rep->toDense()));
		self::assertTrue(Flat::isSymmetric($rep->toDense(), $n));
	}

	/** @dataProvider forms */
	public function testCommitWithInflatedVariance(FilterForm $form): void
	{
		$n = 3;
		$rng = new Rng(44);
		$P = $rng->spdMatrix($n);
		$h = [1 => 2.0];
		$rep = CovarianceFactory::create($form, $n);
		$rep->fromDense($P);
		$rep->prepareScalar($h, 0.1);
		$rep->commitScalar(0.5);   // Huber-inflated r

		$hd = Flat::denseRow($h, $n);
		$phi = Flat::matVec($P, $hd, $n, $n);
		$s = Flat::dot($hd, $phi) + 0.5;
		$expected = Flat::subtract($P, Flat::scale(Flat::outer($phi, $phi), 1.0 / $s));
		self::assertLessThan(1e-11, Flat::maxAbsDiff($expected, $rep->toDense()));
	}

	public function testUdFactorsReconstruct(): void
	{
		$rng = new Rng(45);
		$n = 6;
		$P = $rng->spdMatrix($n);
		$ud = new UD($n);
		$ud->fromDense($P);
		$U = $ud->factorU();
		$D = $ud->factorD();
		// unit upper triangular
		for ($i = 0; $i < $n; $i++) {
			self::assertSame(1.0, $U[$i * $n + $i]);
			for ($j = 0; $j < $i; $j++) {
				self::assertSame(0.0, $U[$i * $n + $j]);
			}
			self::assertGreaterThan(0.0, $D[$i]);
		}
		$UD = Flat::multiply($U, Flat::diagonal($D), $n, $n, $n);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($P, Flat::multiplyTransposed($UD, $U, $n, $n, $n)));
	}

	public function testSquareRootFactorReconstructs(): void
	{
		$rng = new Rng(46);
		$n = 4;
		$P = $rng->spdMatrix($n);
		$sr = new SquareRoot($n);
		$sr->fromDense($P);
		$S = $sr->factor();
		self::assertLessThan(1e-12, Flat::maxAbsDiff($P, Flat::multiplyTransposed($S, $S, $n, $n, $n)));
	}
}
