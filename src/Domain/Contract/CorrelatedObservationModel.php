<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Observation model with a full (non-diagonal) R. Decorrelated into an
 * {@see ObservationModel} via Cholesky R = L·Lᵀ:  z' = L⁻¹z, H' = L⁻¹H, R' = I.
 */
interface CorrelatedObservationModel
{
	public function channelCount(): int;

	public function stateSize(): int;

	/**
	 * @return array<int, float> m×n row-major
	 */
	public function observationMatrix(): array;

	/**
	 * @return array<int, float> m×m row-major, symmetric positive definite
	 */
	public function measurementNoise(): array;
}
