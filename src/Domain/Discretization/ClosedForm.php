<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Discretization;

/**
 * Closed-form F(dt), Q(dt) for the standard continuous-time kinematic and
 * mean-reverting models (§2.4). All results are exactly symmetric and agree
 * with {@see VanLoan} to ~1e-12 (verified by tests).
 */
final class ClosedForm
{
	private function __construct()
	{
	}

	/**
	 * Random walk dx = σ dβ.
	 *
	 * @deterministic
	 * @offloadable
	 * @return array{F: array<int, float>, Q: array<int, float>}
	 */
	public static function randomWalk(float $sigma, float $dt): array
	{
		return ['F' => [1.0], 'Q' => [$sigma * $sigma * $dt]];
	}

	/**
	 * Constant velocity (white-noise acceleration): x = [p, v].
	 *
	 * @deterministic
	 * @offloadable
	 * @return array{F: array<int, float>, Q: array<int, float>}
	 */
	public static function constantVelocity(float $sigmaA, float $dt): array
	{
		$q = $sigmaA * $sigmaA;
		$dt2 = $dt * $dt;
		$q12 = $q * $dt2 * 0.5; // constant LAST in reused products (ADR-007)
		return [
			'F' => [1.0, $dt, 0.0, 1.0],
			'Q' => [$q * $dt2 * $dt / 3.0, $q12, $q12, $q * $dt],
		];
	}

	/**
	 * Constant acceleration (white-noise jerk): x = [p, v, a].
	 *
	 * @deterministic
	 * @offloadable
	 * @return array{F: array<int, float>, Q: array<int, float>}
	 */
	public static function constantAcceleration(float $sigmaJ, float $dt): array
	{
		$q = $sigmaJ * $sigmaJ;
		$dt2 = $dt * $dt;
		$dt3 = $dt2 * $dt;
		$dt4 = $dt3 * $dt;
		$dt5 = $dt4 * $dt;
		$q01 = $q * $dt4 / 8.0;
		$q02 = $q * $dt3 / 6.0;
		$q12 = $q * $dt2 / 2.0;
		return [
			'F' => [1.0, $dt, 0.5 * $dt2, 0.0, 1.0, $dt, 0.0, 0.0, 1.0],
			'Q' => [
				$q * $dt5 / 20.0, $q01, $q02,
				$q01, $q * $dt3 / 3.0, $q12,
				$q02, $q12, $q * $dt,
			],
		];
	}

	/**
	 * Ornstein–Uhlenbeck dx = −θ(x − μ)dt + σ dβ. Also returns the control
	 * term u = μ(1 − e^{−θdt}) so that x_k = F x_{k-1} + u.
	 *
	 * @deterministic
	 * @offloadable
	 * @return array{F: array<int, float>, Q: array<int, float>, u: array<int, float>}
	 */
	public static function ornsteinUhlenbeck(float $theta, float $mu, float $sigma, float $dt): array
	{
		if ($theta === 0.0) {
			return ['F' => [1.0], 'Q' => [$sigma * $sigma * $dt], 'u' => [0.0]];
		}
		$e = \exp(-$theta * $dt);
		return [
			'F' => [$e],
			'Q' => [$sigma * $sigma / (2.0 * $theta) * (1.0 - $e * $e)],
			'u' => [$mu * (1.0 - $e)],
		];
	}

	/**
	 * Singer / integrated OU: position with exponentially correlated velocity,
	 * dv = −(1/τ) v dt + σ dβ. x = [p, v].
	 *
	 *   F = [1  τ(1−e); 0  e],  e = exp(−dt/τ)
	 *   Q_vv = σ²τ/2 (1−e²)
	 *   Q_pv = σ²τ² [(1−e) − (1−e²)/2]
	 *   Q_pp = σ²τ² [dt − 2τ(1−e) + τ(1−e²)/2]
	 *
	 * @deterministic
	 * @offloadable
	 * @return array{F: array<int, float>, Q: array<int, float>}
	 */
	public static function singer(float $sigma, float $tau, float $dt): array
	{
		$a = $dt / $tau;                 // dimensionless step
		$e = \exp(-$a);
		$s2 = $sigma * $sigma;
		$oneMinusE = -\expm1(-$a);       // 1 − e, accurate for small a
		$oneMinusE2 = -\expm1(-2.0 * $a); // 1 − e²

		// g(a) = a − 2(1−e) + (1−e²)/2 = a³/3 − a⁴/4 + …   (Q_pp = σ²τ³·g)
		// h(a) = (1−e) − (1−e²)/2      = a²/2 − a³/2 + …   (Q_pv = σ²τ²·h)
		// Direct evaluation cancels catastrophically for a ≪ 1 → power series there.
		if ($a < 0.05) {
			$g = 0.0;
			$h = 0.0;
			$term = $a;            // a^k / k!
			$pow2 = 1.0;           // 2^(k−1)
			for ($k = 1; $k <= 16; $k++) {
				if ($k > 1) {
					$term *= $a / $k;
					$pow2 *= 2.0;
				}
				$sign = ($k % 2 === 1) ? 1.0 : -1.0;   // (−1)^(k+1)
				$h += $sign * (1.0 - $pow2) * $term;
				$g += $sign * ($pow2 - 2.0) * $term;
			}
			$g += $a; // the k=1 term of g is −a, plus the leading a
		} else {
			$g = $a - 2.0 * $oneMinusE + 0.5 * $oneMinusE2;
			$h = $oneMinusE - 0.5 * $oneMinusE2;
		}
		$tau2 = $tau * $tau;
		$qvv = $s2 * $tau * $oneMinusE2 * 0.5; // constant LAST (ADR-007)
		$qpv = $s2 * $tau2 * $h;
		$qpp = $s2 * $tau2 * $tau * $g;
		return [
			'F' => [1.0, $tau * $oneMinusE, 0.0, $e],
			'Q' => [$qpp, $qpv, $qpv, $qvv],
		];
	}
}
