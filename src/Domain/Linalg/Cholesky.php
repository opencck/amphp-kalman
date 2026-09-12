<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Linalg;

use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\NotPositiveDefinite;

/**
 * Cholesky factorisation A = L·Lᵀ (L lower triangular) and the triangular
 * solves built on it. This is the ONLY way the library inverts anything:
 * the production code never forms an explicit inverse (rule §0.2 p.15);
 * inverse() exists for the information filter and for tests.
 *
 * All matrices are flat row-major; L stores exact zeros above the diagonal.
 */
final class Cholesky
{
	private function __construct()
	{
	}

	/**
	 * @param array<int, float> $A symmetric positive definite n×n
	 * @return array<int, float> L lower triangular, n×n
	 * @throws NotPositiveDefinite
	 */
	public static function decompose(array $A, int $n): array
	{
		if (\count($A) !== $n * $n) {
			throw DimensionMismatch::forMatrix('A', $n * $n, \count($A));
		}
		$L = \array_fill(0, $n * $n, 0.0);
		for ($j = 0; $j < $n; $j++) {
			$jn = $j * $n;
			$sum = $A[$jn + $j];
			for ($k = 0; $k < $j; $k++) {
				$l = $L[$jn + $k];
				$sum -= $l * $l;
			}
			if (!($sum > 0.0)) {
				throw NotPositiveDefinite::atPivot($j, $sum);
			}
			$ljj = \sqrt($sum);
			$L[$jn + $j] = $ljj;
			$inv = 1.0 / $ljj;
			for ($i = $j + 1; $i < $n; $i++) {
				$in = $i * $n;
				$sum = $A[$in + $j];
				for ($k = 0; $k < $j; $k++) {
					$sum -= $L[$in + $k] * $L[$jn + $k];
				}
				$L[$in + $j] = $sum * $inv;
			}
		}
		return $L;
	}

	/**
	 * Cholesky for positive SEMI-definite matrices (e.g. singular Q).
	 * Pivots ≤ tolerance produce a zero column instead of an exception.
	 * Used by simulators and the UKF sigma-point generator.
	 *
	 * @param array<int, float> $A
	 * @return array<int, float>
	 */
	public static function decomposeSemidefinite(array $A, int $n, float $tolerance = 1e-12): array
	{
		if (\count($A) !== $n * $n) {
			throw DimensionMismatch::forMatrix('A', $n * $n, \count($A));
		}
		$L = \array_fill(0, $n * $n, 0.0);
		$scale = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$d = $A[$i * $n + $i];
			if ($d > $scale) {
				$scale = $d;
			}
		}
		$threshold = $tolerance * ($scale > 0.0 ? $scale : 1.0);
		for ($j = 0; $j < $n; $j++) {
			$jn = $j * $n;
			$sum = $A[$jn + $j];
			for ($k = 0; $k < $j; $k++) {
				$l = $L[$jn + $k];
				$sum -= $l * $l;
			}
			if ($sum <= $threshold) {
				if ($sum < -$threshold) {
					throw NotPositiveDefinite::atPivot($j, $sum);
				}
				// singular direction: leave column j at zero
				continue;
			}
			$ljj = \sqrt($sum);
			$L[$jn + $j] = $ljj;
			$inv = 1.0 / $ljj;
			for ($i = $j + 1; $i < $n; $i++) {
				$in = $i * $n;
				$sum = $A[$in + $j];
				for ($k = 0; $k < $j; $k++) {
					$sum -= $L[$in + $k] * $L[$jn + $k];
				}
				$L[$in + $j] = $sum * $inv;
			}
		}
		return $L;
	}

	/**
	 * Solves L·y = b (forward substitution).
	 *
	 * @param array<int, float> $L
	 * @param array<int, float> $b
	 * @return array<int, float>
	 */
	public static function solveForward(array $L, array $b, int $n): array
	{
		$y = \array_fill(0, $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			$sum = $b[$i];
			for ($k = 0; $k < $i; $k++) {
				$sum -= $L[$in + $k] * $y[$k];
			}
			$y[$i] = $sum / $L[$in + $i];
		}
		return $y;
	}

	/**
	 * Solves Lᵀ·x = y (backward substitution).
	 *
	 * @param array<int, float> $L
	 * @param array<int, float> $y
	 * @return array<int, float>
	 */
	public static function solveBackward(array $L, array $y, int $n): array
	{
		$x = \array_fill(0, $n, 0.0);
		for ($i = $n - 1; $i >= 0; $i--) {
			$sum = $y[$i];
			for ($k = $i + 1; $k < $n; $k++) {
				$sum -= $L[$k * $n + $i] * $x[$k];
			}
			$x[$i] = $sum / $L[$i * $n + $i];
		}
		return $x;
	}

	/**
	 * Solves A·x = b given L = chol(A).
	 *
	 * @param array<int, float> $L
	 * @param array<int, float> $b
	 * @return array<int, float>
	 */
	public static function solve(array $L, array $b, int $n): array
	{
		return self::solveBackward($L, self::solveForward($L, $b, $n), $n);
	}

	/**
	 * Solves A·X = B for a matrix right-hand side B (n×m), given L = chol(A).
	 *
	 * @param array<int, float> $L
	 * @param array<int, float> $B n×m
	 * @return array<int, float> n×m
	 */
	public static function solveMatrix(array $L, array $B, int $n, int $m): array
	{
		$X = \array_fill(0, $n * $m, 0.0);
		for ($j = 0; $j < $m; $j++) {
			$b = [];
			for ($i = 0; $i < $n; $i++) {
				$b[] = $B[$i * $m + $j];
			}
			$x = self::solve($L, $b, $n);
			for ($i = 0; $i < $n; $i++) {
				$X[$i * $m + $j] = $x[$i];
			}
		}
		return $X;
	}

	/**
	 * ln|A| = 2·Σ ln L_ii.
	 *
	 * @param array<int, float> $L
	 */
	public static function logDeterminant(array $L, int $n): float
	{
		$sum = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$sum += \log($L[$i * $n + $i]);
		}
		return 2.0 * $sum;
	}

	/**
	 * A⁻¹ from L = chol(A). Result is exactly symmetric. Tests and the
	 * information filter only.
	 *
	 * @param array<int, float> $L
	 * @return array<int, float>
	 */
	public static function inverse(array $L, int $n): array
	{
		$inv = \array_fill(0, $n * $n, 0.0);
		$e = \array_fill(0, $n, 0.0);
		for ($j = 0; $j < $n; $j++) {
			$e[$j] = 1.0;
			$col = self::solve($L, $e, $n);
			$e[$j] = 0.0;
			for ($i = $j; $i < $n; $i++) {
				$inv[$i * $n + $j] = $col[$i];
				$inv[$j * $n + $i] = $col[$i];
			}
		}
		return $inv;
	}

	/**
	 * vᵀ·A⁻¹·v = ‖L⁻¹v‖² (Mahalanobis distance squared).
	 *
	 * @param array<int, float> $L
	 * @param array<int, float> $v
	 */
	public static function quadraticForm(array $L, array $v, int $n): float
	{
		$y = self::solveForward($L, $v, $n);
		$sum = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$sum += $y[$i] * $y[$i];
		}
		return $sum;
	}

	/** @param array<int, float> $A */
	public static function isPositiveDefinite(array $A, int $n): bool
	{
		try {
			self::decompose($A, $n);
			return true;
		} catch (NotPositiveDefinite) {
			return false;
		}
	}
}
