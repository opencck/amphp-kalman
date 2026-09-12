# BRIEF — project context for `opencck/amphp-kalman`

*Short entry point for coding agents: [CLAUDE.md](CLAUDE.md). This document and
CLAUDE.md are kept in English only; user-facing documentation is bilingual (§8).*

This is the single source of truth for **what this project is, what may not be
broken, and where everything lives**. It replaces the original Russian design
document, whose theory chapter became [docs/theory.md](docs/theory.md), whose
async chapter became [docs/async.md](docs/async.md) and whose model catalogue
became [docs/models](docs/models/README.md). The file name `ROADMAP.md` is now
reused by an unrelated document: the plan for the metric and indicator layer.

Contents: [1 Scope](#1-scope) · [2 Non-negotiable rules](#2-non-negotiable-rules) ·
[3 Architecture](#3-architecture) · [4 Performance](#4-performance) ·
[5 Testing](#5-testing) · [6 Status](#6-status) · [7 Repository map](#7-repository-map) ·
[8 Working agreements](#8-working-agreements) · [9 Glossary](#9-glossary) ·
[10 References](#10-references)

---

## 1. Scope

The library estimates a hidden state vector `x ∈ ℝⁿ` of a linear (and, through
EKF/UKF, weakly nonlinear) dynamic system from noisy observations `z ∈ ℝᵐ` that
arrive as a stream — with gaps, at different rates, from different sources. The
application domain is market data: fair prices, trends, hedge ratios,
volatility, fund premiums, venue offsets.

**What asynchrony does and does not buy.** AMPHP does not speed up arithmetic:
a filter step is a dense loop over float arrays, fibers are cooperative and run
in one thread. Moving `correct()` into `async()` makes it zero nanoseconds
faster. What AMPHP buys is everything *around* the step:

| Problem without async | With AMPHP |
|---|---|
| Waiting for a tick from venue A blocks a tick from venue B | one event loop, N WebSocket connections, ticks handled as they arrive |
| Writing a snapshot to disk/Redis stalls processing | `Amp\File`, `amphp/redis` — I/O never blocks the loop |
| Serving estimates to clients blocks ingest | `WebsocketClientGateway::broadcastText` — non-blocking push |
| One process is one core | `amphp/parallel`: instrument groups in worker processes, IPC over `Channel` |
| Calibrating Q/R over history takes hours | fan-out of MLE/EM evaluations onto a worker pool |
| A backtest reads a file line by line, blocking | `Amp\File\openFile` + `splitLines` |
| A stale feed hangs forever | `TimeoutCancellation` on `receive()` |

Consequence for the design: the core (`src/Domain`) is plain synchronous PHP
with no `Amp\` import at all and is usable without AMPHP; the asynchronous
layer (`src/Infrastructure`) is a thin shell that owns a filter, feeds it and
collects results.

**Non-goals.** Not a general linear-algebra library (only what the filter
needs). Not a trading engine (it produces estimates; decisions live outside).
Not PHP < 8.2 or AMPHP v2. Not a particle filter. Not GPU.

## 2. Non-negotiable rules

Rules 1–9 come from the `opencck/skill-amphp` skill and apply to every PHP
file; rules 10–17 are specific to a filter. **Breaking any of them is a bug,
not a trade-off.**

1. `declare(strict_types=1)` on the first line after `<?php`.
2. `JSON_THROW_ON_ERROR` in every `json_encode` / `json_decode`.
3. No blocking I/O in the event loop: `sleep`, `file_get_contents`, `PDO`,
   `curl_exec` are forbidden. Use `Amp\delay`, `Amp\File\*`,
   `amphp/http-client`, `amphp/redis`. (A deliberate exception, documented in
   place: worker processes read history files with the blocking driver — see
   [docs/async.md](docs/async.md) §10.)
4. A held lock is released in `finally`.
5. An HTTP response body is always fully read (`buffer()` or iteration).
6. `Queue::complete()` / `Queue::error()` is always called, or the consumer
   hangs forever.
7. `Channel::receive()` throws `ChannelException` at EOF, it does not return
   null. The library's worker protocol ends a stream with a `null` sentinel.
8. `await()` only inside a fiber (`async()`, `EventLoop::run()`, `AsyncTestCase`).
9. No AMPHP v2 patterns: `yield $promise`, `Amp\Loop::run()`, `Promise`, `Coroutine`.
10. **A filter step is atomic and synchronous.** No `await()`, `delay()` or I/O
    inside `predict()` / `correct()`. A fiber switch in the middle of a
    covariance update corrupts the state. Asynchrony lives *around* the filter,
    never *inside* it.
11. **One filter, one owner fiber.** A `KalmanFilter` instance is never shared
    between fibers; other fibers talk to it through a command `Queue`, never
    through a mutex (§3, `FilterSession`).
12. **Zero allocations in the hot loop.** Every buffer is allocated in the
    constructor. Value objects (`Measurement`, `StateSnapshot`) live on the API
    boundary only; `stepRaw()` is the object-free path.
13. **Matrices are flat row-major arrays.** `M[i][j]` is `$M[$i * $n + $j]`.
    Nested arrays are forbidden in `Domain\Linalg`, `Domain\Covariance`,
    `Domain\Filter`.
14. **Symmetry of P is exact.** Any update computes the upper triangle and
    mirrors it. The `(I − KH)P` form is forbidden outside the reference tests.
15. **No matrix inversion in production code.** Cholesky plus two triangular
    solves, or the scalar sequential correction.
16. **Time comes from the data, not from a clock.** `dt` is derived from
    exchange timestamps. `microtime()` / `hrtime()` are allowed only in
    benchmarks, logs and the bar clock's `Clock` abstraction.
17. **Every numerical algorithm has a reference test.** The fast implementation
    is compared with a naive textbook one on random data, tolerance ≤ 1e-9.

Two more rules were added after the JIT investigation (ADR-007) and apply to
every numeric kernel:

18. In a reused product, put the constant **last**: `$q * $dt2 * 0.5`, never
    `$q * 0.5 * $dt2`.
19. Never accumulate into an array element with a computed index inside a loop
    nest; accumulate into a local and store once.

## 3. Architecture

Directory layout follows the OpenCCK DDD convention (ADR-002); dependencies
point downwards only and `ArchitectureTest` enforces it.

```
┌─────────────────────────────────────────────────────────────────────┐
│ src/Infrastructure   Async, Ingest, Command, Output, Task           │ ← the only layer that imports Amp\
├─────────────────────────────────────────────────────────────────────┤
│ src/App              Calibration (MLE, EM, Nelder–Mead), Backtest   │
├─────────────────────────────────────────────────────────────────────┤
│ src/Domain           Contract, Entity, Linalg, Covariance, Filter,  │
│                      Model (Generic/Observation/Finance),           │
│                      Discretization, Diagnostics, Smoothing,        │
│                      Adaptive, MultiModel, Metric, Factory,         │
│                      Exception                                      │
└─────────────────────────────────────────────────────────────────────┘
```

**Key contracts** (`Domain\Contract`), all matrices flat row-major:

- `MotionModel` — `stateSize()`, `transition(dt)`, `processNoise(dt)`, `control(dt)`.
- `SparseMotionModel` — `advanceInPlace(&$x, &$P, $dt)`: applies F·(·)·Fᵀ in O(n²) without building F.
- `StationaryModel` — marker: F, Q may be cached per quantised dt.
- `ObservationModel` — `channelCount()`, `channelRow(i)` (sparse), `channelVariance(i)`, `channelName(i)`.
- `CovarianceRepresentation` — the two-phase scalar protocol `prepareScalar(h, r) → s`, `gain()`, `commitScalar(r)` (ADR-003), so the filter can gate or reweight a channel *between* computing `s` and committing.
- `NonlinearMotionModel` / `NonlinearObservationModel` — for EKF/UKF.
- `SerializableModel` — `type()`, `toArray()`, `fromArray()`: lets a model cross a process boundary through `ModelRegistry` / `FilterFactory`.
- `StepRecorder` — priors/posteriors for smoothing and EM.
- `Clock` — `realtimeNs()`, the only place wall-clock time enters.

**Memory layout** (ADR-001). Flat `array<int, float>` beat nested arrays,
`SplFixedArray` and FFI buffers at every size measured; PHP stores
consecutive-integer-key arrays as packed C arrays, so a flat matrix is one
allocation and one bounds check per access.

**Ownership.** `Infrastructure\Async\FilterSession` is the single owner of one
filter. It reads `Measurement | Command | array<Measurement>` from an inbox
`Queue` and executes them strictly in order; snapshots requested through the
same queue are linearised. Producers batch with `MeasurementBatcher`.

**Offloadable kernels** (ADR-005). Everything expensive is also available as a
`public static` deterministic function taking only serialisable arrays —
`FilterBatch::run`, `InnovationLikelihood::evaluateTheta`,
`RauchTungStriebel::smoothConfig`, `Parametrization::applyTheta` and friends,
tagged `@deterministic @offloadable`. They run unchanged in a worker, behind a
queue consumer or an HTTP compute endpoint; `ArchitectureTest` verifies the
contract by reflection.

Full narrative: [docs/theory.md](docs/theory.md) (mathematics, per section, with
the implementing class) and [docs/async.md](docs/async.md) (topology,
back-pressure, workers, shutdown).

## 4. Performance

Targets are measured on one core, PHP 8.3, `opcache.jit=1255`. The current
numbers, the deviations and their causes live in
[README.md § Performance](README.md#performance); `bench/results/baseline.json`
is the machine-readable baseline and CI compares against it. Each entry there
carries the engine it was recorded on (`environments`), because a filtered
`--save-baseline` updates one benchmark and leaves the other eleven as they
were; `--compare` says so when the engines differ, and still gates on the
timing, since a 2× fall back to the interpreter survives any change of engine.
An allocation metric is gated unconditionally — it is absolute, so it needs no
baseline to be checked against 0.

| Metric | n=4 | n=8 | n=16 | n=32 | achieved |
|---|---|---|---|---|---|
| Sequential step | ≤ 3 µs | ≤ 12 µs | ≤ 60 µs | ≤ 300 µs | 1.4 / 9.2 / 53 / 363 µs |
| UD step | ≤ 5 µs | ≤ 20 µs | ≤ 100 µs | ≤ 500 µs | 3.6 / 16.3 / 99 / 718 µs |
| Allocations per step | 0 | 0 | 0 | 0 | 0 everywhere |
| Throughput, no I/O | ≥ 300k/s | ≥ 80k/s | ≥ 15k/s | ≥ 3k/s | 727k / 108k / 19k / 2.8k |
| Event-loop overhead per tick | ≤ 2 µs | | | | 1.1 µs, 0.0 µs batched |
| 8-worker scaling | ≥ 6.5× | | | | 4.0× |

The optimisation levers, in order of effect: the JIT (2–4× on float loops,
mandatory); flat packed arrays; locals instead of properties in hot loops; no
function calls in the inner loop; generated unrolled kernels for n = 2, 4;
the F(dt)/Q(dt) cache on stationary models; `stepRaw()`; and, above n ≈ 12, the
optional OpenBLAS backend (ADR-006). Two engine hazards are documented and
worked around: PHP 8.2/8.3 JIT miscompilations (ADR-007) and the shared OPcache
segment of Windows CLI processes (ADR-008).

## 5. Testing

```
        ┌──────────────┐
        │  Async e2e   │  mock WS → filter → gateway, AsyncTestCase
        ├──────────────┤
        │ Consistency  │  NEES/NIS on synthetic data, 20–50k steps
        ├──────────────┤
        │  Reference   │  fast form == naive textbook form, 1e-9
        ├──────────────┤
        │  Invariants  │  exact symmetry, positive definiteness, dt ≥ 0, sizes
        ├──────────────┤
        │  Unit        │  Cholesky, Householder, exp(M), ClosedForm, …
        └──────────────┘
```

- **Metrics.** `tests/Unit/Metric/` covers the support structures and the
  metric contract (every implementation round-trips through `toArray()`,
  resets cleanly and reports stable keys) — the *shape* of a metric, never its
  arithmetic. The arithmetic is `tests/Reference/Metric/`: one file per
  measurement, all 32 of them, each checking the metric against an independent
  naive implementation written from the published formula inside the test, plus
  values derived by hand where the definition allows one, plus the property the
  metric is chosen for (drift independence, a bound, an invariance, an exact
  recovery from synthetic data). A new measurement without one of these is not
  finished. `MetricCatalogueTest` keeps the published catalogue in step with the
  code, and `MetricBench` asserts zero allocations per update.
- **Reference.** `tests/Reference/NaiveKalmanFilter.php` implements equations
  (1)–(7) with nested arrays, an explicit inverse and the `(I−KH)P` form: slow,
  unstable, obviously correct on short runs. Every production form is compared
  against it on random SPD models, tolerance 1e-9 absolute. A test that needs a
  looser tolerance is reporting a bug in the fast form.
- **Invariants.** `assert()`-guarded checks after every step (exact symmetry,
  Cholesky succeeds, all finite, dt ≥ 0), enabled by `zend.assertions=1` in
  tests and compiled out in production.
- **Consistency.** For every catalogue model, a simulator of the same model:
  mean NEES ∈ [0.9n, 1.1n], mean NIS ∈ [0.9, 1.1] per channel, innovation
  autocorrelation inside ±1.96/√N, gate rejection rate ≈ α. These are the only
  tests that catch *subtle* errors — a wrong `Q` for CV, a forgotten `Δt`, a
  transposed `F`. Regression models use an ensemble protocol (a single path is
  not ergodic).
- **Property-based.** Channel order does not matter; two measurements of one
  channel fuse like one with the harmonic-mean variance; `predict(dt₁);
  predict(dt₂)` == `predict(dt₁+dt₂)`; skipping all channels == blind steps;
  information form == covariance form; EKF/UKF on a linear model == KF; the RTS
  estimate at the last point == the filtered one.
- **Golden files.** A deterministic 1000-step run per model in
  `tests/Golden/*.json`. Any change requires updating the file deliberately,
  with a justification.
- **Architecture.** No `Amp\` in Domain/App, layer direction, `strict_types`,
  `JSON_THROW_ON_ERROR`, no nested matrices in the hot core, the `@offloadable`
  contract, FFI confined to its backend.
- **Static analysis.** Two analysers, both required to be clean: PHPStan at
  level 9 and Psalm at `errorLevel="1"` with unused-variable detection on.
  They catch different things, there is no baseline file, and every suppression
  carries a written reason (ADR-009). Type inference coverage is 99.88 %.

Commands:

```bash
composer test            # everything (1209 tests), zend.assertions=1
composer test:fast       # without @group slow
composer test:blas       # BLAS backend (needs ext-ffi + KALMAN_BLAS_LIB, see tools/fetch-openblas.php)
composer analyse         # PHPStan level 9 AND Psalm errorLevel 1 — both must be clean
composer analyse:phpstan # just PHPStan
composer analyse:psalm   # just Psalm
composer bench           # every benchmark in its own process → bench/results/latest.json
composer bench:baseline  # save/merge the baseline
```

## 6. Status

All ten phases of the original plan are complete: bootstrap; linear algebra and
the sequential core; stable forms (Joseph, UD, square-root); the model
catalogue and discretisation; async ingest; parallelism, calibration and
adaptivity; smoothing, EM and backtests; nonlinear (EKF/UKF) and IMM;
performance; documentation and release preparation. Plus post-plan work:
producer-side batching, the multi-start optimiser, a verified BLAS backend and
bilingual documentation.

Beyond that plan, the **metric layer** is now complete as well: 32
measurements — technical indicators, order-book metrics, volatility estimators
and microstructure measures — each with a streaming object, an `@offloadable`
kernel, a reference test and a runnable example, plus a catalogue generated
from the code. `ROADMAP.md` carries the per-indicator analysis of the reference
PromQL formulas it replaces.

**Current state:** PHPStan level 9 and Psalm errorLevel 1 both clean,
benchmarks recorded in `bench/results/baseline.json`, twelve ADRs,
`composer.json` at version 0.1.0.

**Deliberate deviations from the original plan**, each with an ADR: PHP ≥ 8.2
instead of 8.3 and PHPUnit 9.6 + PHPStan instead of PHPUnit 11 + Psalm
(ADR-004); DDD directory layout instead of a flat `src/` (ADR-002); the
two-phase scalar protocol instead of a single `correctScalar()` (ADR-003).
Unmet performance targets are listed with their causes in the README.

**Open items**, all external to the code: initialise a git repository and tag a
release, publish to Packagist, and cross-link the library from the
`opencck/skill-amphp` README.

## 7. Repository map

```
BRIEF.md                   this document — project context
phpstan.neon / psalm.xml   static analysis: PHPStan level 9, Psalm errorLevel 1 (ADR-009)
CLAUDE.md                  short entry point for coding agents → points here
README.md / README.ru.md   user-facing: install, quick starts, performance
CHANGELOG.md               per-phase history
docs/theory.md   .ru.md    the mathematics, section by section
docs/async.md    .ru.md    the AMPHP layer, topology and rules
docs/models/               one page per market model (EN + RU)
docs/decisions/            ADR-001…012 (not translated: 001–005 RU, 006–012 EN)
src/Domain/                pure numerics, no Amp\ (Filter, Model, Linalg, …, Metric)
src/App/                   calibration and backtest use cases
src/Infrastructure/        everything AMPHP
tests/                     Unit, Reference, Invariant, Property, Consistency,
                           Golden, Async, Architecture
bench/                     benchmarks + run.php (process isolation, baseline gate)
tools/                     generate-kernels.php, fetch-openblas.php, generate-metric-index.php,
                           psalm/, openblas/ (gitignored)
examples/                  etf-live.php, calibrate-pair.php, backtest-trend.php, decoders/,
                           one runnable script per measurement, Support/Synthetic.php,
                           bootstrap.php (autoloader for both layouts — see "What ships")
examples/README.md .ru.md  catalogue of measurements — GENERATED from MetricRegistry
ROADMAP.md                 the metric/indicator layer: analysis, phases, decisions (Russian only)
```

**What ships.** `src/`, `docs/`, `examples/`, `composer.json`, `LICENSE`, both
READMEs, `CHANGELOG.md` and `tools/fetch-openblas.php` reach a consumer;
everything else above is marked `export-ignore` in `.gitattributes`, which
Composer's dist archive honours (1.9 MB installed against 2.9 MB of repository).
`docs/` and `examples/` ship because they are user documentation — the README
links into them and `examples/README.md` is the catalogue of measurements — so
excluding them would leave those links dangling inside `vendor/`. Tests,
benchmarks, analyser configs, the code generators and this file do not ship.
Two consequences to keep in mind when touching `examples/`: it runs from two
layouts, which is what `examples/bootstrap.php` is for, and the
`OpenCCK\Kalman\Examples\` namespace is registered there rather than in
composer.json, because `autoload-dev` does not exist for a consumer.

**Legacy section numbers.** Docblocks across the code cite `§N.M` from the
original design document. They resolve as: **§2.x** → [docs/theory.md](docs/theory.md)
(same order of topics), **§3.x** → [docs/models](docs/models/README.md),
**§4.x** → §3 of this brief, **§5.x** → [docs/async.md](docs/async.md),
**§6.x** → §4 of this brief, **§7.x** → §5 of this brief, **§0.2** → §2 of this
brief, **§1.3** → the performance table in §4.

## 8. Working agreements

**Definition of done** for any unit of work: the checklist is complete;
`composer test` is green with assertions on; `composer bench` shows no
regression beyond the tolerance; `composer analyse` is clean (both analysers); `CHANGELOG.md`
has an entry; every architectural decision has an ADR in `docs/decisions/`.

**When something is unclear:**

- *An AMPHP signature* → the `opencck/skill-amphp` skill (`docs/constructors.md`,
  `docs/namespaces.md`). Never guess. The skill is the authority on AMPHP API;
  this brief is the authority on architecture; [docs/theory.md](docs/theory.md)
  is the authority on the mathematics. A conflict is recorded in an ADR.
- *The mathematics* → [docs/theory.md](docs/theory.md), then the literature in
  §10. Implement the textbook version, check numerically, then optimise.
- *A requirement* → choose the more conservative option (slower but correct),
  record it in an ADR, mark `TODO(perf)`.
- *A test fails intermittently* → this is not a flaky test, it is a numerical
  issue. Do not widen the tolerance; find the source, usually a loss of
  symmetry or positive definiteness.

**Documentation is bilingual.** Every user-facing document has an English file
and a `.ru.md` twin, cross-linked in the second line. ADRs are English only.
When you change one of a pair, change both. This brief and CLAUDE.md are
internal documents and stay English-only; ADRs are decision records and are
not translated either (ADR-001…005 are in Russian, ADR-006…012 in English).

## 9. Glossary

| Term | Meaning |
|---|---|
| State `x` | the hidden vector being estimated |
| Observation `z` | what was actually measured |
| Prior `x̂⁻` | after predict, before the measurement |
| Posterior `x̂` | after correct |
| Innovation `ỹ` | `z − Hx̂⁻`, the gap between forecast and fact |
| `S` | innovation covariance, `HP⁻Hᵀ + R` |
| Gain `K` | how much of the innovation reaches the state |
| NIS | normalised innovation squared, `ỹᵀS⁻¹ỹ ~ χ²(m)` |
| NEES | normalised estimation error squared (needs the truth), `~ χ²(n)` |
| Gating | rejecting a measurement by its NIS |
| Consistency | `P` matching the real error |
| Joseph form | the stable formula for `P` after a correction |
| Sequential correction | per-channel scalar processing with a diagonal `R` |
| UD filter | `P = UDUᵀ`, Bierman–Thornton |
| SRKF | square-root filter, stores `S` with `P = SSᵀ` |
| Information filter | stores `P⁻¹` and `P⁻¹x̂` |
| DARE | discrete algebraic Riccati equation, the steady-state `P` |
| Alpha-beta | the steady-state KF for CV with one channel |
| OOSM | out-of-sequence measurement |
| RTS | Rauch–Tung–Striebel smoother |
| EM | estimating `Q, R` through smoothing |
| IAE | innovation-based adaptive estimation |
| IMM | interacting multiple models, regime switching |
| EKF / UKF | extended / sigma-point filter for nonlinear models |
| Van Loan | exact discretisation through the matrix exponential |
| OU | Ornstein–Uhlenbeck, a mean-reverting process |
| CV / CA | constant velocity / acceleration |
| Fiber | PHP 8.1+ cooperative coroutine, the basis of AMPHP v3 |
| Back-pressure | suspending the producer when the consumer is slow (`Queue::push`) |
| Single-owner | one fiber owns the filter, everyone else goes through its queue |

## 10. References

**Foundations and stable forms**
- Kalman R.E. (1960). A New Approach to Linear Filtering and Prediction Problems. *J. Basic Eng.* 82(1).
- Grewal M.S., Andrews A.P. *Kalman Filtering: Theory and Practice Using MATLAB*, 4th ed. — ch. 6–7: UD, SRKF, numerical stability.
- Bierman G.J. (1977). *Factorization Methods for Discrete Sequential Estimation*.
- Simon D. (2006). *Optimal State Estimation: Kalman, H∞, and Nonlinear Approaches*.
- Bar-Shalom Y., Li X.R., Kirubarajan T. (2001). *Estimation with Applications to Tracking and Navigation* — consistency (NIS/NEES), IMM, OOSM.
- Van Loan C.F. (1978). Computing Integrals Involving the Matrix Exponential. *IEEE Trans. Autom. Control* 23(3).

**Parameter estimation and smoothing**
- Shumway R.H., Stoffer D.S. (1982). An Approach to Time Series Smoothing and Forecasting Using the EM Algorithm. *J. Time Series Anal.* 3(4).
- Mehra R.K. (1970). On the Identification of Variances and Adaptive Kalman Filtering. *IEEE Trans. Autom. Control* 15(2).
- Rauch H.E., Tung F., Striebel C.T. (1965). Maximum Likelihood Estimates of Linear Dynamic Systems. *AIAA J.* 3(8).
- Durbin J., Koopman S.J. (2012). *Time Series Analysis by State Space Methods*, 2nd ed.

**Financial applications**
- Harvey A.C., Ruiz E., Shephard N. (1994). Multivariate Stochastic Variance Models. *Rev. Econ. Stud.* 61(2).
- Diebold F.X., Li C. (2006). Forecasting the Term Structure of Government Bond Yields. *J. Econometrics* 130(2).
- Chan E.P. (2013). *Algorithmic Trading: Winning Strategies and Their Rationale*.
- Aït-Sahalia Y., Mykland P.A., Zhang L. (2005). How Often to Sample a Continuous-Time Process in the Presence of Market Microstructure Noise. *Rev. Financ. Stud.* 18(2).
- Hasbrouck J. (2007). *Empirical Market Microstructure*.
- Stoikov S. (2018). The Micro-Price: A High-Frequency Estimator of Future Prices. *Quant. Finance* 18(12).

**Nonlinear filters**
- Julier S.J., Uhlmann J.K. (2004). Unscented Filtering and Nonlinear Estimation. *Proc. IEEE* 92(3).
- Wan E.A., van der Merwe R. (2000). The Unscented Kalman Filter for Nonlinear Estimation. *Proc. IEEE AS-SPCC*.

**PHP and AMPHP**
- `opencck/skill-amphp` — the authority on the AMPHP v3 API for this project.
- AMPHP v3 documentation — https://amphp.org — only for what the skill does not cover.
- PHP RFC: Fibers — https://wiki.php.net/rfc/fibers.
- PHP JIT configuration — https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.jit.
