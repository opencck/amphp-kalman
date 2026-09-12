<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Linalg;

use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Linalg\Nested;
use OpenCCK\Kalman\Tests\Reference\NaiveKalmanFilter as Naive;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class FlatTest extends TestCase
{
	public function testIdentity(): void
	{
		self::assertSame([1.0, 0.0, 0.0, 1.0], Flat::identity(2));
	}

	public function testMultiplyMatchesNestedReference(): void
	{
		$rng = new Rng(1);
		foreach ([[2, 3, 4], [1, 1, 1], [5, 2, 3], [4, 4, 4]] as [$n, $k, $m]) {
			$A = $rng->matrix($n, $k);
			$B = $rng->matrix($k, $m);
			$expected = Naive::toFlat(Naive::mul(Naive::fromFlat($A, $n, $k), Naive::fromFlat($B, $k, $m)));
			self::assertLessThan(1e-12, Flat::maxAbsDiff($expected, Flat::multiply($A, $B, $n, $k, $m)));
		}
	}

	public function testMultiplyTransposedAndTransposedMultiply(): void
	{
		$rng = new Rng(2);
		$n = 3;
		$k = 4;
		$m = 2;
		$A = $rng->matrix($n, $k);
		$B = $rng->matrix($m, $k);
		$expected = Flat::multiply($A, Flat::transpose($B, $m, $k), $n, $k, $m);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($expected, Flat::multiplyTransposed($A, $B, $n, $k, $m)));

		$C = $rng->matrix($k, $n);
		$D = $rng->matrix($k, $m);
		$expected2 = Flat::multiply(Flat::transpose($C, $k, $n), $D, $n, $k, $m);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($expected2, Flat::transposedMultiply($C, $D, $k, $n, $m)));
	}

	public function testSandwichIsExactlySymmetricAndCorrect(): void
	{
		$rng = new Rng(3);
		$n = 5;
		$F = $rng->matrix($n, $n);
		$P = $rng->spdMatrix($n);
		$S = Flat::sandwich($F, $P, $n);
		self::assertTrue(Flat::isSymmetric($S, $n));
		$expected = Flat::multiply(Flat::multiply($F, $P, $n, $n, $n), Flat::transpose($F, $n, $n), $n, $n, $n);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($expected, $S));
	}

	public function testTransposeRoundTrip(): void
	{
		$A = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0];
		self::assertSame([1.0, 4.0, 2.0, 5.0, 3.0, 6.0], Flat::transpose($A, 2, 3));
		self::assertSame($A, Flat::transpose(Flat::transpose($A, 2, 3), 3, 2));
	}

	public function testSymmetrizeAndIsSymmetric(): void
	{
		$A = [1.0, 2.0, 4.0, 3.0];
		self::assertFalse(Flat::isSymmetric($A, 2));
		self::assertTrue(Flat::isSymmetric($A, 2, 2.5));
		$S = Flat::symmetrize($A, 2);
		self::assertSame([1.0, 3.0, 3.0, 3.0], $S);
		self::assertTrue(Flat::isSymmetric($S, 2));
	}

	public function testMatVecDotOuter(): void
	{
		self::assertSame([5.0, 11.0], Flat::matVec([1.0, 2.0, 3.0, 4.0], [1.0, 2.0], 2, 2));
		self::assertSame(11.0, Flat::dot([1.0, 2.0], [3.0, 4.0]));
		self::assertSame([3.0, 4.0, 6.0, 8.0], Flat::outer([1.0, 2.0], [3.0, 4.0]));
	}

	public function testNestedConversion(): void
	{
		$flat = Nested::toFlat([[1, 2], [3, 4]]);
		self::assertSame([1.0, 2.0, 3.0, 4.0], $flat);
		self::assertSame([[1.0, 2.0], [3.0, 4.0]], Nested::fromFlat($flat, 2, 2));
	}

	public function testSparseDenseRow(): void
	{
		$dense = Flat::denseRow([2 => 1.5, 0 => -1.0], 4);
		self::assertSame([-1.0, 0.0, 1.5, 0.0], $dense);
		self::assertSame([0 => -1.0, 2 => 1.5], Flat::sparseRow($dense));
	}

	public function testDimensionMismatchThrows(): void
	{
		$this->expectException(DimensionMismatch::class);
		Flat::multiply([1.0, 2.0], [1.0], 2, 2, 1);
	}

	public function testAllFiniteAndNorms(): void
	{
		self::assertTrue(Flat::allFinite([1.0, 2.0]));
		self::assertFalse(Flat::allFinite([1.0, \NAN]));
		self::assertFalse(Flat::allFinite([\INF]));
		self::assertSame(5.0, Flat::norm([3.0, 4.0]));
		self::assertSame(4.0, Flat::maxAbs([-4.0, 3.0]));
		self::assertSame(5.0, Flat::trace([1.0, 9.0, 9.0, 4.0], 2));
	}
}
