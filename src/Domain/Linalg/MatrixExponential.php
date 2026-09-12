<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Linalg;

use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;

/**
 * exp(M) by scaling-and-squaring with a diagonal Padé(6,6) approximant
 * (Moler & Van Loan, "Nineteen Dubious Ways", method 3). Accuracy ≈ 1e-14
 * relative for well-scaled M. Off the hot path: used by Van Loan discretisation.
 */
final class MatrixExponential
{
	private const PADE_ORDER = 6;

	private function __construct()
	{
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $M n×n
	 * @return array<int, float> n×n
	 */
	public static function compute(array $M, int $n): array
	{
		if (\count($M) !== $n * $n) {
			throw DimensionMismatch::forMatrix('M', $n * $n, \count($M));
		}
		// scale so that the ∞-norm is ≤ 0.5
		$norm = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$row = 0.0;
			for ($j = 0; $j < $n; $j++) {
				$row += \abs($M[$i * $n + $j]);
			}
			if ($row > $norm) {
				$norm = $row;
			}
		}
		$s = 0;
		if ($norm > 0.5) {
			$s = (int) \max(0, \ceil(\log($norm / 0.5, 2.0)));
		}
		$A = $s > 0 ? Flat::scale($M, 1.0 / (2 ** $s)) : $M;

		// Padé coefficients c_k = c_{k-1} · (q − k + 1) / (k · (2q − k + 1))
		$q = self::PADE_ORDER;
		$c = 1.0;
		$I = Flat::identity($n);
		$X = $A;
		$N = $I;
		$D = $I;
		$size = $n * $n;
		for ($k = 1; $k <= $q; $k++) {
			$c = $c * ($q - $k + 1) / ($k * (2 * $q - $k + 1));
			if ($k > 1) {
				$X = Flat::multiply($A, $X, $n, $n, $n);
			}
			$cSigned = ($k % 2 === 0) ? $c : -$c;
			$Nn = \array_fill(0, $size, 0.0);
			$Dn = \array_fill(0, $size, 0.0);
			for ($i = 0; $i < $size; $i++) {
				$xi = $X[$i];
				$Nn[$i] = $N[$i] + $c * $xi;
				$Dn[$i] = $D[$i] + $cSigned * $xi;
			}
			$N = $Nn;
			$D = $Dn;
		}
		$E = LU::solve($D, $N, $n, $n);
		for ($k = 0; $k < $s; $k++) {
			$E = Flat::multiply($E, $E, $n, $n, $n);
		}
		return $E;
	}
}
