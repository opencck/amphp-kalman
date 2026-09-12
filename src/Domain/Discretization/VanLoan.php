<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Discretization;

use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Linalg\MatrixExponential;

/**
 * Exact discretisation of the continuous-time model dx = A·x·dt + G·dβ,
 * E[dβ dβᵀ] = Qc·dt (Van Loan, 1978):
 *
 *   M = [ −A      G·Qc·Gᵀ ] · dt        exp(M) = [ …    F⁻¹·Q ]
 *       [  0        Aᵀ    ]                      [ 0     Fᵀ   ]
 *
 *   F = (lower-right)ᵀ,   Q = F · (upper-right)
 */
final class VanLoan
{
	private function __construct()
	{
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $A n×n continuous-time dynamics
	 * @param array<int, float> $G n×q noise input matrix
	 * @param array<int, float> $Qc q×q spectral density
	 * @return array{F: array<int, float>, Q: array<int, float>}
	 */
	public static function discretize(array $A, array $G, array $Qc, int $n, int $q, float $dt): array
	{
		if (\count($A) !== $n * $n) {
			throw DimensionMismatch::forMatrix('A', $n * $n, \count($A));
		}
		if (\count($G) !== $n * $q) {
			throw DimensionMismatch::forMatrix('G', $n * $q, \count($G));
		}
		if (\count($Qc) !== $q * $q) {
			throw DimensionMismatch::forMatrix('Qc', $q * $q, \count($Qc));
		}
		$GQGt = Flat::multiplyTransposed(Flat::multiply($G, $Qc, $n, $q, $q), $G, $n, $q, $n);

		$N = 2 * $n;
		$M = \array_fill(0, $N * $N, 0.0);
		for ($i = 0; $i < $n; $i++) {
			for ($j = 0; $j < $n; $j++) {
				$M[$i * $N + $j] = -$A[$i * $n + $j] * $dt;
				$M[$i * $N + $n + $j] = $GQGt[$i * $n + $j] * $dt;
				$M[($n + $i) * $N + $n + $j] = $A[$j * $n + $i] * $dt;   // Aᵀ
			}
		}
		$E = MatrixExponential::compute($M, $N);

		$F = \array_fill(0, $n * $n, 0.0);
		$FinvQ = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			for ($j = 0; $j < $n; $j++) {
				$F[$i * $n + $j] = $E[($n + $j) * $N + $n + $i];   // transpose of lower-right
				$FinvQ[$i * $n + $j] = $E[$i * $N + $n + $j];       // upper-right
			}
		}
		$Q = Flat::symmetrize(Flat::multiply($F, $FinvQ, $n, $n, $n), $n);
		return ['F' => $F, 'Q' => $Q];
	}

	/**
	 * Convenience for models with G = I (noise on every state).
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $A n×n
	 * @param array<int, float> $Qc n×n
	 * @return array{F: array<int, float>, Q: array<int, float>}
	 */
	public static function discretizeFull(array $A, array $Qc, int $n, float $dt): array
	{
		return self::discretize($A, Flat::identity($n), $Qc, $n, $n, $dt);
	}
}
