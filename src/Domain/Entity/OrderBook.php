<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * An order-book snapshot with **sorted sides**: bids descending, asks
 * ascending, both held as flat `list<float>` of prices and sizes so that a
 * kernel can be handed the raw arrays and run without touching an object.
 *
 * Sorting is the invariant, and it is not a detail. The reference
 * implementation this library replaces kept each side as a map keyed by price
 * and mutated it in place, then read the best bid with
 * `array_slice($bid, 0, 1)` — which returns the first element *by insertion
 * order*. After the first update that adds a level, insertion order and price
 * order have nothing to do with each other, so the mid price, the top-5
 * volumes and the whole liquidity-density figure were computed around an
 * arbitrary level (ROADMAP §4.5 M-17, defect 1). Sorting once, at
 * construction, makes that class of bug impossible.
 *
 * Levels with zero size are dropped, so `levels()` is the count of live
 * levels. A crossed book is not rejected — feeds do cross for a few
 * microseconds — but `isCrossed()` reports it so a metric can skip the tick.
 */
final readonly class OrderBook
{
	/**
	 * @param list<float> $bidPrices descending
	 * @param list<float> $bidSizes aligned with $bidPrices
	 * @param list<float> $askPrices ascending
	 * @param list<float> $askSizes aligned with $askPrices
	 */
	private function __construct(
		public int $timestampNs,
		public array $bidPrices,
		public array $bidSizes,
		public array $askPrices,
		public array $askSizes,
	) {
	}

	/**
	 * @param list<array{0: float|int, 1: float|int}> $bids [price, size] pairs, any order
	 * @param list<array{0: float|int, 1: float|int}> $asks [price, size] pairs, any order
	 */
	public static function of(int $timestampNs, array $bids, array $asks): self
	{
		[$bidPrices, $bidSizes] = self::normalise($bids, true);
		[$askPrices, $askSizes] = self::normalise($asks, false);
		return new self($timestampNs, $bidPrices, $bidSizes, $askPrices, $askSizes);
	}

	/**
	 * Builds from the price-keyed maps most exchange payloads decode into.
	 *
	 * @param array<array-key, float|int> $bids price => size
	 * @param array<array-key, float|int> $asks price => size
	 */
	public static function fromMap(int $timestampNs, array $bids, array $asks): self
	{
		return self::of($timestampNs, self::pairs($bids), self::pairs($asks));
	}

	/**
	 * @param array<array-key, float|int> $map
	 * @return list<array{0: float, 1: float}>
	 */
	private static function pairs(array $map): array
	{
		$out = [];
		foreach ($map as $price => $size) {
			$out[] = [(float) $price, (float) $size];
		}
		return $out;
	}

	/**
	 * @param list<array{0: float|int, 1: float|int}> $levels
	 * @return array{0: list<float>, 1: list<float>}
	 */
	private static function normalise(array $levels, bool $descending): array
	{
		$clean = [];
		foreach ($levels as $level) {
			$price = (float) $level[0];
			$size = (float) $level[1];
			if (!\is_finite($price) || $price <= 0.0) {
				throw new InvalidArgument(\sprintf('Order-book price must be finite and > 0, got %s', (string) $price));
			}
			if (!\is_finite($size) || $size < 0.0) {
				throw new InvalidArgument(\sprintf('Order-book size must be finite and >= 0, got %s', (string) $size));
			}
			if ($size === 0.0) {
				continue;
			}
			$clean[] = [$price, $size];
		}
		\usort(
			$clean,
			$descending
				? static fn (array $a, array $b): int => $b[0] <=> $a[0]
				: static fn (array $a, array $b): int => $a[0] <=> $b[0],
		);
		$prices = [];
		$sizes = [];
		foreach ($clean as [$price, $size]) {
			$prices[] = $price;
			$sizes[] = $size;
		}
		return [$prices, $sizes];
	}

	public function bidLevels(): int
	{
		return \count($this->bidPrices);
	}

	public function askLevels(): int
	{
		return \count($this->askPrices);
	}

	public function isEmpty(): bool
	{
		return $this->bidPrices === [] || $this->askPrices === [];
	}

	public function isCrossed(): bool
	{
		return !$this->isEmpty() && $this->bidPrices[0] >= $this->askPrices[0];
	}

	public function bestBid(): float
	{
		return $this->bidPrices === [] ? \NAN : $this->bidPrices[0];
	}

	public function bestAsk(): float
	{
		return $this->askPrices === [] ? \NAN : $this->askPrices[0];
	}

	public function bestBidSize(): float
	{
		return $this->bidSizes === [] ? \NAN : $this->bidSizes[0];
	}

	public function bestAskSize(): float
	{
		return $this->askSizes === [] ? \NAN : $this->askSizes[0];
	}

	/** Arithmetic mid; NAN when either side is empty. */
	public function mid(): float
	{
		return $this->isEmpty() ? \NAN : ($this->bidPrices[0] + $this->askPrices[0]) / 2.0;
	}

	/**
	 * Size-weighted mid (the micro price of the top of book): the larger the
	 * bid size, the closer fair value sits to the ask. See the `Microprice`
	 * model for the filtered version of the same idea.
	 */
	public function weightedMid(): float
	{
		if ($this->isEmpty()) {
			return \NAN;
		}
		$qb = $this->bidSizes[0];
		$qa = $this->askSizes[0];
		$total = $qb + $qa;
		if ($total <= 0.0) {
			return $this->mid();
		}
		return ($this->askPrices[0] * $qb + $this->bidPrices[0] * $qa) / $total;
	}

	public function spread(): float
	{
		return $this->isEmpty() ? \NAN : $this->askPrices[0] - $this->bidPrices[0];
	}

	/** Quoted spread relative to the mid, in basis points. */
	public function relativeSpreadBps(): float
	{
		$mid = $this->mid();
		if (\is_nan($mid) || $mid <= 0.0) {
			return \NAN;
		}
		return ($this->askPrices[0] - $this->bidPrices[0]) / $mid * 10000.0;
	}

	/** @param int $levels 0 for the whole side */
	public function bidVolume(int $levels = 0): float
	{
		return self::sum($this->bidSizes, $levels);
	}

	/** @param int $levels 0 for the whole side */
	public function askVolume(int $levels = 0): float
	{
		return self::sum($this->askSizes, $levels);
	}

	/** @param list<float> $sizes */
	private static function sum(array $sizes, int $levels): float
	{
		$n = \count($sizes);
		if ($levels > 0 && $levels < $n) {
			$n = $levels;
		}
		$sum = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$sum += $sizes[$i];
		}
		return $sum;
	}

	/** @return array{ts: int, bids: list<array{0: float, 1: float}>, asks: list<array{0: float, 1: float}>} */
	public function toArray(): array
	{
		$bids = [];
		foreach ($this->bidPrices as $i => $price) {
			$bids[] = [$price, $this->bidSizes[$i]];
		}
		$asks = [];
		foreach ($this->askPrices as $i => $price) {
			$asks[] = [$price, $this->askSizes[$i]];
		}
		return ['ts' => $this->timestampNs, 'bids' => $bids, 'asks' => $asks];
	}

	/** @param array{ts: int, bids: list<array{0: float|int, 1: float|int}>, asks: list<array{0: float|int, 1: float|int}>} $data */
	public static function fromArray(array $data): self
	{
		return self::of($data['ts'], $data['bids'], $data['asks']);
	}
}
