<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Linalg;

use OpenCCK\Kalman\Domain\Exception\NotPositiveDefinite;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Tests\Reference\NaiveKalmanFilter as Naive;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class CholeskyTest extends TestCase
{
	/** @return iterable<string, array{int}> */
	public function sizes(): iterable
	{
		foreach ([1, 2, 3, 5, 8, 16] as $n) {
			yield "n=$n" => [$n];
		}
	}

	/** @dataProvider sizes */
	public function testDecomposeReconstructs(int $n): void
	{
		$rng = new Rng(10 + $n);
		$A = $rng->spdMatrix($n);
		$L = Cholesky::decompose($A, $n);
		// strictly upper part is zero
		for ($i = 0; $i < $n; $i++) {
			for ($j = $i + 1; $j < $n; $j++) {
				self::assertSame(0.0, $L[$i * $n + $j]);
			}
		}
		$LLt = Flat::multiplyTransposed($L, $L, $n, $n, $n);
		self::assertLessThan(1e-11, Flat::maxAbsDiff($A, $LLt));
	}

	/** @dataProvider sizes */
	public function testSolveMatchesGaussJordan(int $n): void
	{
		$rng = new Rng(20 + $n);
		$A = $rng->spdMatrix($n);
		$b = $rng->vector($n);
		$L = Cholesky::decompose($A, $n);
		$x = Cholesky::solve($L, $b, $n);
		$expected = Naive::matVec(Naive::inverse(Naive::fromFlat($A, $n, $n)), $b);
		self::assertLessThan(1e-9, Flat::maxAbsDiff($expected, $x));
		// A x == b
		self::assertLessThan(1e-9, Flat::maxAbsDiff($b, Flat::matVec($A, $x, $n, $n)));
	}

	/** @dataProvider sizes */
	public function testInverseAndLogDeterminant(int $n): void
	{
		$rng = new Rng(30 + $n);
		$A = $rng->spdMatrix($n);
		$L = Cholesky::decompose($A, $n);
		$inv = Cholesky::inverse($L, $n);
		self::assertTrue(Flat::isSymmetric($inv, $n));
		self::assertLessThan(1e-9, Flat::maxAbsDiff(Flat::identity($n), Flat::multiply($A, $inv, $n, $n, $n)));
		$det = Naive::determinant(Naive::fromFlat($A, $n, $n));
		self::assertEqualsWithDelta(\log($det), Cholesky::logDeterminant($L, $n), 1e-9);
	}

	public function testQuadraticForm(): void
	{
		$A = [4.0, 1.0, 1.0, 3.0];
		$v = [1.0, 2.0];
		$L = Cholesky::decompose($A, 2);
		$inv = Cholesky::inverse($L, 2);
		$expected = Flat::dot($v, Flat::matVec($inv, $v, 2, 2));
		self::assertEqualsWithDelta($expected, Cholesky::quadraticForm($L, $v, 2), 1e-12);
	}

	public function testSolveMatrix(): void
	{
		$rng = new Rng(7);
		$n = 4;
		$m = 3;
		$A = $rng->spdMatrix($n);
		$B = $rng->matrix($n, $m);
		$X = Cholesky::solveMatrix(Cholesky::decompose($A, $n), $B, $n, $m);
		self::assertLessThan(1e-9, Flat::maxAbsDiff($B, Flat::multiply($A, $X, $n, $n, $m)));
	}

	public function testNotPositiveDefiniteThrows(): void
	{
		$this->expectException(NotPositiveDefinite::class);
		Cholesky::decompose([1.0, 2.0, 2.0, 1.0], 2);
	}

	public function testIsPositiveDefinite(): void
	{
		self::assertTrue(Cholesky::isPositiveDefinite([2.0, 0.5, 0.5, 1.0], 2));
		self::assertFalse(Cholesky::isPositiveDefinite([1.0, 2.0, 2.0, 1.0], 2));
		self::assertFalse(Cholesky::isPositiveDefinite([0.0], 1));
	}

	public function testSemidefiniteHandlesSingularDirection(): void
	{
		// rank-1 matrix v vᵀ with v = [1, 2]
		$A = [1.0, 2.0, 2.0, 4.0];
		$L = Cholesky::decomposeSemidefinite($A, 2);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($A, Flat::multiplyTransposed($L, $L, 2, 2, 2)));
		// zero matrix
		self::assertSame([0.0, 0.0, 0.0, 0.0], Cholesky::decomposeSemidefinite([0.0, 0.0, 0.0, 0.0], 2));
	}

	public function testSemidefiniteRejectsNegative(): void
	{
		$this->expectException(NotPositiveDefinite::class);
		Cholesky::decomposeSemidefinite([1.0, 0.0, 0.0, -1.0], 2);
	}
}
