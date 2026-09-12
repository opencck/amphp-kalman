<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Adaptive;

use OpenCCK\Kalman\Domain\Entity\UpdateResult;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * §2.11.4 Sage–Husa recursive noise estimation with exponential forgetting
 * b ∈ (0, 1):  d_k = (1 − b)/(1 − b^k)
 *
 *   R̂_i,k = (1 − d_k)·R̂_i,k−1 + d_k·(ỹ_i² − h P⁻ hᵀ)       floored at r_min (positivity)
 *   q̂_k   = (1 − d_k)·q̂_k−1   + d_k·(ỹ²/s)                 running Q-scale ≈ NIS with forgetting
 *
 * Unlike the windowed IAE it reacts continuously and never needs a buffer.
 */
final class SageHusa
{
	/** @var array<int, float> */
	private array $r;

	/** @var array<int, int> */
	private array $k;

	private float $qScale = 1.0;
	private int $qCount = 0;

	/**
	 * @param array<int, float> $initialR starting R̂ per channel
	 */
	public function __construct(
		array $initialR,
		private readonly float $forgetting = 0.98,
		private readonly float $rMin = 1e-12,
	) {
		if ($initialR === []) {
			throw new InvalidArgument('initialR must not be empty');
		}
		if ($forgetting <= 0.0 || $forgetting >= 1.0) {
			throw new InvalidArgument('forgetting must be in (0, 1)');
		}
		$this->r = \array_values($initialR);
		$this->k = \array_fill(0, \count($this->r), 0);
	}

	/**
	 * @param array<int, float> $variances the r_i the filter used on this step
	 */
	public function record(UpdateResult $result, array $variances): void
	{
		$b = $this->forgetting;
		foreach ($result->outcomes as $o) {
			if ($o->weight <= 0.0 || !isset($variances[$o->channel], $this->r[$o->channel])) {
				continue;
			}
			$c = $o->channel;
			$this->k[$c]++;
			$d = (1.0 - $b) / (1.0 - $b ** $this->k[$c]);
			$sample = $o->innovation * $o->innovation - ($o->innovationVariance - $variances[$c]);
			$r = (1.0 - $d) * $this->r[$c] + $d * $sample;
			$this->r[$c] = $r < $this->rMin ? $this->rMin : $r;

			$this->qCount++;
			$dq = (1.0 - $b) / (1.0 - $b ** $this->qCount);
			$this->qScale = (1.0 - $dq) * $this->qScale + $dq * ($o->innovation * $o->innovation / $o->innovationVariance);
		}
	}

	public function estimatedR(int $channel): float
	{
		return $this->r[$channel];
	}

	/** @return array<int, float> */
	public function estimatedRs(): array
	{
		return $this->r;
	}

	/** Forgetting-weighted NIS: multiply Q by this to restore consistency. */
	public function suggestedQScale(): float
	{
		return $this->qScale;
	}

	public function updates(int $channel): int
	{
		return $this->k[$channel];
	}
}
