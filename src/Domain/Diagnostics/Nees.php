<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Diagnostics;

use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;

/**
 * Normalised estimation error squared (§2.9): η = (x − x̂)ᵀ P⁻¹ (x − x̂) ~ χ²(n).
 * Requires the true state — simulation and backtests with ground truth only.
 */
final class Nees
{
	private function __construct()
	{
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $truth
	 * @param array<int, float> $mean
	 * @param array<int, float> $covariance n² row-major SPD
	 */
	public static function compute(array $truth, array $mean, array $covariance, int $n): float
	{
		if (\count($truth) !== $n || \count($mean) !== $n) {
			throw DimensionMismatch::forVector('truth/mean', $n, \count($truth));
		}
		$e = [];
		for ($i = 0; $i < $n; $i++) {
			$e[] = $truth[$i] - $mean[$i];
		}
		$L = Cholesky::decompose($covariance, $n);
		return Cholesky::quadraticForm($L, $e, $n);
	}
}
