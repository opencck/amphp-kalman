<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Linalg;

use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Linalg\Householder;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class HouseholderTest extends TestCase
{
	/** @return iterable<string, array{int, int}> */
	public function shapes(): iterable
	{
		yield '2x1' => [2, 1];
		yield '4x2' => [4, 2];
		yield '6x3' => [6, 3];
		yield '16x8' => [16, 8];
		yield '5x5' => [5, 5];
	}

	/** @dataProvider shapes */
	public function testRPreservesGram(int $rows, int $cols): void
	{
		$rng = new Rng(300 + $rows);
		$A = $rng->matrix($rows, $cols);
		$R = Householder::r($A, $rows, $cols);
		// R upper triangular
		for ($i = 0; $i < $cols; $i++) {
			for ($j = 0; $j < $i; $j++) {
				self::assertSame(0.0, $R[$i * $cols + $j]);
			}
		}
		// RᵀR == AᵀA
		$AtA = Flat::transposedMultiply($A, $A, $rows, $cols, $cols);
		$RtR = Flat::transposedMultiply($R, $R, $cols, $cols, $cols);
		self::assertLessThan(1e-10, Flat::maxAbsDiff($AtA, $RtR));
	}

	public function testZeroColumnIsHandled(): void
	{
		$A = [0.0, 1.0, 0.0, 2.0, 0.0, 3.0]; // 3×2, first column zero
		$R = Householder::r($A, 3, 2);
		$AtA = Flat::transposedMultiply($A, $A, 3, 2, 2);
		$RtR = Flat::transposedMultiply($R, $R, 2, 2, 2);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($AtA, $RtR));
	}
}
