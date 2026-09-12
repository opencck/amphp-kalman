<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Liquidity;

/**
 * How a level's contribution to liquidity density decays with its distance
 * from the mid.
 *
 * `Rectangular` is the reference behaviour: count a level fully inside the
 * band, ignore it entirely outside. It is an indicator function, so it is
 * discontinuous — a resting order one tick from the boundary flips the reading
 * every time the mid moves a tick, producing steps that look like liquidity
 * events and are not. The smooth kernels remove that artefact.
 */
enum DensityKernel: string
{
	case Rectangular = 'rectangular';
	case Triangular = 'triangular';
	case Exponential = 'exponential';

	/**
	 * Weight for a level at `distanceBps` from the mid, given a band width.
	 * Rectangular and triangular vanish outside the band; the exponential
	 * kernel decays with the band as its scale and is never exactly zero.
	 */
	public function weight(float $distanceBps, float $bandBps): float
	{
		if ($this === self::Exponential) {
			return \exp(-$distanceBps / $bandBps);
		}
		if ($distanceBps > $bandBps) {
			return 0.0;
		}
		return $this === self::Triangular ? 1.0 - $distanceBps / $bandBps : 1.0;
	}
}
