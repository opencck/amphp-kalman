<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Diagnostics;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Exception\NumericalFailure;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * §2.10 discrete algebraic Riccati equation by fixed-point iteration of the
 * predict/correct recursion (Joseph form for the correction):
 *
 *   P⁻ = F·P⁺·Fᵀ + Q,   K = P⁻Hᵀ(HP⁻Hᵀ + R)⁻¹,   P⁺ = (I−KH)P⁻(I−KH)ᵀ + KRKᵀ
 *
 * Converges for detectable (F, H) and stabilisable (F, Q^{1/2}). Returns the
 * steady-state prior covariance, gain and posterior covariance.
 */
final class SteadyStateSolver
{
	private function __construct()
	{
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $F n×n
	 * @param array<int, float> $Q n×n
	 * @param array<int, float> $H m×n
	 * @param array<int, float> $R m×m
	 * @return array{P: array<int, float>, K: array<int, float>, posterior: array<int, float>, iterations: int}
	 */
	public static function dare(array $F, array $Q, array $H, array $R, int $n, int $m, float $tolerance = 1e-12, int $maxIterations = 100000): array
	{
		$P = $Q;
		$I = Flat::identity($n);
		for ($iter = 1; $iter <= $maxIterations; $iter++) {
			$PHt = Flat::multiplyTransposed($P, $H, $n, $n, $m);                 // n×m
			$S = Flat::add(Flat::multiply($H, $PHt, $m, $n, $m), $R);           // m×m
			$L = Cholesky::decompose(Flat::symmetrize($S, $m), $m);
			// K = P Hᵀ S⁻¹  ⇔  Kᵀ = S⁻¹ (P Hᵀ)ᵀ
			$Kt = Cholesky::solveMatrix($L, Flat::transpose($PHt, $n, $m), $m, $n); // m×n
			$K = Flat::transpose($Kt, $m, $n);                                  // n×m
			$IKH = Flat::subtract($I, Flat::multiply($K, $H, $n, $m, $n));
			$posterior = Flat::add(
				Flat::sandwich($IKH, $P, $n),
				Flat::multiplyTransposed(Flat::multiply($K, $R, $n, $m, $m), $K, $n, $m, $n),
			);
			$posterior = Flat::symmetrize($posterior, $n);
			$next = Flat::add(Flat::sandwich($F, $posterior, $n), $Q);
			$diff = Flat::maxAbsDiff($next, $P);
			$P = $next;
			if ($diff <= $tolerance * \max(1.0, Flat::maxAbs($P))) {
				return ['P' => $P, 'K' => $K, 'posterior' => $posterior, 'iterations' => $iter];
			}
		}
		throw new NumericalFailure(\sprintf('Riccati iteration did not converge in %d iterations', $maxIterations));
	}

	/**
	 * DARE for model objects at a fixed dt.
	 *
	 * @return array{P: array<int, float>, K: array<int, float>, posterior: array<int, float>, iterations: int, H: array<int, float>, F: array<int, float>}
	 */
	public static function forModels(MotionModel $motion, ObservationModel $observation, float $dt, float $tolerance = 1e-12): array
	{
		$n = $motion->stateSize();
		$m = $observation->channelCount();
		$H = [];
		$Rdiag = [];
		for ($c = 0; $c < $m; $c++) {
			foreach (Flat::denseRow($observation->channelRow($c), $n) as $v) {
				$H[] = $v;
			}
			$Rdiag[] = $observation->channelVariance($c);
		}
		$F = $motion->transition($dt);
		$result = self::dare($F, $motion->processNoise($dt), $H, Flat::diagonal($Rdiag), $n, $m, $tolerance);
		$result['H'] = $H;
		$result['F'] = $F;
		return $result;
	}
}
