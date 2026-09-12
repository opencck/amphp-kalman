<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Linear state dynamics x_k = F(dt)·x_{k-1} + B·u(dt) + w,  w ~ N(0, Q(dt)).
 *
 * All matrices are flat row-major float arrays: M[i][j] is stored at $M[$i * $n + $j].
 * F and Q are functions of the elapsed interval dt (seconds) and MUST be
 * recomputed for every new dt unless the model also implements {@see StationaryModel},
 * in which case the filter may cache them keyed by quantised dt.
 */
interface MotionModel
{
	public function stateSize(): int;

	/**
	 * Transition matrix F(dt).
	 *
	 * @return array<int, float> n² elements, row-major
	 */
	public function transition(float $dt): array;

	/**
	 * Process noise covariance Q(dt). Must be symmetric positive semi-definite.
	 *
	 * @return array<int, float> n² elements, row-major
	 */
	public function processNoise(float $dt): array;

	/**
	 * Known control input B·u(dt) added to the state after the transition,
	 * or null when the model has no control term.
	 *
	 * @return array<int, float>|null n elements
	 */
	public function control(float $dt): ?array;
}
