<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Linalg;

use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Dense linear algebra over flat row-major float arrays.
 *
 * A matrix n×m is a array<int, float> of n·m elements, element (i, j) at index i·m + j.
 * These helpers are used for model construction, tests and the dense predict
 * path. They allocate their results — the filter hot loop uses the in-place
 * kernels of the covariance representations instead.
 */
final class Flat
{
	private function __construct()
	{
	}

	/** @return array<int, float> */
	public static function identity(int $n): array
	{
		$I = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$I[$i * $n + $i] = 1.0;
		}
		return $I;
	}

	/** @return array<int, float> */
	public static function zeros(int $rows, int $cols): array
	{
		return \array_fill(0, $rows * $cols, 0.0);
	}

	/**
	 * @param array<int, float> $diagonal
	 * @return array<int, float>
	 */
	public static function diagonal(array $diagonal): array
	{
		$n = \count($diagonal);
		$D = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$D[$i * $n + $i] = $diagonal[$i];
		}
		return $D;
	}

	/**
	 * @param array<int, float> $A n×n
	 * @return array<int, float> n
	 */
	public static function diagonalOf(array $A, int $n): array
	{
		$d = [];
		for ($i = 0; $i < $n; $i++) {
			$d[] = $A[$i * $n + $i];
		}
		return $d;
	}

	/**
	 * C = A·B with A n×k, B k×m.
	 *
	 * @param array<int, float> $A
	 * @param array<int, float> $B
	 * @return array<int, float> n×m
	 */
	public static function multiply(array $A, array $B, int $n, int $k, int $m): array
	{
		self::assertSize('A', $A, $n * $k);
		self::assertSize('B', $B, $k * $m);
		// dot-product form with a local accumulator: the "C[i,j] += a·B[p,j]" shape is
		// miscompiled by the tracing JIT on PHP 8.2/8.3 (ADR-007) and is slower anyway
		$C = \array_fill(0, $n * $m, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$ik = $i * $k;
			$im = $i * $m;
			for ($j = 0; $j < $m; $j++) {
				$sum = 0.0;
				for ($p = 0; $p < $k; $p++) {
					$sum += $A[$ik + $p] * $B[$p * $m + $j];
				}
				$C[$im + $j] = $sum;
			}
		}
		return $C;
	}

	/**
	 * C = A·Bᵀ with A n×k, B m×k.
	 *
	 * @param array<int, float> $A
	 * @param array<int, float> $B
	 * @return array<int, float> n×m
	 */
	public static function multiplyTransposed(array $A, array $B, int $n, int $k, int $m): array
	{
		self::assertSize('A', $A, $n * $k);
		self::assertSize('B', $B, $m * $k);
		$C = \array_fill(0, $n * $m, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$ik = $i * $k;
			for ($j = 0; $j < $m; $j++) {
				$jk = $j * $k;
				$sum = 0.0;
				for ($p = 0; $p < $k; $p++) {
					$sum += $A[$ik + $p] * $B[$jk + $p];
				}
				$C[$i * $m + $j] = $sum;
			}
		}
		return $C;
	}

	/**
	 * C = Aᵀ·B with A k×n, B k×m.
	 *
	 * @param array<int, float> $A
	 * @param array<int, float> $B
	 * @return array<int, float> n×m
	 */
	public static function transposedMultiply(array $A, array $B, int $k, int $n, int $m): array
	{
		self::assertSize('A', $A, $k * $n);
		self::assertSize('B', $B, $k * $m);
		$C = \array_fill(0, $n * $m, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$im = $i * $m;
			for ($j = 0; $j < $m; $j++) {
				$sum = 0.0;
				for ($p = 0; $p < $k; $p++) {
					$sum += $A[$p * $n + $i] * $B[$p * $m + $j];
				}
				$C[$im + $j] = $sum;
			}
		}
		return $C;
	}

	/**
	 * @param array<int, float> $A n×m
	 * @return array<int, float> m×n
	 */
	public static function transpose(array $A, int $n, int $m): array
	{
		self::assertSize('A', $A, $n * $m);
		$T = \array_fill(0, $n * $m, 0.0);
		for ($i = 0; $i < $n; $i++) {
			for ($j = 0; $j < $m; $j++) {
				$T[$j * $n + $i] = $A[$i * $m + $j];
			}
		}
		return $T;
	}

	/**
	 * F·P·Fᵀ for square n×n matrices. The result is exactly symmetric:
	 * the upper triangle is computed and mirrored.
	 *
	 * @param array<int, float> $F
	 * @param array<int, float> $P
	 * @return array<int, float>
	 */
	public static function sandwich(array $F, array $P, int $n): array
	{
		self::assertSize('F', $F, $n * $n);
		self::assertSize('P', $P, $n * $n);
		$T = self::multiply($F, $P, $n, $n, $n);
		$R = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$jn = $j * $n;
				$sum = 0.0;
				for ($p = 0; $p < $n; $p++) {
					$sum += $T[$in + $p] * $F[$jn + $p];
				}
				$R[$in + $j] = $sum;
				$R[$jn + $i] = $sum;
			}
		}
		return $R;
	}

	/**
	 * @param array<int, float> $A
	 * @param array<int, float> $B
	 * @return array<int, float>
	 */
	public static function add(array $A, array $B): array
	{
		$size = \count($A);
		self::assertSize('B', $B, $size);
		$C = \array_fill(0, $size, 0.0);
		for ($i = 0; $i < $size; $i++) {
			$C[$i] = $A[$i] + $B[$i];
		}
		return $C;
	}

	/**
	 * @param array<int, float> $A
	 * @param array<int, float> $B
	 * @return array<int, float>
	 */
	public static function subtract(array $A, array $B): array
	{
		$size = \count($A);
		self::assertSize('B', $B, $size);
		$C = \array_fill(0, $size, 0.0);
		for ($i = 0; $i < $size; $i++) {
			$C[$i] = $A[$i] - $B[$i];
		}
		return $C;
	}

	/**
	 * @param array<int, float> $A
	 * @return array<int, float>
	 */
	public static function scale(array $A, float $alpha): array
	{
		$size = \count($A);
		for ($i = 0; $i < $size; $i++) {
			$A[$i] *= $alpha;
		}
		return $A;
	}

	/**
	 * y = A·x with A n×m, x m.
	 *
	 * @param array<int, float> $A
	 * @param array<int, float> $x
	 * @return array<int, float> n
	 */
	public static function matVec(array $A, array $x, int $n, int $m): array
	{
		self::assertSize('A', $A, $n * $m);
		self::assertSize('x', $x, $m);
		$y = \array_fill(0, $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$im = $i * $m;
			$sum = 0.0;
			for ($j = 0; $j < $m; $j++) {
				$sum += $A[$im + $j] * $x[$j];
			}
			$y[$i] = $sum;
		}
		return $y;
	}

	/**
	 * @param array<int, float> $x
	 * @param array<int, float> $y
	 */
	public static function dot(array $x, array $y): float
	{
		$n = \count($x);
		self::assertSize('y', $y, $n);
		$sum = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$sum += $x[$i] * $y[$i];
		}
		return $sum;
	}

	/**
	 * x·yᵀ outer product, n×m.
	 *
	 * @param array<int, float> $x
	 * @param array<int, float> $y
	 * @return array<int, float>
	 */
	public static function outer(array $x, array $y): array
	{
		$n = \count($x);
		$m = \count($y);
		$O = \array_fill(0, $n * $m, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$xi = $x[$i];
			for ($j = 0; $j < $m; $j++) {
				$O[$i * $m + $j] = $xi * $y[$j];
			}
		}
		return $O;
	}

	/**
	 * (A + Aᵀ)/2 — exact symmetry restored by averaging.
	 *
	 * @param array<int, float> $A
	 * @return array<int, float>
	 */
	public static function symmetrize(array $A, int $n): array
	{
		self::assertSize('A', $A, $n * $n);
		for ($i = 0; $i < $n; $i++) {
			for ($j = $i + 1; $j < $n; $j++) {
				$v = 0.5 * ($A[$i * $n + $j] + $A[$j * $n + $i]);
				$A[$i * $n + $j] = $v;
				$A[$j * $n + $i] = $v;
			}
		}
		return $A;
	}

	/**
	 * @param array<int, float> $A
	 * @param float $tolerance 0.0 means bitwise equality of mirrored elements
	 */
	public static function isSymmetric(array $A, int $n, float $tolerance = 0.0): bool
	{
		if (\count($A) !== $n * $n) {
			return false;
		}
		for ($i = 0; $i < $n; $i++) {
			for ($j = $i + 1; $j < $n; $j++) {
				$d = $A[$i * $n + $j] - $A[$j * $n + $i];
				if ($tolerance === 0.0 ? $d !== 0.0 : ($d < -$tolerance || $d > $tolerance)) {
					return false;
				}
			}
		}
		return true;
	}

	/** @param array<int, float> $A */
	public static function allFinite(array $A): bool
	{
		foreach ($A as $v) {
			if (!\is_finite($v)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int, float> $A
	 * @param array<int, float> $B
	 */
	public static function maxAbsDiff(array $A, array $B): float
	{
		$size = \count($A);
		self::assertSize('B', $B, $size);
		$max = 0.0;
		for ($i = 0; $i < $size; $i++) {
			$d = $A[$i] - $B[$i];
			if ($d < 0.0) {
				$d = -$d;
			}
			if ($d > $max) {
				$max = $d;
			}
		}
		return $max;
	}

	/** @param array<int, float> $A */
	public static function maxAbs(array $A): float
	{
		$max = 0.0;
		foreach ($A as $v) {
			if ($v < 0.0) {
				$v = -$v;
			}
			if ($v > $max) {
				$max = $v;
			}
		}
		return $max;
	}

	/** @param array<int, float> $A n×n */
	public static function trace(array $A, int $n): float
	{
		$t = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$t += $A[$i * $n + $i];
		}
		return $t;
	}

	/**
	 * Frobenius norm.
	 *
	 * @param array<int, float> $A
	 */
	public static function norm(array $A): float
	{
		$sum = 0.0;
		foreach ($A as $v) {
			$sum += $v * $v;
		}
		return \sqrt($sum);
	}

	/**
	 * Densifies a sparse row (state index => coefficient) to a list of length n.
	 *
	 * @param array<int, float> $row
	 * @return array<int, float>
	 */
	public static function denseRow(array $row, int $n): array
	{
		$h = \array_fill(0, $n, 0.0);
		foreach ($row as $j => $c) {
			if ($j < 0 || $j >= $n) {
				throw new InvalidArgument(\sprintf('Row index %d out of range [0, %d)', $j, $n));
			}
			$h[$j] = $c;
		}
		return $h;
	}

	/**
	 * Sparsifies a dense row: drops exact zeros.
	 *
	 * @param array<int, float> $row
	 * @return array<int, float>
	 */
	public static function sparseRow(array $row): array
	{
		$out = [];
		foreach ($row as $j => $c) {
			if ($c !== 0.0) {
				$out[$j] = $c;
			}
		}
		return $out;
	}

	/** @param array<int, float> $A */
	private static function assertSize(string $name, array $A, int $expected): void
	{
		if (\count($A) !== $expected) {
			throw DimensionMismatch::forMatrix($name, $expected, \count($A));
		}
	}
}
