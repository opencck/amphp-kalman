# The asynchronous layer (AMPHP v3)

*Русская версия: [async.ru.md](async.ru.md).*

The filter itself is synchronous PHP: a step is a few microseconds of
arithmetic with no I/O. Everything asynchronous lives *around* it in
`src/Infrastructure` — feeds, queues, sessions, outputs, workers — and never
inside a step. This document explains the topology and the rules that keep it
correct.

## 1. Topology of one process

```
  WS exchange A ─┐
  WS exchange B ─┼─► WebsocketFeed fibers ─► Queue<Measurement> ─► ReorderBuffer ─► FilterSession (owner fiber)
  WS exchange C ─┘         (IngestOrchestrator)                                             │
                                                                                            ├─► Queue<StateSnapshot> ─► SnapshotBroadcaster (WebSocket gateway)
  BarClock (Interval) ──► Measurement::blind ──────────────────────────────────┘           ├─► FilePersister / RedisPersister
                                                                                            └─► ConsistencyMonitor → alerts
```

One event loop, zero blocking calls, one fiber owns the filter. Throughput is
bounded by the arithmetic of a step; the loop adds about 1 µs per tick
(`EventLoopOverheadBench`), or 0 µs with producer-side batching (§5).

## 2. Ingest: WebSocket → Queue with back-pressure

`Infrastructure\Ingest\WebsocketFeed` connects with `Rfc6455Connector`,
receives frames with a `TimeoutCancellation(staleAfterSeconds)` composed with
the external stop cancellation, decodes each frame through a `MessageDecoder`
and pushes the `Measurement` into a shared `Queue`.

- **Back-pressure is free.** If the `FilterSession` falls behind, `push()`
  suspends the feed fiber, the socket stops being read, the TCP window closes
  and the exchange slows down. Nothing is lost, nothing accumulates without
  bound.
- **Stale detection.** A silent connection past `staleAfterSeconds` cancels
  `receive()`; the feed distinguishes "stop requested" from "stale" and
  reconnects after `reconnectDelay`.
- **The decoder is the only JSON boundary.** `JsonTickDecoder` (the library's
  own line format) and the example exchange decoders parse with
  `JSON_THROW_ON_ERROR`, return `null` for heartbeats and acks and MUST take
  the exchange timestamp from the payload — never local time.
  `SbeTickDecoder` is the binary alternative: one `unpack()` per frame, no
  intermediate objects, 3–5× cheaper than JSON (`DecoderBench`).
- The feed never completes the queue: several feeds share it and the
  orchestrator completes it when *all* of them have stopped.

## 3. Orchestrator: several feeds, one queue

`IngestOrchestrator::start()` spawns one fiber per feed, returns an
`IngestHandle` with the queue and a `DeferredCancellation`, and completes the
queue once every pump has finished (`awaitAll`). Forgetting `complete()` is
the classic AMPHP mistake: the session's `foreach ($queue->iterate())` would
never return.

## 4. ReorderBuffer as a Pipeline operator

Out-of-sequence measurements are normal with several venues. The buffer keeps
a window of `W` ns, sorts inside it by `timestampNs` and yields everything
older than `watermark − W`:

```php
foreach ((new ReorderBuffer(windowNs: 2_000_000))->apply($queue->iterate()) as $m) {
    $inbox->push($m);
}
```

`W` trades latency for accuracy: 1–5 ms in co-location, 50–200 ms on retail
APIs. A measurement later than `W` still reaches the filter with `dt < 0` and
is handled by the session's `OutOfSequencePolicy`.

## 5. FilterSession — the single owner

`Infrastructure\Async\FilterSession` reads `Measurement | Command` from its
inbox and executes them strictly in order:

- `Measurement` → out-of-sequence check → **atomic step** (`predict` +
  `correct`, no `await` in between) → monitor → every `snapshotEvery`-th tick a
  `StateSnapshot` is pushed to the snapshot queue (that push may suspend; the
  filter state is already consistent).
- `SnapshotRequest` → completes its future with the state *exactly after the
  measurement that precedes it in the inbox* — linearised snapshots.
- `CorporateAction` (`Split`, `Dividend`, `Rebalance`, `Reset`) → applied
  between steps, in order.

