<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Filter;

use OpenCCK\Kalman\Domain\Entity\ChannelOutcome;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\UpdateResult;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Linalg\LU;

/**
 * One-step retrodiction for out-of-sequence measurements (§2.7, Bar-Shalom 2002,
 * algorithm "A1" approximation): a measurement z_τ taken at τ < t_k, after the
 * filter has already advanced to t_k, is folded in by mapping the current
 * estimate back to τ, forming the innovation there and updating the current
 * state through the cross-covariance.
 *
 *   x̂_τ = F⁻¹(x̂_k − u),      P_τ  ≈ F⁻¹ (P_k + Q) F⁻ᵀ … (approximation: P_k − Q mapped back)
 *   S    = H P_τ Hᵀ + R,      cross-covariance  P_kτ = P_k F⁻ᵀ
 *   K    = P_kτ Hᵀ S⁻¹,       x̂_k += K(z − H x̂_τ),   P_k -= K S Kᵀ
 *
 * Exact for one step back (lag < dt of the last step); the retrodicted
 * process-noise term is dropped when P_k − F⁻¹ Q F⁻ᵀ is not positive
 * definite. Returns null when the measurement is older than one step.
 *
 * Goes through dense copies of P (rare path). Not for the hot loop.
 */
final class Retrodiction
{
	private function __construct()
	{
	}

	public static function apply(KalmanFilter $filter, Measurement $measurement): ?UpdateResult
	{
		$last = $filter->lastTimestampNs();
		if ($last === null || $measurement->isBlind()) {
			return null;
		}
		$lagNs = $last - $measurement->timestampNs;
		$stepNs = (int) \round($filter->lastDt() * 1e9);
		if ($lagNs <= 0 || $stepNs <= 0 || $lagNs > $stepNs) {
			return null;   // older than one step → caller drops it
		}
		$lag = $lagNs / 1e9;

		$n = $filter->stateSize();
		$motion = $filter->motion();
		$obs = $filter->observation();
		$F = $motion->transition($lag);
		$Q = $motion->processNoise($lag);
		$u = $motion->control($lag);
		$Finv = LU::inverse($F, $n);

		$x = $filter->mean();
		$P = $filter->covariance();

		// state at τ
		$xk = $x;
		if ($u !== null) {
			$xk = Flat::subtract($xk, $u);
		}
		$xTau = Flat::matVec($Finv, $xk, $n, $n);

		// P_τ ≈ F⁻¹ (P_k − Q) F⁻ᵀ ; fall back to F⁻¹ P_k F⁻ᵀ when not PD
		$Pminus = Flat::subtract($P, $Q);
		if (!\OpenCCK\Kalman\Domain\Linalg\Cholesky::isPositiveDefinite($Pminus, $n)) {
			$Pminus = $P;
		}
		$PTau = Flat::sandwich($Finv, $Pminus, $n);
		// cross-covariance between x_k and x_τ: P_kτ = (P_k − Q) F⁻ᵀ
		$Pcross = Flat::multiplyTransposed($Pminus, $Finv, $n, $n, $n);

		$outcomes = [];
		$ll = 0.0;
		foreach ($measurement->values as $channel => $z) {
			$h = Flat::denseRow($obs->channelRow($channel), $n);
			$r = $obs->channelVariance($channel);
			$hx = Flat::dot($h, $xTau);
			$innovation = $z - $hx;
			$PTauH = Flat::matVec($PTau, $h, $n, $n);
			$s = Flat::dot($h, $PTauH) + $r;
			$K = Flat::scale(Flat::matVec($Pcross, $h, $n, $n), 1.0 / $s);
			$x = Flat::add($x, Flat::scale($K, $innovation));
			$P = Flat::symmetrize(Flat::subtract($P, Flat::scale(Flat::outer($K, $K), $s)), $n);
			// keep the τ-state consistent for further channels of the same measurement
			$KTau = Flat::scale($PTauH, 1.0 / $s);
			$xTau = Flat::add($xTau, Flat::scale($KTau, $innovation));
			$PTau = Flat::symmetrize(Flat::subtract($PTau, Flat::scale(Flat::outer($PTauH, $PTauH), 1.0 / $s)), $n);
			$Pcross = Flat::subtract($Pcross, Flat::scale(Flat::outer($K, $PTauH), 1.0));
			$outcomes[] = new ChannelOutcome($channel, $innovation, $s, 1.0);
			$ll -= 0.5 * ($innovation * $innovation / $s + \log($s) + \log(2.0 * \M_PI));
		}

		$filter->reset($x, $P, $last);
		// reset() zeroes the accumulated statistics; restore the clock only —
		// the retrodicted likelihood is reported in the result.
		return new UpdateResult($outcomes, $ll, -$lag, $measurement->timestampNs);
	}
}
