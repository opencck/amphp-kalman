<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Motion model with a structured (sparse) F that can apply the prediction
 * x ← F·x + B·u and P ← F·P·Fᵀ in place in O(n²) instead of O(n³).
 *
 * Q(dt) is NOT added here — the covariance representation adds it after
 * calling advanceInPlace(), so that the same kernel serves every form.
 */
interface SparseMotionModel extends MotionModel
{
	/**
	 * @param array<int, float> $x state vector (n), advanced in place (including control term)
	 * @param array<int, float> $P covariance (n², row-major), replaced by F·P·Fᵀ in place;
	 *                       the result MUST be exactly symmetric (compute upper triangle, mirror)
	 */
	public function advanceInPlace(array &$x, array &$P, float $dt): void;
}
