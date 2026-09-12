<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Strategy for storing and updating the state covariance P.
 *
 * Implementations: dense P with sequential scalar updates, Joseph form,
 * UD factorisation (Bierman–Thornton), square-root (Potter + Householder).
 * The filter only talks to this interface and never sees the storage format.
 *
 * Scalar correction is two-phase so that gating / robust weighting can
 * inspect the innovation variance BEFORE anything is modified (see ADR-003):
 *
 *   $s   = $rep->prepareScalar($h, $r);   // phi = P·hᵀ, s = h·phi + r, nothing mutated
 *   $phi = $rep->gain();                  // K = phi / s
 *   ... gate on innovation² / s ...
 *   $rep->commitScalar($rEffective);      // P ← P − phi·phiᵀ / (h·phi + rEffective)
 *
 * The representation owns all scratch buffers; no allocation happens on
 * the hot path after construction.
 */
interface CovarianceRepresentation
{
	public function size(): int;

	/**
	 * Time update with dense matrices: P ← F·P·Fᵀ + Q.
	 *
	 * @param array<int, float> $F n² row-major
	 * @param array<int, float> $Q n² row-major
	 */
	public function predictDense(array $F, array $Q): void;

	/**
	 * Time update using the model's O(n²) in-place kernel. Advances $x as well.
	 *
	 * @param array<int, float> $x state vector, advanced in place
	 * @param array<int, float> $Q n² row-major
	 */
	public function predictSparse(SparseMotionModel $model, float $dt, array &$x, array $Q): void;

	/**
	 * Phase 1 of a scalar correction. Computes phi = P·hᵀ and s = h·phi + r
	 * without modifying the covariance.
	 *
	 * @param array<int, float> $h sparse row (state index => coefficient)
	 * @param float $r measurement noise variance (> 0)
	 * @return float s — innovation variance
	 */
	public function prepareScalar(array $h, float $r): float;

	/**
	 * Gain numerator phi = P·hᵀ from the last prepareScalar(). The Kalman gain
	 * is K = phi / s. Returned array is an internal buffer: read-only, valid
	 * until the next prepareScalar().
	 *
	 * @return array<int, float> n elements
	 */
	public function gain(): array;

	/**
	 * Phase 2 of a scalar correction: apply the covariance update for the
	 * channel prepared by prepareScalar(), with the (possibly inflated)
	 * measurement variance $r. Must keep P exactly symmetric.
	 */
	public function commitScalar(float $r): void;

	/**
	 * @return array<int, float> n² row-major copy of P (allocates — API boundary only)
	 */
	public function toDense(): array;

	/**
	 * @param array<int, float> $P n² row-major, symmetric positive definite
	 */
	public function fromDense(array $P): void;

	/** Diagonal element P[i][i]. */
	public function variance(int $i): float;
}
