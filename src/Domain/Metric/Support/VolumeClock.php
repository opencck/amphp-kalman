<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Support;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Volume time: the tape is cut into buckets of equal traded volume instead of
 * equal elapsed time (ROADMAP §4.9 M-30).
 *
 * This is the clock VPIN runs on. Its point is that information arrives with
 * volume, not with the wall clock: a quiet hour and a violent minute can hold
 * the same number of contracts, and in volume time they are the same length.
 * A trade that overflows the current bucket is split across bucket boundaries,
 * so bucket totals are exact regardless of trade size.
 *
 * The completed buckets' signed imbalances are kept in a ring of `buckets`
 * entries; nothing is allocated per trade.
 */
final class VolumeClock
{
	private RingBuffer $imbalances;

	private float $buy = 0.0;

	private float $sell = 0.0;

	private float $filled = 0.0;

	private int $completed = 0;

	public function __construct(public readonly float $bucketSize, int $buckets)
	{
		if (!($bucketSize > 0.0)) {
			throw new InvalidArgument('VolumeClock bucket size must be > 0');
		}
		$this->imbalances = new RingBuffer($buckets);
	}

	/**
	 * Adds one trade, split across buckets when it overflows.
	 *
	 * @param float $volume total size of the trade, >= 0
	 * @param float $buyVolume the part classified as buyer-initiated, 0 <= buyVolume <= volume
	 * @return int how many buckets this trade completed
	 */
	public function add(float $volume, float $buyVolume): int
	{
		if ($volume <= 0.0) {
			return 0;
		}
		$buyFraction = $buyVolume / $volume;
		$remaining = $volume;
		$completed = 0;
		while ($remaining > 0.0) {
			$room = $this->bucketSize - $this->filled;
			$take = $remaining < $room ? $remaining : $room;
			$takeBuy = $take * $buyFraction;
			$this->buy += $takeBuy;
			$this->sell += $take - $takeBuy;
			$this->filled += $take;
			$remaining -= $take;
			// `$take >= $room` and not `filled >= bucketSize`: the sum of the
			// parts need not round back to the bucket size, and comparing sums
			// would leave a sliver of room and spin.
			if ($take >= $room) {
				$this->imbalances->push($this->buy - $this->sell);
				$this->buy = 0.0;
				$this->sell = 0.0;
				$this->filled = 0.0;
				$this->completed++;
				$completed++;
			}
		}
		return $completed;
	}

	/** Signed buy-minus-sell imbalances of the completed buckets, oldest first. */
	public function imbalances(): RingBuffer
	{
		return $this->imbalances;
	}

	/** Total buckets completed since construction or the last reset. */
	public function completedBuckets(): int
	{
		return $this->completed;
	}

	/** How full the bucket in progress is, in volume units. */
	public function pending(): float
	{
		return $this->filled;
	}

	public function reset(): void
	{
		$this->imbalances->reset();
		$this->buy = 0.0;
		$this->sell = 0.0;
		$this->filled = 0.0;
		$this->completed = 0;
	}
}
