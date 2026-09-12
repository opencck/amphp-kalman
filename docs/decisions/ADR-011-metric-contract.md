# ADR-011 — One definition per metric, exercised two ways

**Status:** accepted

## Context

Every measurement in the metric layer has to work in two settings that pull in
opposite directions:

- **Live.** One observation at a time, O(1), no allocation per tick, inside a
  filter session that must not stall.
- **Batch.** A whole history at once, inside a backtest or on an
  `amphp/parallel` worker, where the input and the output have to survive
  `serialize()` across a process boundary (ADR-005).

The obvious implementation is to write both: a streaming class and a static
kernel over arrays. That is what most indicator libraries do, and it is how the
two drift apart. The two paths warm up differently, round differently, and
handle the degenerate cases differently, so a signal that backtested well
behaves differently in production — the most expensive class of bug this
library can ship, because it surfaces as lost money rather than as a failing
test.

The alternative — only the streaming object, with batch callers looping — costs
a virtual call per element and cannot cross a process boundary, since ADR-005
requires offloadable work to take and return plain arrays.

## Decision

1. **`Domain\Metric\Contract\Metric` is the shape of a measurement:**
   `type()`, `describe()`, `isReady()`, `value()`, `values()`, `reset()`,
   `toArray()`, `fromArray()`. Input arrives through one of four sub-contracts
   — `PriceMetric`, `TradeMetric`, `BarMetric`, `BookMetric` — because a metric
   that needs the intrabar range cannot be fed ticks, and the type system
   should say so rather than a docblock.

2. **The kernels drive the streaming object.** A kernel constructs the metric,
   feeds the series through it and collects the outputs. There is one
   definition of each formula, exercised two ways, so the live and batch paths
   cannot disagree by construction. The cost is one object and one method call
   per element in batch mode, which is immaterial next to the arithmetic and
   next to the serialisation that surrounds a worker call.

   Where a batch algorithm is genuinely different from a recursive one — a
   regression over a whole sample, say — the kernel implements it directly and
   says so.

3. **Correctness comes from reference tests, not from an equivalence test.**
   Since the two paths share an implementation, checking them against each
   other proves nothing. Instead every metric is checked against an independent
   naive implementation written inside the test, to 1e-9, which is the project
   rule already in force for every numerical algorithm (BRIEF §2 p.8). Where
   published values exist, they are pinned as well.

4. **`isReady()` is part of the contract, and `value()` returns NAN until it is
   true.** Silently returning zero during warm-up is how an indicator produces
   a trade on its first bar. NAN propagates, is visible in output, and cannot
   be mistaken for a reading.

5. **Degenerate inputs resolve explicitly.** A flat window has no RSI by the
   formula (0/0) — it returns 50. A zero-range window has no stochastic — it
   returns 50. A book with no bid has no mid — NAN. Each such case is decided
   in the code with a comment saying why, never by whatever a division happens
   to produce.

6. **Multi-output metrics return a shape.** `value()` is the primary output;
   `values()` returns every one, keyed, with a precise array shape in the
   docblock so a static analyser can check the keys a caller indexes.

7. **Every metric is serialisable and registered.** `MetricRegistry` maps
   `type()` to the class, so a metric configuration crosses a process boundary
   the way a model config does through `ModelRegistry`, and `describe()`
   supplies the catalogue card that generates the tables in `examples/README*`.

## Consequences

- Adding a metric means writing one implementation, not two, and getting the
  batch path free.
- A backtest and a live session compute identical numbers from identical input.
  That is now a structural property, not a thing to re-verify.
- `MetricRegistry::describeAll()` is complete by construction, so the published
  catalogue cannot omit a metric that exists or list one that does not.
- Batch throughput is bounded by the streaming implementation. If a metric ever
  becomes hot enough for that to matter, the fix is a specialised kernel plus a
  test pinning it against the streaming object — the situation this ADR avoids
  by default, entered deliberately with evidence rather than by accident.
