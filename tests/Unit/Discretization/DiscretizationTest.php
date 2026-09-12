<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Discretization;

use OpenCCK\Kalman\Domain\Discretization\ClosedForm;
use OpenCCK\Kalman\Domain\Discretization\VanLoan;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Linalg\LU;
use OpenCCK\Kalman\Domain\Linalg\MatrixExponential;
use OpenCCK\Kalman\Domain\Model\Generic\BlockDiagonal;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantAcceleration;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Generic\DenseLinear;
use OpenCCK\Kalman\Domain\Model\Generic\OrnsteinUhlenbeck;
use OpenCCK\Kalman\Domain\Model\Generic\Singer;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * §2.4: closed forms == Van Loan at random dt to 1e-10; models' sparse
 * kernels == their dense matrices.
 */
final class DiscretizationTest extends TestCase
{
	public function testMatrixExponentialOfDiagonalAndNilpotent(): void
	{
		$E = MatrixExponential::compute([1.0, 0.0, 0.0, -2.0], 2);
		self::assertEqualsWithDelta(\exp(1.0), $E[0], 1e-13);
		self::assertEqualsWithDelta(\exp(-2.0), $E[3], 1e-13);
		self::assertSame(0.0, $E[1]);
		// exp([0 t; 0 0]) = [1 t; 0 1]
		$E = MatrixExponential::compute([0.0, 3.7, 0.0, 0.0], 2);
		self::assertLessThan(1e-14, Flat::maxAbsDiff([1.0, 3.7, 0.0, 1.0], $E));
		// large norm exercises scaling & squaring: exp(10) diag
		$E = MatrixExponential::compute([10.0, 0.0, 0.0, 0.0], 2);
		self::assertEqualsWithDelta(\exp(10.0), $E[0], 1e-9 * \exp(10.0));
	}

	public function testMatrixExponentialRotation(): void
	{
		$w = 0.8;
		$E = MatrixExponential::compute([0.0, -$w, $w, 0.0], 2);
		self::assertLessThan(1e-13, Flat::maxAbsDiff([\cos($w), -\sin($w), \sin($w), \cos($w)], $E));
	}

	public function testLuSolveAndRank(): void
	{
		$rng = new Rng(61);
		$A = $rng->matrix(5, 5);
		$B = $rng->matrix(5, 2);
		$X = LU::solve($A, $B, 5, 2);
		self::assertLessThan(1e-9, Flat::maxAbsDiff($B, Flat::multiply($A, $X, 5, 5, 2)));
		self::assertSame(5, LU::rank($A, 5, 5));
		self::assertSame(1, LU::rank([1.0, 2.0, 2.0, 4.0, 3.0, 6.0], 3, 2));
		self::assertSame(0, LU::rank([0.0, 0.0], 1, 2));
	}

	/** @return iterable<string, array{float}> */
	public function dts(): iterable
	{
		$rng = new Rng(62);
		for ($i = 0; $i < 6; $i++) {
			$dt = $rng->uniformBetween(0.01, 5.0);
			yield "dt=$dt" => [$dt];
		}
	}

	/** @dataProvider dts */
	public function testConstantVelocityMatchesVanLoan(float $dt): void
	{
		$sigma = 0.7;
		$cf = ClosedForm::constantVelocity($sigma, $dt);
		$vl = VanLoan::discretize([0.0, 1.0, 0.0, 0.0], [0.0, 1.0], [$sigma * $sigma], 2, 1, $dt);
		self::assertLessThan(1e-10, Flat::maxAbsDiff($cf['F'], $vl['F']));
		self::assertLessThan(1e-10, Flat::maxAbsDiff($cf['Q'], $vl['Q']));
		$model = new ConstantVelocity($sigma);
		self::assertSame($cf['F'], $model->transition($dt));
		self::assertSame($cf['Q'], $model->processNoise($dt));
	}

	/** @dataProvider dts */
	public function testConstantAccelerationMatchesVanLoan(float $dt): void
	{
		$sigma = 0.4;
		$cf = ClosedForm::constantAcceleration($sigma, $dt);
		$vl = VanLoan::discretize([0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0], [0.0, 0.0, 1.0], [$sigma * $sigma], 3, 1, $dt);
		self::assertLessThan(1e-9 * \max(1.0, $dt ** 5), Flat::maxAbsDiff($cf['F'], $vl['F']));
		self::assertLessThan(1e-9 * \max(1.0, $dt ** 5), Flat::maxAbsDiff($cf['Q'], $vl['Q']));
	}

	/** @dataProvider dts */
	public function testOrnsteinUhlenbeckMatchesVanLoan(float $dt): void
	{
		$theta = 0.9;
		$sigma = 0.3;
		$cf = ClosedForm::ornsteinUhlenbeck($theta, 2.0, $sigma, $dt);
		$vl = VanLoan::discretize([-$theta], [1.0], [$sigma * $sigma], 1, 1, $dt);
		self::assertEqualsWithDelta($vl['F'][0], $cf['F'][0], 1e-12);
		self::assertEqualsWithDelta($vl['Q'][0], $cf['Q'][0], 1e-12);
		// control via augmented exponential (DenseLinear) == closed form μ(1−e^{−θdt})
		$dense = new DenseLinear([-$theta], [1.0], [$sigma * $sigma], 1, 1, [$theta * 2.0]);
		self::assertEqualsWithDelta($cf['u'][0], $dense->control($dt)[0] ?? \NAN, 1e-12);
		$ou = new OrnsteinUhlenbeck($theta, 2.0, $sigma);
		self::assertEqualsWithDelta($cf['u'][0], $ou->control($dt)[0] ?? \NAN, 1e-15);
	}

