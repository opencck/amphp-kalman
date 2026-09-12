<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Smoothing;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\FilterFactory;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * §2.12 Rauch–Tung–Striebel fixed-interval smoother (backward pass):
 *
 *   C_k     = P_{k|k} F_{k+1}ᵀ (P⁻_{k+1})⁻¹          (via Cholesky solve, no explicit inverse)
 *   x̂_{k|N} = x̂_{k|k} + C_k (x̂_{k+1|N} − x̂⁻_{k+1})
 *   P_{k|N} = P_{k|k} + C_k (P_{k+1|N} − P⁻_{k+1}) C_kᵀ
 *   P_{k,k−1|N} = P_{k|N} C_{k−1}ᵀ                      (lag-one covariance for EM)
 *
 * F_{k+1} = F(dt_{k+1}) is recomputed from the motion model, so only dt is
 * stored per step. Steps with dt = 0 (pure corrections) use F = I.
 */
final class RauchTungStriebel
{
	private function __construct()
	{
	}

	/**
	 * @return array{mean: array<int, array<int, float>>, covariance: array<int, array<int, float>>, lagOne: array<int, array<int, float>|null>}
	 */
	public static function smooth(FilterTrajectory $trajectory, MotionModel $motion): array
	{
		return self::smoothArrays($trajectory->toArrays(), $motion);
	}

	/**
	 * Offloadable variant: everything is a plain array; the motion model is a
	 * serialisable config (FilterFactory::motion()).
	 *
	 * @deterministic
	 * @offloadable
	 * @param array{n: int, dt: array<int, float>, xPrior: array<int, array<int, float>>, PPrior: array<int, array<int, float>>, xPost: array<int, array<int, float>>, PPost: array<int, array<int, float>>, ts?: array<int, int>} $trajectory
	 * @param array<string, mixed> $modelConfig FilterFactory::fromConfig() shape (only "motion" is used)
	 * @return array{mean: array<int, array<int, float>>, covariance: array<int, array<int, float>>, lagOne: array<int, array<int, float>|null>}
	 */
	public static function smoothConfig(array $trajectory, array $modelConfig): array
	{
		return self::smoothArrays($trajectory, FilterFactory::motion($modelConfig));
	}

	/**
	 * @param array{n: int, dt: array<int, float>, xPrior: array<int, array<int, float>>, PPrior: array<int, array<int, float>>, xPost: array<int, array<int, float>>, PPost: array<int, array<int, float>>, ts?: array<int, int>} $t
	 * @return array{mean: array<int, array<int, float>>, covariance: array<int, array<int, float>>, lagOne: array<int, array<int, float>|null>}
	 */
	public static function smoothArrays(array $t, MotionModel $motion): array
	{
		$N = \count($t['xPost']);
		if ($N === 0) {
			throw new InvalidArgument('Empty trajectory');
		}
		$n = $t['n'];
		$xs = $t['xPost'];
		$Ps = $t['PPost'];
		$gains = \array_fill(0, $N, null);

		for ($k = $N - 2; $k >= 0; $k--) {
			$dt = $t['dt'][$k + 1];
			$F = $dt > 0.0 ? $motion->transition($dt) : Flat::identity($n);
			$PPrior = $t['PPrior'][$k + 1];
			// C = P_k Fᵀ (P⁻)⁻¹  ⇔  Cᵀ = (P⁻)⁻¹ F P_k   (P⁻ symmetric)
			$L = Cholesky::decompose($PPrior, $n);
			$FP = Flat::multiply($F, $t['PPost'][$k], $n, $n, $n);           // F P_k
			$Ct = Cholesky::solveMatrix($L, $FP, $n, $n);                   // (P⁻)⁻¹ F P_k
			$C = Flat::transpose($Ct, $n, $n);
			$gains[$k] = $C;

			$dx = Flat::subtract($xs[$k + 1], $t['xPrior'][$k + 1]);
			$xs[$k] = Flat::add($t['xPost'][$k], Flat::matVec($C, $dx, $n, $n));
			$dP = Flat::subtract($Ps[$k + 1], $PPrior);
			$Ps[$k] = Flat::symmetrize(Flat::add($t['PPost'][$k], Flat::sandwich($C, $dP, $n)), $n);
		}

		$lagOne = \array_fill(0, $N, null);
		for ($k = 1; $k < $N; $k++) {
			$C = $gains[$k - 1];
			if ($C !== null) {
				$lagOne[$k] = Flat::multiplyTransposed($Ps[$k], $C, $n, $n, $n);   // P_{k|N} C_{k−1}ᵀ
			}
		}
		return ['mean' => $xs, 'covariance' => $Ps, 'lagOne' => $lagOne];
	}
}
