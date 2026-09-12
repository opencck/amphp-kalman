<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Linalg;

/**
 * Householder QR used by the square-root filter's time update.
 *
 * triangularizeInPlace() takes A (rows × cols, rows ≥ cols, row-major) and
 * overwrites its leading cols × cols block with the upper-triangular R of
 * A = Q·R. Q is never formed. Allocation-free apart from the caller's
 * scratch vector.
 */
final class Householder
{
	private function __construct()
	{
	}

	/**
	 * @param array<int, float> $A rows×cols, overwritten
	 * @param array<int, float> $work scratch of length ≥ cols
	 */
	public static function triangularizeInPlace(array &$A, int $rows, int $cols, array &$work): void
	{
		for ($k = 0; $k < $cols; $k++) {
			// norm of column k below (and including) row k
			$norm = 0.0;
			for ($i = $k; $i < $rows; $i++) {
				$a = $A[$i * $cols + $k];
				$norm += $a * $a;
			}
			if ($norm === 0.0) {
				continue;
			}
			$norm = \sqrt($norm);
			$akk = $A[$k * $cols + $k];
			$alpha = $akk > 0.0 ? -$norm : $norm;
			// v = a_k − alpha e_k, stored in column k below the diagonal (in place)
			$A[$k * $cols + $k] = $akk - $alpha;
			$vnorm2 = 0.0;
			for ($i = $k; $i < $rows; $i++) {
				$v = $A[$i * $cols + $k];
				$vnorm2 += $v * $v;
			}
			if ($vnorm2 === 0.0) {
				$A[$k * $cols + $k] = $alpha;
				continue;
			}
			$beta = 2.0 / $vnorm2;
			// apply H = I − beta v vᵀ to the remaining columns j > k
			for ($j = $k + 1; $j < $cols; $j++) {
				$dot = 0.0;
				for ($i = $k; $i < $rows; $i++) {
					$dot += $A[$i * $cols + $k] * $A[$i * $cols + $j];
				}
				$work[$j] = $dot * $beta;
			}
			for ($j = $k + 1; $j < $cols; $j++) {
				$w = $work[$j];
				if ($w === 0.0) {
					continue;
				}
				for ($i = $k; $i < $rows; $i++) {
					$A[$i * $cols + $j] -= $w * $A[$i * $cols + $k];
				}
			}
			// column k becomes (alpha, 0, …, 0)
			$A[$k * $cols + $k] = $alpha;
			for ($i = $k + 1; $i < $rows; $i++) {
				$A[$i * $cols + $k] = 0.0;
			}
		}
	}

	/**
	 * Convenience: returns R (cols × cols upper triangular) of A = Q·R.
	 *
	 * @param array<int, float> $A rows×cols
	 * @return array<int, float>
	 */
	public static function r(array $A, int $rows, int $cols): array
	{
		$work = \array_fill(0, $cols, 0.0);
		self::triangularizeInPlace($A, $rows, $cols, $work);
		$R = \array_fill(0, $cols * $cols, 0.0);
		for ($i = 0; $i < $cols; $i++) {
			for ($j = $i; $j < $cols; $j++) {
				$R[$i * $cols + $j] = $A[$i * $cols + $j];
			}
		}
		return $R;
	}
}
