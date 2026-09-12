<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

/**
 * Result of one filter step (predict + correct). Created once per step at
 * the API boundary; the raw path (stepRaw) does not create it.
 */
final readonly class UpdateResult
{
	/**
	 * @param list<ChannelOutcome> $outcomes one per channel present in the measurement
	 * @param float $logLikelihood increment of the innovation log-likelihood on this step
	 * @param float $dt seconds elapsed since the previous step (0 for the first)
	 */
	public function __construct(
		public array $outcomes,
		public float $logLikelihood,
		public float $dt,
		public ?int $timestampNs = null,
	) {
	}

	/** Sum of ỹ²/s over accepted channels; E[nis] = number of accepted channels. */
	public function nis(): float
	{
		$sum = 0.0;
		foreach ($this->outcomes as $o) {
			if ($o->weight > 0.0) {
				$sum += $o->nis();
			}
		}
		return $sum;
	}

	public function acceptedCount(): int
	{
		$c = 0;
		foreach ($this->outcomes as $o) {
			if ($o->weight > 0.0) {
				$c++;
			}
		}
		return $c;
	}

	public function rejectedCount(): int
	{
		return \count($this->outcomes) - $this->acceptedCount();
	}

	public function isBlind(): bool
	{
		return $this->outcomes === [];
	}

	public function outcome(int $channel): ?ChannelOutcome
	{
		foreach ($this->outcomes as $o) {
			if ($o->channel === $channel) {
				return $o;
			}
		}
		return null;
	}
}
