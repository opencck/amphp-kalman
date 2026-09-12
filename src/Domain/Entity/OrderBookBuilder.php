<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

/**
 * Accumulates incremental order-book deltas and hands out sorted snapshots.
 *
 * Exchanges publish a snapshot followed by a stream of per-level updates,
 * where size 0 means "remove this level". Keeping a map and reading its first
 * element is what breaks (see `OrderBook`); this builder keeps the map for
 * O(1) updates and pays the sort once, when a snapshot is actually taken.
 * `snapshot()` caches, so taking it twice without an intervening update costs
 * nothing.
 */
final class OrderBookBuilder
{
	/**
	 * Keyed by price. The key is written as a string, but PHP silently turns
	 * an integer-valued one back into an int, so the key type is array-key —
	 * which is exactly why `OrderBook` never reads a level out of a map like
	 * this by position.
	 *
	 * @var array<array-key, float> price => size
	 */
	private array $bids = [];

	/** @var array<array-key, float> price => size */
	private array $asks = [];

	private int $timestampNs = 0;

	private ?OrderBook $cached = null;

	/** Replaces the whole book, as an exchange snapshot frame does.
	 *
	 * @param list<array{0: float|int, 1: float|int}> $bids
	 * @param list<array{0: float|int, 1: float|int}> $asks
	 */
	public function reset(int $timestampNs, array $bids = [], array $asks = []): void
	{
		$this->bids = [];
		$this->asks = [];
		$this->cached = null;
		$this->timestampNs = $timestampNs;
		foreach ($bids as [$price, $size]) {
			$this->apply(true, (float) $price, (float) $size);
		}
		foreach ($asks as [$price, $size]) {
			$this->apply(false, (float) $price, (float) $size);
		}
	}

	/** One delta on the bid side; size 0 removes the level. */
	public function bid(float $price, float $size): void
	{
		$this->apply(true, $price, $size);
	}

	/** One delta on the ask side; size 0 removes the level. */
	public function ask(float $price, float $size): void
	{
		$this->apply(false, $price, $size);
	}

	public function touch(int $timestampNs): void
	{
		$this->timestampNs = $timestampNs;
		$this->cached = null;
	}

	private function apply(bool $isBid, float $price, float $size): void
	{
		$key = (string) $price;
		if ($isBid) {
			if ($size <= 0.0) {
				unset($this->bids[$key]);
			} else {
				$this->bids[$key] = $size;
			}
		} elseif ($size <= 0.0) {
			unset($this->asks[$key]);
		} else {
			$this->asks[$key] = $size;
		}
		$this->cached = null;
	}

	/** Sorted snapshot of the current state; cached until the next update. */
	public function snapshot(): OrderBook
	{
		return $this->cached ??= OrderBook::fromMap($this->timestampNs, $this->bids, $this->asks);
	}

	public function bidLevelCount(): int
	{
		return \count($this->bids);
	}

	public function askLevelCount(): int
	{
		return \count($this->asks);
	}
}
