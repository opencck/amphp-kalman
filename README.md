# opencck/amphp-kalman

[![CI](https://github.com/opencck/amphp-kalman/actions/workflows/ci.yml/badge.svg)](https://github.com/opencck/amphp-kalman/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/opencck/amphp-kalman.svg)](https://packagist.org/packages/opencck/amphp-kalman)
[![PHP](https://img.shields.io/packagist/dependency-v/opencck/amphp-kalman/php.svg)](https://www.php.net/)
[![License](https://img.shields.io/packagist/l/opencck/amphp-kalman.svg)](LICENSE)

*Русская версия: [README.ru.md](README.ru.md).*

High-performance n-dimensional discrete Kalman filter for PHP 8.2+, with a
catalogue of market-data models and an asynchronous streaming layer on
AMPHP v3 / Revolt.

- **Numerically stable forms** — sequential scalar correction (default),
  Joseph, UD (Bierman–Thornton), square-root (Potter + Householder); no matrix
  inversion anywhere in production code; exact symmetry by construction.
- **Time from the data** — `dt` is derived from exchange timestamps, `F(dt)`
  and `Q(dt)` come from exact continuous-time discretisation (closed forms or
  Van Loan).
- **Diagnostics built in** — χ² / σ / Huber gating, NIS/NEES consistency,
  innovation whiteness, observability, model-break detection.
- **Everything around the step is async** — WebSocket ingest with
  back-pressure, reorder buffer, single-owner filter session, bar clock,
  snapshots to WebSocket/HTTP/files/Redis, worker processes, parallel
  calibration, file-based backtests.
- **Pure, offloadable kernels** — `FilterBatch::run`, `InnovationLikelihood`,
  RTS smoothing, EM steps and more are `public static`, deterministic, and take
  only serialisable arrays, so they run unchanged in a worker, behind a queue
  consumer or an HTTP compute service (`@offloadable`, ADR-005).
- **Zero allocations in the hot loop**, unrolled kernels for n = 2/4, optional
  OpenBLAS backend through FFI, `stepRaw()` object-free path in every filter.

## Installation

```bash
composer require opencck/amphp-kalman
```

Requirements: PHP ≥ 8.2 (8.4+ recommended), `ext-json`; `ext-opcache` with the
JIT for production speed; optional `ext-ffi` + OpenBLAS for n > 32 (on Windows
`php tools/fetch-openblas.php` downloads the DLL, then set `KALMAN_BLAS_LIB`); the
`amphp/*` packages are pulled in by Composer. No compiled extension is needed.

### Enable the JIT (2–4× on the filter step)

```ini
opcache.enable_cli=1        ; for CLI workers and backtests
opcache.jit=1255
opcache.jit_buffer_size=128M
```

The filter core is written to be JIT-safe: PHP 8.2 and 8.3 miscompile two
common floating-point statement shapes (both JIT modes), and the library
avoids them and checks its own kernels at start-up
(`Diagnostics\JitSanity`; see [ADR-007](docs/decisions/ADR-007-jit-mode-and-determinism.md)).
Raise the tracing JIT's budget for processes hosting many models or many code
paths — when it is exhausted the rest of the code silently runs interpreted
(3–8× slower, no warning):

```ini
opcache.jit_max_root_traces=100000
opcache.jit_max_side_traces=100000
opcache.jit_max_exit_counters=32768
```

On Windows, CLI processes started with the same binary and ini attach to
*one* OPcache shared-memory segment — compiled scripts, JIT buffer and
tracing-JIT state included. Traces specialised by one process make hot loops
in another exit to the interpreter (we measured 3–8× on small kernels). Give
each process family its own segment with `opcache.cache_id`;
`WorkerPools::likeParent()` does this for worker pools and `bench/run.php` for
every benchmark child.

## Quick start

### 1. Local linear trend on one price stream

```php
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;

// σ_a: velocity-noise intensity (price units / s^1.5), half spread, tick size → R
$llt = new LocalLinearTrend(sigmaA: 0.5, halfSpread: 0.05, tick: 0.01);
$filter = $llt->filter(firstPrice: 100.0, config: FilterConfig::default()->withGating(GatingPolicy::chiSquare(0.001)));

foreach ($ticks as [$exchangeTimestampNs, $price]) {
    $result = $filter->step(Measurement::at($exchangeTimestampNs, [0 => $price]));   // predict(dt) + correct, atomic
    // $result->outcomes[0]->innovation, ->nis(); $result->logLikelihood
}

$fair = $filter->meanAt(0);                 // filtered price
$trend = LocalLinearTrend::trendScore($filter);   // v̂ / √P_vv
```

`stepRaw(int $tsNs, array $values)` does the same without creating objects;
results are read back with `lastInnovation($channel)` and friends.

### 2. ETF basket: fair value of the fund and of every constituent

```php
use OpenCCK\Kalman\Domain\Model\Finance\EtfBasket;

$etf = new EtfBasket(
    weights: [0.5, 0.3, 0.2],
    sigma: [4e-6, 1e-6, 5e-7,  1e-6, 3e-6, 2e-7,  5e-7, 2e-7, 2e-6],   // k×k return covariance per second
    sigmaA: 0.002,                        // velocity noise of the constituents
    premiumTheta: 0.05, premiumSigma: 0.01,   // OU premium of the fund over its NAV
    quoteVariances: [1e-4, 2e-4, 3e-4],   // r_i for the constituent quotes
    etfVariance: 5e-5,                    // r for the fund quote
);
$filter = $etf->filter(firstPrices: [100.0, 50.0, 20.0]);

// channels 0..k-1 are the constituents, channel k the fund; any subset per measurement is fine
$filter->step(Measurement::at($ts, [0 => 100.02, 3 => 71.9]));
$nav = $etf->nav($filter);   // Σ wᵢ p̂ᵢ
```

The state is `[p₁, v₁, …, p_k, v_k, premium]` (n = 2k + 1); prediction is
sparse and O(k·n).

### 3. Pairs trading: time-varying hedge ratio

```php
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Model\Finance\PairsHedge;

$pair = new PairsHedge(qAlpha: 1e-6, qBeta: 1e-5, sigmaEps: 0.02);   // y = α + β·x + ε
$filter = $pair->filter(alpha0: 0.0, beta0: 1.2, config: FilterConfig::default()->withForm(FilterForm::UD));

foreach ($ticks as [$ts, $y, $x]) {
    $raw = $pair->tick($ts, $y, $x);        // ['ts', 'values', 'rows'] — the observation row depends on x
    $filter->step(Measurement::at($raw['ts'], $raw['values']));
}
$z = PairsHedge::zScore($filter);          // standardised spread, the trading signal
```

Every catalogue model is serialisable (`toArray()` / `ModelRegistry`), so the
same filter can be rebuilt from a config array in another process:
`FilterFactory::fromConfig($modelConfig, $filterConfig, $snapshot)`.

More models: multi-venue fair value, time-varying beta, stochastic volatility
(QML and UKF with leverage), order-book microprice, Nelson–Siegel yield curve,
bid-ask bounce, log-price ETF basket (EKF), regime switching (IMM). See
[docs/models](docs/models/README.md).

## Streaming with AMPHP

```php
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;
use OpenCCK\Kalman\Infrastructure\Async\MeasurementBatcher;
use OpenCCK\Kalman\Infrastructure\Async\ReorderBuffer;
use OpenCCK\Kalman\Infrastructure\Ingest\IngestOrchestrator;
use OpenCCK\Kalman\Infrastructure\Ingest\WebsocketFeed;
use function Amp\async;

$feeds = [new WebsocketFeed($urlA, $decoderA), new WebsocketFeed($urlB, $decoderB)];
$handle = (new IngestOrchestrator($feeds))->start();          // Queue<Measurement> with back-pressure + stop()

$inbox = new Queue(1024);
$snapshots = new Queue(16);
$session = new FilterSession($filter, $inbox, $snapshots, snapshotEvery: 100);
$final = $session->start();                                   // owner fiber; Future<StateSnapshot>

async(static function () use ($handle, $inbox): void {
    $ordered = (new ReorderBuffer(windowNs: 2_000_000))->apply($handle->queue->iterate());   // exchange-time order inside a 2 ms window
    (new MeasurementBatcher(256))->pump($ordered, $inbox);    // arrays of ticks per queue item: loop overhead → 0
    $inbox->complete();
});
foreach ($snapshots->iterate() as $snapshot) {                // broadcast / persist
    // ...
}
```

The step itself is synchronous and atomic; asynchrony lives around it. Details,
worker processes, parallel calibration and shutdown order: [docs/async.md](docs/async.md).

Runnable examples: `examples/etf-live.php --mock`, `examples/calibrate-pair.php --synthetic`,
`examples/backtest-trend.php --synthetic --em`. The full index of every quantity
the library can measure, with an example per row, is in
[examples/README.md](examples/README.md) ([по-русски](examples/README.ru.md)).

## Calibration, smoothing, backtests

```php
use OpenCCK\Kalman\App\Calibration\Calibrator;
use OpenCCK\Kalman\App\Calibration\Parametrization;

$param = new Parametrization(['motion.sigmaA' => 'log', 'observation.variances.0' => 'log']);
$best = (new Calibrator($param))->calibrate($modelConfig, filterConfig: null, ticks: $history);
// $best['config'] — the model config at the innovation-likelihood maximum (Nelder–Mead)
```

`ParallelCalibrator` evaluates every simplex batch on an `amphp/parallel`
worker pool; give it `new MultiStartNelderMead(starts: 4)` to run four
simplexes in lockstep (16 candidate points per round — enough for 8 workers,
and robust to local optima). `ExpectationMaximization::fit()` estimates full
`Q`, `R` with RTS smoothing; `App\Backtest\Engine` replays a history file into a filter and
reports NIS, rejection rate and smoothed trajectories.

## Metrics, indicators and order-book measures

Filtering is only half the job: what gets filtered is usually an indicator, and
an indicator computed from raw ticks is itself a noisy series.
`Domain\Metric` supplies 32 measurements — technical indicators, order-book
metrics, volatility estimators and microstructure measures — each as a
streaming object that costs O(1) per observation and as a pure
`@deterministic @offloadable` kernel that a worker process can run on a whole
history.

```php
use OpenCCK\Kalman\Domain\Metric\Momentum\Rsi;
use OpenCCK\Kalman\Domain\Metric\Liquidity\LiquidityDensity;
use OpenCCK\Kalman\Domain\Metric\Filtered\Derivative;

$rsi = new Rsi(period: 14);                       // Wilder's RMA, the canonical definition
foreach ($closes as $close) {
    $rsi->updatePrice($timestampNs, $close);
}
$rsi->value();                                     // 0-100, NAN until warmed up

$series = Rsi::wilder($closes, 14);                // the same definition, whole history at once

$density = LiquidityDensity::within(               // size resting within 10 bps of the mid
    $book->bidPrices, $book->bidSizes, $book->askPrices, $book->askSizes, bandBps: 10.0,
);

$rate = Derivative::ofSeries($timestamps, $series);   // d(RSI)/dt, with its own uncertainty
$rate['rate'][-1];                                    // the slope, per second
$rate['tStat'][-1];                                   // above 2 means it is real, not noise
```

That last call is the point of the layer. Fourteen of the fifty indicators in
the reference catalogue this library was built against are finite differences
of a noisy series, which is the worst possible estimator of a derivative.
`Filtered\Derivative` replaces all of them with one constant-velocity filter
that returns the rate **and** its standard error, so a trend can be tested for
significance instead of eyeballed.

The full catalogue — every measurement with its symbol, category, offloadable
kernels, an algotrading-oriented description and a runnable example — is in
**[examples/README.md](examples/README.md)** ([по-русски](examples/README.ru.md)),
generated from the code so it cannot drift. Where a metric departs from the
reference formula it replaces, [ROADMAP.md](ROADMAP.md) says exactly how and
why: the reference RSI uses a 30-minute lag and a flat average instead of
Wilder's smoothing, its MACD uses simple averages over 8 and 17 samples instead
of EMAs of 12 and 26, its stochastic oscillator has no signal line, and its
liquidity density reads the best bid out of an unsorted map.
## Performance

Measured with `composer bench` on one core (PHP 8.3.33, `opcache.jit=1255`,
Windows 11 x64, `zend.assertions=-1`); every benchmark runs in its own process.
`bench/results/baseline.json` holds the full set; CI fails on allocations ≠ 0
and on gross (2×) regressions — hosted runners differ too much from a desktop
for a tighter gate.

| Metric (§1.3 targets in brackets) | n=4, m=2 | n=8, m=4 | n=16, m=8 | n=32, m=16 |
|---|---|---|---|---|
| Filter step, Sequential form (≤ 3 / 12 / 60 / 300 µs) | **1.4 µs** | **9.2 µs** | **53 µs** | 363 µs ✗ |
| Filter step, UD form (≤ 5 / 20 / 100 / 500 µs) | **3.6 µs** | **16.3 µs** | **99 µs** | 718 µs ✗ |
| Filter step, `Backend::Blas` (OpenBLAS) | — | 13.7 µs | **28 µs** | **76 µs** |
| Allocations per step, every filter type (0) | **0** | **0** | **0** | **0** |
| Throughput without I/O, ticks/s (≥ 300k / 80k / 15k / 3k) | **≈ 727k** | **≈ 108k** | **≈ 19k** | 2.8k ✗ |
| Event-loop overhead per tick (≤ 2 µs) | **1.1 µs** single ticks, **0.0 µs** with `MeasurementBatcher` arrays | | | |
| WebSocket ingest → filter, ticks/s (≥ 50k / 30k / 10k / 2.5k) | 15–20k ✗ (Windows loopback) | | | |
| 8-worker scaling, 32 independent likelihoods (≥ 6.5×) | 4.0× ✗ (1.8× / 2.9× on 2 / 4 workers) | | | |

Other numbers: LLT step (n=2, unrolled kernel) 0.62 µs; alpha-beta step
0.055 µs; unrolled vs generic kernel n=2 0.80 vs 0.98 µs, n=4 1.62 vs 2.41 µs;
IPC 35 µs/tick unbatched → 1.4–2.3 µs/tick at batch ≥ 16; SBE decode 0.78 µs
vs compact JSON 0.92 µs (1 channel), a real Binance trade event 2.6 µs;
UKF/KF cost ratio 3.2–4.8× (n = 2…8); parallel Nelder–Mead calibration on 8
workers 2.2× with one simplex and **4.6×** evaluation throughput with
`MultiStartNelderMead(starts: 4)`; F·P·Fᵀ alone at n=8: 4.0 µs flat PHP,
4.3 nested, 18 SplFixedArray, 18 FFI buffers, 2.4 OpenBLAS `dgemm` — the
BLAS crossover for the whole step is n ≈ 12 (ADR-006).

Deviations from the §1.3 targets and their causes:

- **n = 32 in pure PHP** — 363 µs vs 300 (UD 718 vs 500): the O(n³) predict in
  PHP. `Backend::Blas` does the same step in 76 µs (ADR-006), or use a sparse
  motion model at this size.
- **WebSocket throughput** — 15–20k ticks/s vs 30k, depending on machine load:
  bounded by the loopback WebSocket stack on Windows (50–67 µs per frame
  end-to-end, of which the filter step is 1 µs); Linux figures are expected to
  be higher — measure with `composer bench -- --filter=throughput`.
- **8-worker scaling** — 4.0× vs 6.5×: each job is only ~65 ms of work against
  a fixed IPC + serialisation cost per task, and the 16 "cores" detected are 8
  physical cores with hyper-threading; the trend (1.8× → 2.9× → 4.0×) is the
  physical-core curve. Longer histories per job approach the core count.
- **UKF/KF ratio** — the roadmap asked for ≤ 3×; the sequential UKF is 3.2–4.8×
  (2n+1 sigma points regenerated per channel).
- **Consumer-side tick batching** (`FilterSession` `batchSize`) alone saves only
  ~0.05–0.25 µs per tick; the producer-side `MeasurementBatcher` (arrays of
  measurements per queue item) removes the loop overhead entirely — use it.
Two engine effects were uncovered while measuring and are documented because
they affect users as much as benchmarks: PHP 8.2/8.3 JIT miscompilations of
two float-kernel shapes (ADR-007) and the shared OPcache segment of Windows
CLI processes (ADR-008).

## Design documents

- [docs/theory.md](docs/theory.md) — the mathematics, section by section, with
  the class that implements each idea ([по-русски](docs/theory.ru.md)).
- [docs/async.md](docs/async.md) — topology, back-pressure, single-owner
  session, workers, shutdown, AMPHP pitfalls ([по-русски](docs/async.ru.md)).
- [docs/models](docs/models/README.md) — the market-model catalogue
  ([по-русски](docs/models/README.ru.md)).
- [examples/README.md](examples/README.md) — the catalogue of measurements:
  every metric, indicator and filter output with its symbol, category,
  offloadable kernels and a runnable example ([по-русски](examples/README.ru.md)).
- [ROADMAP.md](ROADMAP.md) — the plan for the metric layer: per-indicator
  theory, canonical formulas and how they differ from the PromQL originals
  (Russian only).
- [docs/decisions](docs/decisions) — decision records (ADR-001…005 in Russian,
  006–008 in English): ADR-001
  memory layout, ADR-002 DDD layout,
  ADR-003 two-phase scalar correction, ADR-004 toolchain, ADR-005 offloadable
  kernels, ADR-006 BLAS backend, ADR-007 JIT miscompilations and determinism,
  ADR-008 OPcache isolation on Windows, ADR-009 Psalm alongside PHPStan,
  ADR-010 the order-book entity, ADR-011 the metric contract, ADR-012 metric
  windows.
- [BRIEF.md](BRIEF.md) — project context: scope, the non-negotiable rules,
  architecture, status, repository map, glossary.
- [CLAUDE.md](CLAUDE.md) — short entry point for coding agents.

## Development

```bash
composer test            # PHPUnit 9.6, zend.assertions=1 (unit, reference, invariant, property, consistency, golden, async, architecture)
composer test:fast       # without the @group slow suites
composer test:blas       # BLAS backend tests (needs ext-ffi and KALMAN_BLAS_LIB, see tools/fetch-openblas.php)
composer analyse         # PHPStan level 9 + Psalm errorLevel 1
composer bench           # all benchmarks, each in its own process → bench/results/latest.json
composer bench:baseline  # save/merge bench/results/baseline.json
```

Layout follows the OpenCCK DDD convention: `src/Domain` (pure numerics, no
`Amp\`), `src/App` (calibration, backtest use cases), `src/Infrastructure`
(everything AMPHP). Architecture tests enforce the layer direction,
`strict_types`, `JSON_THROW_ON_ERROR`, flat matrices in the hot core, the
`@offloadable` contract and FFI containment. Static analysis runs twice —
PHPStan level 9 and Psalm errorLevel 1, both without a baseline (ADR-009).

## License

MIT — see [LICENSE](LICENSE).