	/** @dataProvider dts */
	public function testSingerMatchesVanLoan(float $dt): void
	{
		$sigma = 0.5;
		$tau = 1.7;
		$cf = ClosedForm::singer($sigma, $tau, $dt);
		$vl = VanLoan::discretize([0.0, 1.0, 0.0, -1.0 / $tau], [0.0, 1.0], [$sigma * $sigma], 2, 1, $dt);
		self::assertLessThan(1e-10, Flat::maxAbsDiff($cf['F'], $vl['F']));
		self::assertLessThan(1e-10, Flat::maxAbsDiff($cf['Q'], $vl['Q']));
	}

	public function testSingerDegeneratesToConstantVelocityForLargeTau(): void
	{
		$cv = ClosedForm::constantVelocity(0.5, 0.3);
		$sg = ClosedForm::singer(0.5, 1e6, 0.3);
		self::assertLessThan(1e-6, Flat::maxAbsDiff($cv['F'], $sg['F']));
		self::assertLessThan(1e-6, Flat::maxAbsDiff($cv['Q'], $sg['Q']));
	}

	/** @return iterable<string, array{\OpenCCK\Kalman\Domain\Contract\SparseMotionModel}> */
	public function sparseModels(): iterable
	{
		yield 'cv' => [new ConstantVelocity(0.6)];
		yield 'ca' => [new ConstantAcceleration(0.3)];
		yield 'ou' => [new OrnsteinUhlenbeck(1.2, 0.5, 0.4)];
		yield 'singer' => [new Singer(0.5, 2.0)];
		yield 'block' => [new BlockDiagonal([new ConstantVelocity(0.6), new OrnsteinUhlenbeck(0.7, 1.0, 0.2), new Singer(0.3, 1.5)])];
	}

	/** @dataProvider sparseModels */
	public function testSparseKernelMatchesDenseMatrices(\OpenCCK\Kalman\Domain\Contract\SparseMotionModel $model): void
	{
		$rng = new Rng(63);
		$n = $model->stateSize();
		foreach ([0.05, 0.5, 2.0] as $dt) {
			$P = $rng->spdMatrix($n);
			$x = $rng->vector($n);
			$F = $model->transition($dt);
			$u = $model->control($dt);
			$xExpected = Flat::matVec($F, $x, $n, $n);
			if ($u !== null) {
				$xExpected = Flat::add($xExpected, $u);
			}
			$PExpected = Flat::sandwich($F, $P, $n);

			$xs = $x;
			$Ps = $P;
			$model->advanceInPlace($xs, $Ps, $dt);
			self::assertLessThan(1e-12, Flat::maxAbsDiff($xExpected, $xs));
			self::assertLessThan(1e-12, Flat::maxAbsDiff($PExpected, $Ps));
			self::assertTrue(Flat::isSymmetric($Ps, $n));
		}
	}

	public function testBlockDiagonalAssemblesBlocks(): void
	{
		$b = new BlockDiagonal([new ConstantVelocity(0.6), new OrnsteinUhlenbeck(0.7, 1.0, 0.2)]);
		self::assertSame(3, $b->stateSize());
		self::assertSame(2, $b->offset(1));
		$F = $b->transition(0.5);
		self::assertSame(0.5, $F[1]);
		self::assertSame(0.0, $F[2]);
		self::assertEqualsWithDelta(\exp(-0.35), $F[8], 1e-15);
		$u = $b->control(0.5);
		self::assertNotNull($u);
		self::assertSame(0.0, $u[0]);
		self::assertEqualsWithDelta(1.0 - \exp(-0.35), $u[2], 1e-15);
		self::assertTrue($b->isStationary());
		$rebuilt = BlockDiagonal::fromArray($b->toArray());
		self::assertSame($b->transition(0.3), $rebuilt->transition(0.3));
	}

	public function testDenseLinearCachesAndSerialises(): void
	{
		$m = new DenseLinear([0.0, 1.0, 0.0, -0.5], [0.0, 1.0], [0.25], 2, 1);
		$F1 = $m->transition(0.4);
		$F2 = $m->transition(0.4);
		self::assertSame($F1, $F2);
		$copy = DenseLinear::fromArray($m->toArray());
		self::assertSame($F1, $copy->transition(0.4));
		self::assertSame($m->processNoise(0.4), $copy->processNoise(0.4));
		$singer = ClosedForm::singer(0.5, 2.0, 0.4);
		self::assertLessThan(1e-10, Flat::maxAbsDiff($singer['F'], $F1));
	}
}
