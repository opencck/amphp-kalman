# ADR-010 — The order book is a sorted snapshot, never a map

**Status:** accepted

## Context

The metric layer added a third kind of input alongside ticks and bars: the
order book. Six of its measurements read one — imbalance, liquidity density,
book slope, cost to trade, weighted prices, spread — and two more (order-flow
imbalance, the micro price) read the top of it.

The reference implementation this library was asked to replace kept each side
as a PHP array keyed by price and mutated it in place:

```php
$this->bid[$price] = $volume;          // add or replace a level
unset($this->bid[$price]);             // remove one
$best = array_values(array_slice($this->bid, 0, 1))[0][0];   // "best bid"
```

That last line is the defect. `array_slice($map, 0, 1)` returns the first
element **by insertion order**, which equals price order only until the first
update that adds a level the exchange did not send in the original snapshot.
From then on the "best bid" is an arbitrary resting level. Everything computed
from it inherits the error: the mid price, the top-5 and top-10 volumes, and
the liquidity-density band, which is centred on that mid.

The failure is silent. The numbers stay plausible — they are real levels of a
real book — so nothing crashes and no assertion fires; the metric simply
measures a different thing from the one it is named after, and only a
side-by-side comparison against a correctly sorted book reveals it.
`examples/liquidity-density.php` performs exactly that comparison.

Three shapes were considered for the replacement:

1. **Keep the map, sort on read.** Minimal change, but every metric pays an
   O(k log k) sort per access and nothing stops a caller from reading the map
   directly and reintroducing the bug.
2. **A heap or `SplPriorityQueue` per side.** O(log k) updates and O(1) best,
   but it gives no efficient access to level *i*, which top-k volume, book
   slope and cost-to-trade all need; and it allocates per node, which the
   zero-allocation rule (BRIEF §2 p.13) rules out of a hot path.
3. **An immutable snapshot holding four flat sorted arrays.** Sorting is paid
   once per snapshot, level *i* is an array offset, and the kernels can be
   handed the raw arrays without touching an object at all.

## Decision

1. **`Domain\Entity\OrderBook` is an immutable snapshot with sorted sides.**
   It holds four `list<float>` — bid prices descending, bid sizes, ask prices
   ascending, ask sizes — validated and sorted in the factory. Zero-size levels
   are dropped at construction, so `bidLevels()` counts live levels.
2. **The flat arrays are public.** Every `@offloadable` kernel in
   `Domain\Metric\Liquidity` and `Domain\Metric\Microstructure` takes
   `array $bidPrices, array $bidSizes, array $askPrices, array $askSizes`
   rather than the entity, so a book metric can run in a worker process or
   against a decoded frame with no object graph at all. The entity is a
   convenience over the same data, not a required wrapper.
3. **Incremental updates live in `OrderBookBuilder`.** It keeps the price-keyed
   map for O(1) per-level updates, and pays the sort once in `snapshot()`,
   which caches until the next update. Deltas with size 0 remove the level, as
   exchange protocols specify.
4. **A crossed book is reported, not rejected.** Feeds do cross for microseconds
   during fast markets. `isCrossed()` lets a metric skip the tick; throwing
   would turn a normal market event into an outage.
5. **Degenerate books return NAN, not zero.** An empty side has no best price,
   no mid and no spread. Zero is a number a strategy can act on; NAN is not,
   and that is the point.

## Consequences

- The class of bug the reference had cannot recur: there is no way to ask an
  `OrderBook` for a level by insertion order, because it does not keep one.
- A snapshot costs one sort of each side. At twenty levels that is nothing; at
  the two hundred levels some venues publish it is still far below the cost of
  parsing the frame that delivered them.
- Metrics that only need the touch (`OrderFlowImbalance`) read four scalars and
  never look at the rest of the book.
- `OrderBookBuilder::snapshot()` returning a cached instance means a metric can
  call it per tick without forcing a re-sort, but callers must treat the
  returned snapshot as immutable — which it is, being `readonly`.
