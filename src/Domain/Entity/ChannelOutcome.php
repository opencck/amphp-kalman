<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

/**
 * Result of correcting one scalar channel.
 *
 * weight: 1.0 accepted, 0.0 rejected by the gate, (0, 1) Huber down-weighted.
 * innovationVariance is s = h·P⁻·hᵀ + r BEFORE any robust inflation.
 */
final readonly class ChannelOutcome
{
	public function __construct(
		public int $channel,
		public float $innovation,
		public float $innovationVariance,
		public float $weight,
	) {
	}

	public function accepted(): bool
	{
		return $this->weight > 0.0;
	}

	public function rejected(): bool
	{
		return $this->weight === 0.0;
	}

	/** Normalised innovation squared ỹ²/s ~ χ²(1) under a consistent model. */
	public function nis(): float
	{
		return $this->innovation * $this->innovation / $this->innovationVariance;
	}

	/** Standardised innovation ỹ/√s. */
	public function standardised(): float
	{
		return $this->innovation / \sqrt($this->innovationVariance);
	}
}