Out-of-sequence policies: `Drop` (default, counted), `Retrodict` (one-step
exact retrodiction), `Fail` (throws — for tests and strict pipelines).

**Batching (§6.5 of the design), two levels.**

*Producer side — the one that matters.* `MeasurementBatcher::pump($source, $inbox)`
drains the source with a helper fiber and pushes everything that accumulated
while the previous push was in flight as ONE array item; the session accepts
`array<Measurement>` natively. One `Queue::push/iterate` pair (~1 µs) is then
paid per batch, not per tick: measured loop overhead per tick drops from
0.93 µs to 0.0 µs (`EventLoopOverheadBench`). Under load batches grow towards
`maxBatch`; at low load a batch is one measurement, so latency is unchanged.
Commands are forwarded individually at their position in the stream, so
snapshot linearisation holds.

*Consumer side.* With `batchSize > 0` the session itself drains its inbox into
an array and processes it in one wake-up. This only amortises the owner's
fiber switch (~0.1–0.25 µs per tick); the per-item queue cost remains, which
is why the producer-side batcher exists.

Both preserve back-pressure (the drain fibers stop at the cap) and order.

```php
$session = new FilterSession($filter, $inbox, $snapshots, $monitor, snapshotEvery: 100);
$final = $session->start();          // Future<StateSnapshot>, completes when the inbox completes

async(static function () use ($ordered, $inbox): void {
    (new MeasurementBatcher(256))->pump($ordered, $inbox);   // $ordered: ReorderBuffer output or any iterable
    $inbox->complete();
});
```

## 6. Bar clock: prediction without measurements

When data stops (night, halts) `P` must keep growing honestly and consumers
must see the widening uncertainty. `BarClock` uses `Amp\Interval` with
`weakClosure` (otherwise the interval holds the clock and the clock holds the
interval — a leak) and pushes `Measurement::blind(ts)` into the inbox.

The timestamp comes from a `Clock` abstraction (`SystemClock::realtimeNs()`,
`ManualClock` in tests) — `CLOCK_REALTIME` nanoseconds, never `hrtime()`,
because exchange timestamps are wall-clock and the two must be comparable.

## 7. Serving estimates: WebSocket gateway and HTTP

`SnapshotBroadcaster` consumes the snapshot queue and calls
`WebsocketClientGateway::broadcastText()` with `SnapshotSerializer` output —
non-blocking, a slow client does not stall the others. The push-only
`StateWebsocketHandler` drains incoming frames (the buffer-deadlock rule).
`HttpStateHandler` serves `GET /state`: it calls
`$session->requestSnapshot()->await()` — an I/O boundary where `await` belongs.

## 8. Persisting snapshots

`FilePersister` writes through a temporary file and `File\move()` (atomic
replace); `RedisPersister` keeps the last state under a key. Recovery is
`FilterFactory::fromConfig($model, $config, $snapshot)`: `x, P` and
`lastTimestampNs` are restored, and the first step after a restart predicts
over the real gap, so `P` is honestly inflated.

## 9. Scaling to cores: amphp/parallel

One PHP process is one core. The unit of distribution is a **group of
instruments sharing state** (one ETF basket, one pair, one multi-venue asset);
groups are independent and can live in different processes.

`FilterWorkerTask` owns one filter in a worker: it rebuilds the model *inside*
`run()` from a serialisable config (no closures, no connections cross the
boundary), receives **batches** of raw ticks through the IPC channel, runs the
same atomic `stepRaw()` and sends compact snapshots back every
`snapshotEvery` ticks. The stream ends with a `null` sentinel; the task
returns the final snapshot.

`WorkerSession` is the parent side: `Batcher` groups measurements (256 by
default), a receiver fiber forwards compact snapshots into a `Queue`.

**IPC cost.** A `Channel::send` is 20–50 µs — ten times a filter step. Hence
batches of 64–512 ticks (`IpcBench`: 26 µs/tick at batch 1, 2.3 µs at 64+),
rare snapshots, and no worker at all for groups under ~5 k ticks/s.

