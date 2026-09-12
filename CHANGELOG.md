# Changelog

All notable changes to `opencck/amphp-kalman` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
the project uses [Semantic Versioning](https://semver.org/).

## [1.0.1] — 2026-09-12

### Fixed
- The `Benchmarks vs baseline` CI job installed only `opcache`, so the three benchmarks that start worker processes —
  `CalibrationBench`, `IpcBench` and `ScalingBench` — died at the first one. `amphp/parallel` spawns processes through
  `PosixRunner`, which needs `ext-posix`, and talks to them over `ext-sockets`; `CalibrationBench` reaches that path on
  its first call, where `HistoryReader::write()` goes through `ParallelFilesystemDriver`. The job now installs
  `opcache, posix, pcntl, sockets`.
- `posix` added to the test job as well, which was on `opcache, pcntl, sockets`: it exercises the same worker code
  paths, so the two jobs differing was accidental rather than deliberate.
- `ext-posix` and `ext-sockets` recorded in `composer.json` `suggest`. The requirement belongs to the library's parallel
  features rather than to CI — anyone installing the package and enabling worker-based calibration meets it too.

### Documentation
- The suite is 1209 tests after the metric reference tests landed, not 752 (`CLAUDE.md`, `BRIEF.md`).
- `BRIEF.md` §5 now says plainly that the contract tests check the *shape* of a metric, while the 32 files under
  `tests/Reference/Metric/` check its arithmetic — and that a new measurement without one is not finished.

[1.0.1]: https://github.com/opencck/amphp-kalman/releases/tag/v1.0.1

## [1.0.0] — 2026-09-12

First public release. Everything below is the work that led to it, kept in the
order it was built rather than collapsed into one entry, because the phases
explain why the library is shaped the way it is.

[1.0.0]: https://github.com/opencck/amphp-kalman/releases/tag/v1.0.0

### Phase 0 — Bootstrap
- composer package skeleton (`php ^8.2`, `amphp/amp ^3`, `amphp/pipeline ^1`, `amphp/sync ^2`, `revolt/event-loop ^1`),
  PHPUnit 9.6 with `zend.assertions=1`, PHPStan level 9, GitHub Actions CI (tests, analysis, benchmarks vs baseline).
- DDD layout `src/Domain | App | Infrastructure` (ADR-002); `Infrastructure` is the only layer allowed to import `Amp\`.
- Benchmark runner `bench/run.php` with JSON output, baseline and regression gate; `LayoutBench`, `FormBench`, `AllocBench`.
- ADR-001 memory layout (flat row-major arrays), ADR-003 two-phase scalar correction, ADR-004 toolchain,
  ADR-005 `@offloadable` deterministic kernels for out-of-process execution.
- `ArchitectureTest`: no `Amp\` in Domain/App, layer direction, `strict_types`, `JSON_THROW_ON_ERROR`,
  no nested matrices in the hot core, `@offloadable` contract.

### Phase 1 — Linear algebra and sequential core
- `Domain\Linalg\Flat`, `Cholesky`, `Nested`.
- Contracts: `MotionModel`, `SparseMotionModel`, `StationaryModel`, `ObservationModel`, `CorrelatedObservationModel`,
  `CovarianceRepresentation`, `NonlinearMotionModel`, `NonlinearObservationModel`, `SerializableModel`.
- Value objects: `Measurement`, `StateSnapshot`, `UpdateResult`, `ChannelOutcome`, `FilterConfig`, `GatingPolicy`, `FilterForm`.
- `Covariance\DenseSequential`; `Filter\KalmanFilter` (`predict`, `correct`, `step`, `stepRaw`, `snapshot`, `fromSnapshot`, `reset`,
  `logLikelihood`, per-channel last innovations), F/Q matrix cache keyed by quantised dt.
- Gating: `none`, `sigma(k)`, `chiSquare(alpha)`, `huber(c)`; `Diagnostics\ChiSquare` quantiles without ext-stats.
- `Diagnostics\ConsistencyMonitor`, `ChannelHealth`, `Nees`.
- Models: `Generic\RandomWalk`, `Generic\ConstantVelocity`, `Observation\StaticObservation`, `Observation\MutableObservation`,
  `Finance\LocalLinearTrend`.
- `Factory\FilterFactory::fromConfig` + `ModelRegistry` (serialisable model descriptions), `Filter\FilterBatch::run` (`@offloadable`).
- Tests: reference (vs textbook `NaiveKalmanFilter`, 1e-9), invariants, properties, NEES/NIS consistency on 50k-step simulation.

### Phase 2 — Numerically stable forms
- `Covariance\Joseph` (scalar Joseph update), `Covariance\UD` (Bierman correct + Thornton MWGS predict),
  `Covariance\SquareRoot` (Potter correct + Householder predict), `Linalg\Householder`.
- `Observation\CorrelatedObservation` + `DecorrelatedObservation` (Cholesky whitening of a full R, with likelihood Jacobian).
- `Finance\BidAskBounce` (§3.9, coloured measurement noise via state augmentation; UD by default).
- Stress test with r ≈ 1e-8·h·P·hᵀ (`@group slow`): UD and SquareRoot keep P positive definite.
- `FormBench` numbers recorded in ADR-001.

### Phase 3 — Model catalogue and discretisation
- `Linalg\LU`, `Linalg\MatrixExponential` (scaling-and-squaring + Padé 6), `Discretization\VanLoan`, `Discretization\ClosedForm`
  (RW, CV, CA, OU, Singer with a cancellation-free series for dt ≪ τ); closed forms match Van Loan to 1e-10.
- Generic models: `ConstantAcceleration`, `OrnsteinUhlenbeck`, `Singer`, `BlockDiagonal`, `DenseLinear` (Van Loan with dt cache),
  `DiscreteWhiteNoiseAcceleration`, `HeteroscedasticMotionModel` + `Finance\IntradayProfile`.
- Finance models §3.2–3.9: `EtfBasket` (sparse O(k·n) predict), `PairsHedge` (+OU spread), `MultiVenue`, `TimeVaryingBeta`,
  `StochasticVolatility` (QML), `Microprice`, `NelsonSiegel`, `BidAskBounce`; `Observation\MutableObservation` for time-varying H_t / R_t.
- `Diagnostics\ObservabilityCheck`, `Diagnostics\SteadyStateSolver::dare`, `Filter\SteadyStateFilter`, `Filter\AlphaBetaFilter`
  (Kalata closed form exact for DWNA; `forConstantVelocity()` from the DARE for CWNA).
- Generated loop-free kernels `Covariance\Kernels\Sequential2` / `Sequential4` (`tools/generate-kernels.php`), selected automatically; `KernelBench`.
- Consistency tests for every catalogue model (NEES/NIS on own-model simulation; ensemble protocol for regression models),
  golden files `tests/Golden/*.json`, `docs/models/*.md`.

### Phase 4 — Async ingest (Infrastructure)
- `Infrastructure\Ingest`: `Feed`, `WebsocketFeed` (stale detection via TimeoutCancellation, reconnect, back-pressure),
  `MessageDecoder`, `JsonTickDecoder`, `IngestOrchestrator` + `IngestHandle`, `TickCodec` (JSON lines / CSV, per-tick rows), `HistoryReader` (Amp\File, gzip).
- `Infrastructure\Async`: `FilterSession` (single-owner fiber, `OutOfSequencePolicy` Drop/Retrodict/Fail, linearised snapshots),
  `ReorderBuffer` (exchange-time window), `BarClock` (blind ticks, `weakClosure`), `Clock\SystemClock` / `ManualClock`.
- `Infrastructure\Command`: `SnapshotRequest`, `Split`, `Dividend`, `Rebalance`, `Reset`; `KalmanFilter::scaleState/shiftState/addVariance`.
- `Infrastructure\Output`: `SnapshotSerializer`, `FilePersister` (atomic tmp+rename), `RedisPersister`, `SnapshotBroadcaster`,
  `StateWebsocketHandler`, `HttpStateHandler`, `TrajectoryFile`.
- `Domain\Filter\Retrodiction` (one-step OOSM). Tests §5.13 with `tests/Support/MockExchangeServer`; `EventLoopOverheadBench`, `ThroughputBench`;
  `examples/etf-live.php`, `examples/decoders/*`.

### Phase 5 — Parallelism, calibration, adaptivity
- `Infrastructure\Task`: `FilterWorkerTask` + `Batcher` + `WorkerSession` (batched IPC, null sentinel, bit-identical to in-process),
  `LikelihoodTask`, `ParallelCalibrator` (Nelder–Mead simplex evaluated on a worker pool).
- `App\Calibration`: `Parametrization` (log / linear / cholesky:n), `InnovationLikelihood` (`@offloadable`), `NelderMead` (batch evaluator,
  speculative 4-point iterations), `Calibrator`.
- `Filter\InformationFilter` (Y = P⁻¹, additive fusion, Y₀ = 0 prior), `Adaptive\InnovationAdaptive`, `Adaptive\SageHusa`, `Adaptive\AdaptiveNoise`.
- Time-varying observation rows shipped with ticks (`rows` key) so regression models cross process boundaries exactly.
- `IpcBench`, `ScalingBench`, `CalibrationBench`; `examples/calibrate-pair.php`.

### Phase 6 — Smoothing, EM, backtest
- `Contract\StepRecorder` + `KalmanFilter::setRecorder()`, `Smoothing\FilterTrajectory` (SplFixedArray blocks, ring cap),
  `Smoothing\RauchTungStriebel` (with lag-one covariances, `@offloadable smoothConfig`), `Smoothing\FixedLagSmoother`.
- `App\Calibration\ExpectationMaximization` (Shumway–Stoffer, monotone likelihood), `Generic\DiscreteLinear`.
- `App\Backtest\Engine` + `Report`; `Infrastructure\Output\TrajectoryFile` (binary spill, random access); `examples/backtest-trend.php`.

### Phase 7 — Nonlinear and multi-model
- `Contract\NonlinearObservationModel::projectChannel`, `Generic\LinearMotionAdapter`, `Observation\LinearObservationAdapter`.
- `Filter\ExtendedKalmanFilter` (same covariance representations), `Filter\UnscentedKalmanFilter` (sequential sigma-point update).
- `Finance\StochasticVolatilityLeverage` (UKF, return as observation, leverage ρ), `Finance\LogPriceEtfBasket` (EKF in log prices).
- `MultiModel\InteractingMultipleModel` with `regimeProbabilities()`; regime switches detected within ≤ 5 steps on synthetic data.
- `NonlinearBench` (UKF / KF cost ratio).

### Phase 8 — Performance
- `stepRaw()` (object-free step) in `ExtendedKalmanFilter`, `UnscentedKalmanFilter`, `InformationFilter` with
  `lastInnovation/lastInnovationVariance/lastWeight/lastChannels` raw accessors; `AllocBench` now covers every filter type
  (all 0 bytes over 100 000 steps).
- `FilterSession` tick batching (§6.5): `batchSize` drains the inbox through a helper fiber into one array per wake-up;
  back-pressure and command order preserved; `batches()` reports the amortisation.
- `Entity\Backend` + `FilterConfig::withBackend(Backend::Blas)`: OpenBLAS through ext-ffi (`Linalg\Ffi\BlasBackend`,
  `Covariance\BlasDense`, Sequential form only, opt-in, `UnsupportedOperation` when unavailable). ADR-006. CI job `blas`.
  `LayoutBench` adds `ffi_n*` and `blas_n*` variants where available.
- Binary `MessageDecoder`: `Ingest\SbeTickDecoder` / `SbeTickEncoder` (SBE message header + repeating group, one `unpack()`
  per frame); `DecoderBench` (JSON vs SBE).
- ADR-007: two PHP 8.2/8.3 JIT miscompilations found and worked around — reused `a * const * b` temporaries
  (`ConstantVelocity`, `ClosedForm`, `BidAskBounce`, `ConstantAcceleration` now put the constant last) and in-place
  `C[i,j] += a·B[p,j]` accumulation (`Flat::multiply/transposedMultiply/add/subtract`, `MatrixExponential`,
  `Retrodiction` rewritten with local accumulators). `Diagnostics\JitSanity::verify()` (library shapes vs exact
  literals, called from every filter constructor and `FilterFactory`) and `engineBugs()` (known-bad shapes, reported);
  `bench/run.php` refuses to run on a failing engine; test suites verified under PHP 8.3 with `opcache.jit=1255` and `1205`.
- `Infrastructure\Task\WorkerPools::likeParent()` (moved from bench support): worker processes inherit the parent's
  extension dir, extensions, JIT flags, assertions and memory limit — required for bit-identical worker results.
- `LikelihoodTask` reads history files with the blocking filesystem driver inside workers (nested `ParallelFilesystemDriver`
  pools stalled the parent's shutdown); `CalibrationBench` / `ScalingBench` now complete.
- `UnscentedKalmanFilter`: sigma points in one flat (2n+1)×n buffer (no nested arrays in the hot core);
  `Parametrization::extractTheta/applyTheta` static `@offloadable` kernels.
- Architecture rules: FFI confined to `Domain\Linalg\Ffi` + `Covariance\BlasDense`.
- `bench/run.php` runs every benchmark in its own child process (the tracing JIT's per-process trace budget made
  later benchmarks in one process fall back to the interpreter); `LikelihoodTask` caches the decoded history per worker
  (calibration speed-up 1.0× → 2.4× on 8 workers, bounded by the 4-point simplex batch); `ScalingBench` 32 jobs with a warm
  file cache; `DecoderBench` adds a real Binance trade event.
- ADR-008: Windows CLI processes share one OPcache segment (tracing-JIT state included), which made benchmarks run
  after others 3–8× slower; `WorkerPools::binary()` adds a per-pool `opcache.cache_id` and `bench/run.php` one per child.
- README performance table with the measured §1.3 numbers and documented deviations.

### Phase 9 — Documentation and release
- `README.md` (installation, JIT requirement, quick starts for LLT / ETF basket / pairs, performance table, async usage).
- `docs/theory.md` (§2 as user documentation), `docs/async.md` (§5), `docs/models/*.md`, `docs/decisions/ADR-001…008`.
- `composer.json` version `0.1.0`, MIT licence.

### Post-roadmap improvements
- `Infrastructure\Async\MeasurementBatcher`: producer-side batching — arrays of measurements per inbox item;
  `FilterSession` accepts them natively. Event-loop overhead per tick 1.1 µs → 0.0 µs (`EventLoopOverheadBench`).
- `App\Calibration\Optimizer` interface, `NelderMead::stepper()` (the algorithm as a coroutine) and
  `MultiStartNelderMead`: K simplexes in lockstep, 4K candidate points per evaluator call. Parallel calibration
  throughput on 8 workers 2.2× → 4.6× (`CalibrationBench`); `Calibrator` / `ParallelCalibrator` accept any `Optimizer`.
- BLAS backend verified against real OpenBLAS: `BlasDense` now keeps only the upper triangle authoritative and uses
  `dsymm` / `dsymv` / `dsyr` (exact symmetry, no PHP O(n²) loop per channel); `tools/fetch-openblas.php` downloads the
  Windows DLL so `BlasDenseTest` and the `blas_*` benchmarks run locally; `FormBench` measures the full step with
  `Backend::Blas` (crossover in ADR-006).
- Documentation in two languages, every pair cross-linked in its header: `README.md` / `README.ru.md`,
  `docs/theory.md` / `docs/theory.ru.md`, `docs/async.md` / `docs/async.ru.md`, `docs/models/*.md` / `docs/models/*.ru.md`.
  `BRIEF.md`, `CLAUDE.md` and the ADRs are not translated.
- Psalm 6 at `errorLevel="1"` added next to PHPStan level 9 (ADR-009); `composer analyse` runs both and CI gates on
  both. 821 Psalm findings triaged: the real ones fixed (null offsets from `array_key_first()`, `getmypid()` /
  `preg_match_all()` / `glob()` false returns, array shapes that had lost the optional `rows` key, an `array_map`
  callable with no parameter, dead initialisers and unused variables/params, an unchecked four-point batch in
  `NelderMead`), the rest suppressed with a written reason. `LayoutBench` kernels now return a checksum so the
  measured work cannot be optimised away; `BlasBackend` gained typed `addr()` / `valueAt()` accessors. No baseline
  file; type inference coverage 99.79 %.
- `BRIEF.md` (project context: scope, rules, architecture, status, repository map, glossary) and
  `CLAUDE.md` (short entry point for coding agents) replace the original `ROADMAP.md`, whose chapters
  had already become `docs/theory.md`, `docs/async.md` and `docs/models/`.

### Metric layer — planning
- `ROADMAP.md` (Russian): the plan for the metric, indicator and signal layer. Per-indicator analysis of the
  50 reference PromQL formulas against their canonical definitions — RSI (Wilder RMA vs `avg_over_time` over a
  30-minute lag), MACD (EMA 12/26/9 vs SMA 8/17/9), the stochastic oscillator (no `%D`), CCI (mean absolute
  deviation, not standard deviation), ADX, VWAP anchoring, order-book imbalance depth, and the liquidity-density
  kernel from the reference `OrderBookEntity` (three defects, including best bid/ask read from an unsorted array).
  Adds what the reference lacks: order-flow imbalance, VPIN, Kyle's lambda, Amihud illiquidity, book slope,
  cost-to-trade, the four spread forms with the Roll estimator, ATR, and the Parkinson / Garman–Klass /
  Rogers–Satchell / Yang–Zhang range volatility estimators. Twelve phases M0–M11, three new ADRs scheduled.
- `examples/README.md` / `examples/README.ru.md`: the catalogue of measurements — every filter output, model
  output, diagnostic and planned metric in one table with symbol, category, offloadable kernels, an
  algotrading-oriented description, a plain description and a link to its example. Linked from both root READMEs.
- The fourteen `dX` finite differences in the reference collapse into one `Filtered\Derivative` wrapper: any metric
  through a local-linear-trend filter yields level, rate and covariance, with strictly lower error than differencing.

### Metric layer — implementation
- `src/Domain/Metric/`: 32 measurements, each with a streaming object that costs O(1) per observation and allocates
  nothing per tick, `@deterministic @offloadable` kernels that drive the same object over a whole history (ADR-011),
  a serialisable config, a catalogue card and a runnable example.
  - **Price**: `NormalizedPrice` (z-score, ratio, log), `PriceDelta` (log and simple returns), `MeanPriceDifference`
    (the reference NdT, which also reports the window overlap that makes it noisy).
  - **Momentum**: `Rsi` — Wilder's RMA on consecutive closes, with `Rsi::lagged()` reproducing the reference's
    30-minute-lag flat-average variant under a name that says what it is; `Stochastic` with the `%D` signal line the
    reference omits; `Cci` on mean absolute deviation, not standard deviation; `Momentum` (return per unit of risk).
  - **Trend**: `MovingAverage` (SMA/EMA/RMA/WMA plus a time-based EMA for irregular streams), `Macd` on EMAs 12/26/9
    with all three outputs and `referenceEquivalent()` for the 8/17 simple-average variant, `MaSpread`, `Adx` with the
    DI pair and an explicit `isSettled()` warm-up.
  - **Volume**: `VolumeProfile` and `Vwap` (anchored and rolling, with dispersion bands), both reading the trade tape
    rather than summing a sampled gauge.
  - **Liquidity**: `OrderBookImbalance` (depth and distance weighting), `LiquidityDensity` (three kernel shapes, three
    normalisations), `BookSlope`, `CostToTrade`, `WeightedPrices`, `Spread` (quoted, relative, effective, realised and
    Roll's estimator from the tape alone).
  - **Volatility**: `OhlcVolatility` with all five estimators (close-to-close, Parkinson, Garman–Klass,
    Rogers–Satchell, Yang–Zhang), `Atr`, `RealizedVolatility` (realised + RiskMetrics EWMA), `RangeVolatility` with
    the Parkinson conversion that turns a relative range into a σ.
  - **Microstructure**: `TradeClassifier` (Lee–Ready, tick rule, bulk volume), `OrderFlowImbalance`, `Vpin` on a
    volume clock, `PriceImpact` (Kyle's λ and Amihud).
  - **Cross-asset**: `BasketReturn` (equal, volume and inverse-volatility weighting), `RelativeStrength` (beta-adjusted
    by default), `MarketStrength` (breadth blended with standardised momentum).
  - **Filtered**: `Derivative` replaces all fourteen `dX` finite differences of the reference with one constant-velocity
    filter returning level, rate, both standard errors and the rate's t-statistic; `MetricObservation` turns any metric
    into a `Measurement` so indicators can be fused as filter channels.
  - **Regime**: `FlatMarket` (Kaufman efficiency ratio and band width).
- New entities: `OrderBook` (sorted sides, ADR-010), `OrderBookBuilder` (incremental deltas, cached snapshots),
  `Trade`, `TradeSide`, `Bar`. New support structures: `RingBuffer`, `RollingMoments` (windowed Welford with periodic
  exact refresh), `MonotonicWindow` (O(1) sliding min/max), `VolumeClock`, `BarAggregator` (time, volume and tick bars).
- `MetricRegistry` maps type slugs to classes and holds the machine-readable catalogue;
  `tools/generate-metric-index.php` renders it into the tables in `examples/README.md` and `examples/README.ru.md`, and
  `tests/Architecture/MetricCatalogueTest` fails the build when the committed tables drift from the code, when a card
  lists a kernel that is not `@offloadable`, or when a row points at a missing example.
- ADR-010 the order-book entity and the reference implementation's unsorted-map defect; ADR-011 one definition per
  metric exercised two ways; ADR-012 windows in observations with a time-based form where it matters.
- 30 runnable examples under `examples/`, at least one per measurement (two pairs share a script, since the book
  slope belongs beside liquidity density and the mean-price difference beside the price delta), on deterministic
  synthetic data
  (`examples/Support/Synthetic.php`). Several demonstrate the correction rather than asserting it:
  `liquidity-density.php` reproduces the unsorted-map bug side by side with the fix, `momentum-rsi.php` plots Wilder's
  RSI against the reference variant, `trend-macd.php` shows their zero crossings landing at different times, and
  `filtered-derivative.php` measures the error of a finite difference, a least-squares slope and the filter against a
  known rate.
- `ArchitectureTest` now resolves interfaces and enums as well as classes, so a contract may describe `@offloadable`
  in its prose.
- `docs/theory.md` / `.ru.md` §17 "Estimating a rate of change": the finite difference, the least-squares slope and the
  constant-velocity filter compared as estimators of the same quantity, with the variance of each, the tracking index
  that governs the filter's trade-off, and the caveat that a misspecified model produces a confident velocity that is
  not there.
- `MetricBench` measures microseconds and allocation delta per update for a representative metric of each input kind;
  every one allocates 0 bytes over 100 000 updates.
- Two defects the new tests found in code written for this layer, both fixed before release:
  `Support\MonotonicWindow` overwrote the front of its deque whenever a monotone sequence filled it, because it
  appended before expiring — a sliding minimum over a rising series returned the wrong value; and
  `Support\RollingMoments` lost significance on large-magnitude series and never returned exactly to zero variance.
  The window now expires before it appends, and the moments are accumulated against a shifted origin with an exact
  rescan for windows of sixteen values or fewer.
- Three docblock claims corrected after the examples measured them: the lagged RSI variant pins at 100 under a trend
  while Wilder's does not (the original text had the direction backwards), `OrderFlowImbalance::impliedMove()` is in
  ticks rather than price, and the filter's t-statistic separates trend from chop only once its tracking index is
  tuned — at a heavily smoothed setting it over-claims.

### Build gates and naming
- Namespace root renamed `Opencck\Kalman` → `OpenCCK\Kalman` (1734 occurrences in 323 files): the code now spells the
  convention the way BRIEF §3, both READMEs and ADR-002 already spelled it in prose. The Composer vendor name stays
  lowercase (`opencck/amphp-kalman`), as do the repository URL, the `opencck/skill-amphp` references and the LICENSE
  line — that difference in case is deliberate. No directories moved: PSR-4 maps the prefix straight onto `src/`,
  `tests/`, `bench/` and `examples/`. String-carried occurrences were renamed too — the benchmark discovery prefix in
  `bench/run.php`, `MetricContractTest::NAMESPACE_PREFIX`, the two layer `preg_match` patterns in `ArchitectureTest`
  and the namespace emitted by `tools/generate-kernels.php`. ADR-002 records the rename and what it did not touch.
- `MetricBench` recorded in `bench/results/baseline.json`: it had a benchmark class and a CHANGELOG claim but no
  baseline entry, and `--compare` skipped a metric with no baseline *before* testing it, so the "0 allocations per
  update" rule of ROADMAP §9 was never actually enforced in CI. The allocation check now runs before that skip — an
  allocation figure is absolute, not relative, so it is gated even for a benchmark the baseline has not seen, and a
  new benchmark can no longer enter the suite ungated. All 14 metrics measure 0 bytes over 100 000 updates.
- `--save-baseline` records the engine environment per benchmark under `environments`, not only once for the run.
  A filtered save (`--filter=metric --save-baseline`) merges one benchmark into a baseline whose other entries were
  recorded on another engine; a single top-level block would then claim all twelve came from the latest run. Entries
  older than the change are back-filled from that top-level block. `--compare` prints which engine a benchmark's
  baseline came from when it differs from the current one, but still gates on the timing: CI compares against the
  maintainer's Windows baseline on purpose, with `--tolerance=1.0`, to catch the one thing that survives a change of
  engine — a 2× fall back to the interpreter.
- `composer.json` `config.process-timeout: 0`. The metric layer took the suite past Composer's default 300 s, so
  `composer test` — the documented entry point, and what CI runs — aborted at 484 of 752 tests with a process timeout
  that reads like a hang rather than a limit. The suite needs 17 min 14 s locally.
- Corrected stale figures: `CLAUDE.md` and `BRIEF.md` said 349 tests (752), and BRIEF quoted 99.79 % Psalm type
  inference (99.88 %).
- `.gitattributes` gained the `export-ignore` set it never had, so `composer require opencck/amphp-kalman` stops
  shipping the whole repository. A consumer receives `src/`, `docs/`, `examples/`, `composer.json`, `LICENSE`, both
  READMEs, `CHANGELOG.md` and `tools/fetch-openblas.php` — the last because the README tells the user to run it to set
  up the opt-in BLAS backend (ADR-006). Excluded: `tests/`, `bench/`, `tools/psalm/`, the two code generators, the
  three analyser configs, `.github/`, and the contributor-facing `BRIEF.md` / `CLAUDE.md` / `ROADMAP.md`. 1.9 MB
  installed against 2.9 MB of repository. `docs/` and `examples/` ship on purpose: they are user documentation, so the
  README's links into `docs/theory.md` and the generated catalogue in `examples/README.md` resolve for someone reading
  the package out of `vendor/` and not only on GitHub.
- `examples/bootstrap.php`: every example now requires it instead of `../vendor/autoload.php`. That path only exists in
  this repository — from a consumer's install it resolves to `vendor/opencck/amphp-kalman/vendor/autoload.php`, which
  does not, so the shipped examples would all have died on a missing require. The bootstrap tries both layouts, and
  registers the `OpenCCK\Kalman\Examples\` namespace itself, because it is declared in `autoload-dev` and a consumer
  never registers it — 30 of the 33 examples use `Support\Synthetic` and would otherwise fail on a missing class.
  Mapping it in the bootstrap rather than in composer.json's production `autoload` keeps a demo fixture out of the
  installed package's classmap. Verified by exporting the archive into `vendor/opencck/amphp-kalman/` under a stub
  autoloader that registers only the production namespace: all 30 run.
- `.gitattributes` line-ending rules trimmed to the file types this repository actually has (php, json, md, xml, neon,
  yml, phpstub); the boilerplate it started from declared `*.c`, `*.h`, `*.sln`, `*.vue`, `*.scss` and five more that
  appear nowhere in the project.
- `.gitignore` commented and reordered: why the lock file is not committed, which three files the cache paths have to
  stay in step with, why only `baseline.json` survives under `bench/results/`, and that `tools/psalm/pcntl.phpstub` is
  deliberately *not* ignored even though the rest of what `tools/` accumulates is. `/.psalm.cache/` is now anchored
  like its two neighbours instead of matching at any depth.

### Reference tests for the whole metric catalogue
- A reference test for each of the remaining 25 measurements, so that all 32 in the catalogue now have one —
  `tests/Reference/Metric/` grows from 7 files to 32. The gap was real: the classic indicators (RSI, stochastic, CCI,
  moving averages, MACD, ADX, ATR) had been covered from the start, while the order-book, volume, volatility,
  microstructure, cross-asset and regime measurements had only `MetricContractTest` behind them — which checks the shape
  of a metric (stable `values()` keys, a clean `reset()`, a `toArray()` round trip) and never its arithmetic. Their
  formulas were, until now, verified by nothing but their own output.
- Each new test follows the established pattern: an independent naive implementation written from the published formula
  inside the test, deterministic fixtures, a tolerance of 1e-9, plus values derived by hand where the definition allows
  one — Parkinson on a bar with H/L = e is σ = 1/(2·√ln2), a 10-unit fill at 100 and 30 at 110 is a VWAP of exactly
  107.5, three units bid against one offered is an imbalance of exactly 0.5.
- Several tests pin the property the metric is chosen for rather than only its arithmetic, because that is what a
  rewrite loses: Rogers–Satchell, Yang–Zhang and close-to-close read a pure drift as exactly zero volatility while
  Parkinson and Garman–Klass report ln(g)²/(4·ln2) and (½ − (2·ln2 − 1))·ln(g)²; Kyle's λ is recovered exactly from a
  synthetic market built to a known λ, and the reversed regression returns 1/λ; log returns telescope across horizons
  where simple ones compound; the efficiency ratio is exactly 1 on a monotone move and exactly 0 on a closed path;
  VPIN splits a trade across the bucket boundary it straddles instead of assigning it whole.
- `LiquidityDensityTest` reproduces the ADR-010 defect as a test rather than prose: the metric's early exit at the first
  level outside the band is sound only because `OrderBook` sorts each side by distance from the mid, so the same levels
  in hash-map order undercount — and the entity restores the ordering.
- `DerivativeTest` drives `NaiveKalmanFilter` — nested arrays, an explicit inverse, the (I−KH)P form — with the matrices
  the metric derives (R = σ², q = (κ·σ·√3/Δt^1.5)², a velocity prior of σ/Δt) and compares the two filters step by step.
- Ten assertions were wrong when first written and the code was right; each was replaced with one that follows from the
  definition. Worth recording because they are the mistakes a reader is likely to repeat: the tick rule compares against
  the previous *trade*, not the mid, so a print at the mid below the last print is a sell; a rolling VWAP reports a
  partial window while only `isReady()` insists on a full one; Roll's estimator needs an odd sample for its closed form,
  since an even one carries a mean-adjustment factor of √(1 − 1/m²); smoothing in `RangeVolatility` does not shrink the
  reading uniformly, because a window of `window` smoothed values spans `window + smoothing − 1` ticks; and a drift
  applied between bars leaves `ln(C/O)` untouched, so drift-independence has to be tested with a drift *inside* the bar.
- One finding left open for the maintainer rather than fixed: `Momentum` (M-09) reports NAPM ≈ 9e14 on a perfectly
  constant growth rate. The block returns are then equal only to within floating point — they differ by ~4e−16 — and the
  `sd > 0` guard cannot tell that from a genuinely tiny dispersion. A flat series, whose dispersion is exactly zero,
  correctly returns NAN. `MomentumTest` documents the edge and deliberately does not pin the ratio.
