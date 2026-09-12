<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\NonlinearMotionModel;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * Presents a linear MotionModel as a NonlinearMotionModel (f = F·x + u,
 * Jacobian = F), so EKF/UKF can run on linear models — used by the
 * "EKF/UKF on a linear model equals KF" tests and for mixed setups.
 */
final class LinearMotionAdapter implements NonlinearMotionModel
{
	public function __construct(private readonly MotionModel $inner)
	{
	}

	public function inner(): MotionModel
	{
		return $this->inner;
	}

	public function stateSize(): int
	{
		return $this->inner->stateSize();
	}

	public function propagate(array $x, float $dt): array
	{
		$n = $this->inner->stateSize();
		$out = Flat::matVec($this->inner->transition($dt), $x, $n, $n);
		$u = $this->inner->control($dt);
		return $u === null ? $out : Flat::add($out, $u);
	}

	public function jacobian(array $x, float $dt): array
	{
		return $this->inner->transition($dt);
	}

	public function processNoise(float $dt): array
	{
		return $this->inner->processNoise($dt);
	}
}