**Engine determinism.** Worker results are bit-identical to in-process results
only when parent and children run the same engine configuration. The default
`workerPool()` spawns children with the plain binary and php.ini — possibly
Xdebug on, JIT off, or a different JIT mode. Build production pools with
`WorkerPools::likeParent($limit)`, which replays the parent's extension
directory, extensions, JIT flags, assertions and memory limit. See ADR-007 for
the PHP 8.2/8.3 JIT miscompilations the library works around and ADR-008 for the
shared OPcache segment of Windows CLI processes.

## 10. Parallel calibration

Each simplex point is an independent filter run over the same history — a
perfect fan-out. `LikelihoodTask` receives a *path* (workers read the history
once and cache it per process) or an in-memory tick list for small cases;
`ParallelCalibrator` submits every candidate batch of the optimiser
concurrently and awaits them together. A single Nelder–Mead simplex offers
only 4 points per iteration, which caps the speed-up near 2–3× regardless of
the pool size; `MultiStartNelderMead(starts: K)` advances K simplexes in
lockstep and hands out 4K points per round — 4.5× evaluation throughput on 8
workers in `CalibrationBench`, and a search that is robust to local optima.
Independent evaluations scale with the physical core count (`ScalingBench`).

File I/O inside a worker uses the **blocking** filesystem driver on purpose:
the default `ParallelFilesystemDriver` would spawn a nested worker pool inside
every worker (slow start-up, lingering children that stall the parent's
shutdown on Windows), and a dedicated worker has nothing else to do while it
reads.

## 11. Backtests from files

`HistoryReader::stream()` reads JSON lines (or CSV) through `Amp\File` and
`splitLines`, decompressing `.gz` on the fly; `readAll()` for small
histories. Ticks may carry per-tick observation rows (`rows`) so time-varying
regression models cross process boundaries exactly. For smoothing the
trajectory is recorded by `FilterTrajectory` and spilled to
`TrajectoryFile` when it exceeds the memory cap.

## 12. Graceful shutdown

Order matters: stop the sources, complete the inbox, await the owner, flush
the persister.

```php
$signal = trapSignal([\SIGINT, \SIGTERM]);   // ext-pcntl; on Windows fall back to EventLoop::run()
$handle->stop();                             // feeds leave receive()
$inbox->complete();                          // the session drains its buffer and returns
$final = $sessionFuture->await();            // final snapshot
$persister->flush();
```

The reverse order loses ticks that were still buffered.

## 13. Testing the async layer

All async tests extend `Amp\PHPUnit\AsyncTestCase` and run with
`zend.assertions=1`:

- `FilterSessionTest` — shuffled ticks through `ReorderBuffer` give monotone
  `dt` and the same result as an in-order run; a snapshot requested between two
  ticks reflects exactly the first; batched and plain sessions are bit-identical.
- `MeasurementBatcherTest` — arrays of measurements give the same state with far
  fewer queue items; commands keep their position.
- `WebsocketFeedTest` — a mock exchange (`tests/Support/MockExchangeServer`)
  that goes silent triggers a reconnect; stop interrupts a pending receive.
- `IngestOrchestratorTest` — the queue completes when all feeds stop.
- `WorkerTest` (`@group slow`) — batched IPC results equal in-process results
  bit for bit.
- `BarClockTest` — blind steps grow the uncertainty.
- `PersistenceTest`, `HttpStateHandlerTest`, `TrajectoryFileTest`.

## 14. AMPHP pitfalls checklist

| Mistake | How it would show up here |
|---|---|
| `await()` outside a fiber | `requestSnapshot()->await()` from a synchronous benchmark |
| blocking I/O in the loop | `file_put_contents` in a persister; `usleep` in a backtest "for realism" |
| `Queue::complete()` never called | `FilterSession::run()` never leaves its `foreach` |
| `Channel::receive()` in `while ($x = …)` | a worker dies with an unhandled exception on normal completion (use the null sentinel) |
| arrow function captures by value | a processed-tick counter that stays 0 |
| `Interval` without `weakClosure` | `BarClock` is never collected; the process leaks |
| `hrtime()` instead of exchange time | `dt` does not match the data; `Q` is wrong |
| `await` inside a step | corrupted state under concurrent snapshot requests |
| nested worker pools (Amp\File inside a worker) | 10-second start-ups, parents that never exit |
