<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Nonlinear dynamics x_k = f(x_{k-1}, dt) + w for EKF / UKF.
 */
interface NonlinearMotionModel
{
	public function stateSize(): int;

	/**
	 * @param array<int, float> $x
	 * @return array<int, float> f(x, dt)
	 */
	public function propagate(array $x, float $dt): array;

	/**
	 * Jacobian ∂f/∂x evaluated at x.
	 *
	 * @param array<int, float> $x
	 * @return array<int, float> n² row-major
	 */
	public function jacobian(array $x, float $dt): array;

	/**
	 * @return array<int, float> n² row-major
	 */
	public function processNoise(float $dt): array;
}
