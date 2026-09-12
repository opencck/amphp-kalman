<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Linalg;

use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\NumericalFailure;

/**
 * LU decomposition with partial pivoting for general (non-symmetric) square
 * matrices. Used OFF the hot path only: matrix exponential (Van Loan
 * discretisation), Riccati iteration, observability rank. The filter itself
 * never inverts anything (§0.2 p.15).
 */
final class LU
{
	private function __construct()
	{
	}

	/**
	 * Solves A·X = B for X (n×m) by Gaussian elimination with partial pivoting.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $A n×n
	 * @param array<int, float> $B n×m
	 * @return array<int, float> n×m
	 */
	public static function solve(array $A, array $B, int $n, int $m): array
	{
		if (\count($A) !== $n * $n) {
			throw DimensionMismatch::forMatrix('A', $n * $n, \count($A));
		}
		if (\count($B) !== $n * $m) {
			throw DimensionMismatch::forMatrix('B', $n * $m, \count($B));
		}
		$X = $B;
		for ($col = 0; $col < $n; $col++) {
			$pivot = $col;
			$max = \abs($A[$col * $n + $col]);
			for ($r = $col + 1; $r < $n; $r++) {
				$v = \abs($A[$r * $n + $col]);
				if ($v > $max) {
					$max = $v;
					$pivot = $r;
				}
			}
			if ($max === 0.0) {
				throw new NumericalFailure('Singular matrix in LU solve');
			}
			if ($pivot !== $col) {
				for ($j = 0; $j < $n; $j++) {
					$t = $A[$col * $n + $j];
					$A[$col * $n + $j] = $A[$pivot * $n + $j];
					$A[$pivot * $n + $j] = $t;
				}
				for ($j = 0; $j < $m; $j++) {
					$t = $X[$col * $m + $j];
					$X[$col * $m + $j] = $X[$pivot * $m + $j];
					$X[$pivot * $m + $j] = $t;
				}
			}
			$p = $A[$col * $n + $col];
			for ($r = $col + 1; $r < $n; $r++) {
				$f = $A[$r * $n + $col] / $p;
				if ($f === 0.0) {
					continue;
				}
				for ($j = $col; $j < $n; $j++) {
					$A[$r * $n + $j] -= $f * $A[$col * $n + $j];
				}
				for ($j = 0; $j < $m; $j++) {
					$X[$r * $m + $j] -= $f * $X[$col * $m + $j];
				}
			}
		}
		// back substitution
		for ($col = $n - 1; $col >= 0; $col--) {
			$p = $A[$col * $n + $col];
			for ($j = 0; $j < $m; $j++) {
				$sum = $X[$col * $m + $j];
				for ($k = $col + 1; $k < $n; $k++) {
					$sum -= $A[$col * $n + $k] * $X[$k * $m + $j];
				}
				$X[$col * $m + $j] = $sum / $p;
			}
		}
		return $X;
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $A n×n
	 * @return array<int, float>
	 */
	public static function inverse(array $A, int $n): array
	{
		return self::solve($A, Flat::identity($n), $n, $n);
	}

	/**
	 * Numerical rank by Gaussian elimination with a relative tolerance.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $A rows×cols
	 */
	public static function rank(array $A, int $rows, int $cols, float $tolerance = 1e-10): int
	{
		if (\count($A) !== $rows * $cols) {
			throw DimensionMismatch::forMatrix('A', $rows * $cols, \count($A));
		}
		$scale = Flat::maxAbs($A);
		if ($scale === 0.0) {
			return 0;
		}
		$eps = $tolerance * $scale;
		$rank = 0;
		$row = 0;
		for ($col = 0; $col < $cols && $row < $rows; $col++) {
			$pivot = $row;
			$max = \abs($A[$row * $cols + $col]);
			for ($r = $row + 1; $r < $rows; $r++) {
				$v = \abs($A[$r * $cols + $col]);
				if ($v > $max) {
					$max = $v;
					$pivot = $r;
				}
			}
			if ($max <= $eps) {
				continue;
			}
			if ($pivot !== $row) {
				for ($j = 0; $j < $cols; $j++) {
					$t = $A[$row * $cols + $j];
					$A[$row * $cols + $j] = $A[$pivot * $cols + $j];
					$A[$pivot * $cols + $j] = $t;
				}
			}
			$p = $A[$row * $cols + $col];
			for ($r = $row + 1; $r < $rows; $r++) {
				$f = $A[$r * $cols + $col] / $p;
				if ($f === 0.0) {
					continue;
				}
				for ($j = $col; $j < $cols; $j++) {
					$A[$r * $cols + $j] -= $f * $A[$row * $cols + $j];
				}
			}
			$row++;
			$rank++;
		}
		return $rank;
	}
}
