# Theory — what the library computes and why it is written this way

*Русская версия: [theory.ru.md](theory.ru.md).*

This is the user-facing edition of the design document's theory chapter.
Every section ends with **In the library**: which classes implement the
idea and what that means for you. Notation matches the code one-to-one.

Contents: [1 State space](#1-the-state-space-model) · [2 Optimality](#2-what-the-kalman-filter-optimises) ·
[3 Equations](#3-the-filter-equations) · [4 Discretisation](#4-discretisation-and-building-q) ·
[5 Stable forms](#5-numerically-stable-forms) · [6 Information filter](#6-the-information-filter) ·
[7 Gaps and out-of-sequence data](#7-missing-asynchronous-and-out-of-sequence-measurements) ·
[8 Gating](#8-outlier-gating-and-robustness) · [9 Consistency](#9-consistency-checks) ·
[10 Observability, steady state, α-β](#10-observability-steady-state-and-the-alpha-beta-filter) ·
[11 Estimating Q and R](#11-where-q-and-r-come-from) · [12 Smoothing](#12-smoothing) ·
[13 EKF / UKF](#13-nonlinear-extensions-ekf-and-ukf) · [14 IMM](#14-interacting-multiple-models) ·
[15 Correlated and coloured noise](#15-correlated-and-coloured-noise) · [16 Market data](#16-market-data-specifics)

---

## 1. The state-space model

A discrete linear Gaussian system:

```
x_k = F_k · x_{k−1} + B_k · u_k + w_k,     w_k ~ N(0, Q_k)     state equation
z_k = H_k · x_k + v_k,                     v_k ~ N(0, R_k)     observation equation
```

| Symbol | Size | Meaning | In a market context |
|---|---|---|---|
| `x_k` | n×1 | hidden state | fair prices, trends, hedge ratios — what is *not* in the order book |
| `z_k` | m×1 | observation | quotes, trades, prices on several venues |
| `F_k` | n×n | state transition | how a price drifts over Δt, how a premium decays |
| `B_k u_k` | n×1 | known control | a known dividend, coupon, rebalance |
| `H_k` | m×n | state → observation | "ETF = Σ wᵢ·pᵢ + premium" |
| `Q_k` | n×n | process-noise covariance | covariance of returns over Δt |
| `R_k` | m×m | measurement-noise covariance | spread, tick discreteness, latency |
| `w_k`, `v_k` | — | white, Gaussian, mutually independent | assumptions the market violates (§16) |

Optimality rests on linearity, Gaussianity, the Markov property, whiteness and
independence of `w` and `v`. Each violation has its remedy (§13–§16).

**In the library.** `Contract\MotionModel` returns `F_k, Q_k, B_k u_k` as
functions of `dt`; `Contract\ObservationModel` returns the rows of `H_k`
(sparse) and the diagonal of `R_k`. The index `k` is real: nothing is cached
unless the model declares itself `StationaryModel`, and even then only per
quantised `dt` (`FilterConfig::withMatrixCache`).

## 2. What the Kalman filter optimises

The filter is recursive Bayesian inference for a Gaussian model: the posterior
`p(x_k | z_{1:k})` stays Gaussian, so the mean `x̂_k` and covariance `P_k`
describe it completely and are the only things updated.

Two equivalent views:

1. **Bayesian.** `predict` marginalises over `x_{k−1}` (a convolution of
   Gaussians); `correct` multiplies the prior by the likelihood and renormalises.
2. **MMSE.** `x̂_k` minimises `E[‖x_k − x̂_k‖²]` among all estimators linear in
   the observations — and among *all* estimators when the noise is Gaussian.

The second view matters for markets: with heavy tails the filter does not
break, it merely stops being optimal and becomes vulnerable to outliers.
That is why gating (§8) is part of the core, not an option.

## 3. The filter equations

**Prediction (time update)**

```
x̂⁻_k = F_k · x̂_{k−1} + B_k · u_k          (1)  prior mean
P⁻_k  = F_k · P_{k−1} · F_kᵀ + Q_k          (2)  prior covariance
```

(2) reads: yesterday's uncertainty pushed through the dynamics, plus the
world's new uncertainty.

**Correction (measurement update)**

```
ỹ_k = z_k − H_k · x̂⁻_k                     (3)  innovation
S_k = H_k · P⁻_k · H_kᵀ + R_k               (4)  innovation covariance
K_k = P⁻_k · H_kᵀ · S_k⁻¹                   (5)  Kalman gain
x̂_k = x̂⁻_k + K_k · ỹ_k                     (6)  posterior mean
P_k  = (I − K_k H_k) · P⁻_k                 (7)  posterior covariance — UNSTABLE FORM
```

`K` is the ratio "how unsure I am about the state" to "how unsure I am about
the measurement". `R → 0` makes the estimate trust the measurement; `R → ∞`
ignores it. The innovation `ỹ_k` is the central diagnostic object: for a
correct model it is white Gaussian with covariance `S_k` (§8–§11 build on it).

**In the library.** Equations (5) and (7) exist only in
`tests/Reference/NaiveKalmanFilter.php`, the textbook oracle the production
forms are compared against to 1e-9. Production code never inverts a matrix;
it uses the sequential scalar form or a Cholesky solver (§5).

## 4. Discretisation and building Q

Market processes are natural in continuous time (Brownian motion,
Ornstein–Uhlenbeck) while ticks arrive irregularly, so `F(Δt)` and `Q(Δt)`
are functions of the interval.

**Continuous model:** `dx = A·x·dt + G·dβ` with spectral density `Q_c`.

**Exact discretisation (Van Loan 1978).** Build the 2n×2n block matrix

```
M = [ −A      G·Q_c·Gᵀ ] · Δt          exp(M) = [ …    F⁻¹·Q ]
    [  0       Aᵀ      ]                          [ 0     Fᵀ   ]
```

then `F = (lower-right block)ᵀ` and `Q = F · (upper-right block)`. The matrix
exponential is scaling-and-squaring with a Padé(6) approximant.

**Closed forms** (no Van Loan needed):

*Random walk (RW) — price without trend*

```
F = [1],   Q = σ²·Δt
```

*Constant velocity / local linear trend (CV) — price + velocity*

```
F = [1  Δt]        Q = σ_a² · [Δt³/3   Δt²/2]
    [0   1]                   [Δt²/2   Δt   ]
```

`σ_a` is the intensity of velocity "kicks": the model is `p̈ = white noise`.
Do not confuse it with a diagonal `Q = diag(q_p, q_v)`, which corresponds to
no continuous process and yields an inconsistent filter.

*Constant acceleration (CA)*

```
F = [1  Δt  Δt²/2]      Q = σ_j² · [Δt⁵/20  Δt⁴/8  Δt³/6]
    [0   1   Δt  ]                  [Δt⁴/8   Δt³/3  Δt²/2]
    [0   0    1  ]                  [Δt³/6   Δt²/2  Δt   ]
```

*Ornstein–Uhlenbeck (OU) — a mean-reverting quantity (pair spread, ETF premium, futures basis)*

```
dx = −θ(x − μ)dt + σ dβ
F = e^{−θΔt},   u = μ(1 − e^{−θΔt}),   Q = σ²/(2θ) · (1 − e^{−2θΔt})
```

`θΔt ≪ 1` degenerates to RW, `θΔt ≫ 1` to white noise around `μ`.

*Singer — velocity with exponentially correlated noise (a trend that "runs out of breath")*

```
dv = −(1/τ)·v·dt + σ dβ
F = [1   τ(1−e^{−Δt/τ})]
    [0   e^{−Δt/τ}     ]
```

with `Q` from Van Loan or the tabulated Singer formula. Good for intraday
impulses.

**Multivariate.** For a price vector with return covariance `Σ`,
`Q_prices = Σ · Δt` is a block of the full `Q`; its off-diagonal entries let
the filter track an untraded instrument through its neighbours.

**In the library.** `Discretization\VanLoan` (general) and
`Discretization\ClosedForm` (RW, CV, CA, OU, Singer — Singer switches to a
cancellation-free series for `Δt/τ < 0.05`). Models recompute `F(Δt), Q(Δt)`
on every new `Δt`; the cache key is `Δt` quantised to `dtQuantum`
(microsecond by default). Closed forms agree with Van Loan to 1e-10 in the
tests.

## 5. Numerically stable forms

The naive equation (7) breaks after thousands of steps: `P` loses symmetry,
then positive definiteness, then produces negative variances and NaN. On tick
data ten thousand steps is minutes. The library implements the whole
hierarchy below behind one interface and lets you choose with `FilterForm`.

### 5.1 Joseph form

```
P_k = (I − K_k H_k) · P⁻_k · (I − K_k H_k)ᵀ + K_k · R_k · K_kᵀ
```

Algebraically identical to (7) for an exact `K`, but symmetric and positive
semi-definite for *any* `K`, even a wrong one. ~2n³ instead of n³.

### 5.2 Sequential scalar correction (the default)

With a diagonal `R` (or one whitened to diagonal, below) channels are
processed one at a time. For channel `i` with row `hᵢ` and variance `rᵢ`:

```
for i = 1..m:
    φ  = P · hᵢᵀ                    n×1, O(n·nnz(hᵢ))
    s  = hᵢ · φ + rᵢ                scalar
    ỹ  = zᵢ − hᵢ · x̂               scalar
    k  = φ / s                      n×1
    x̂  = x̂ + k · ỹ
    P  = P − φ · φᵀ / s             symmetric rank-1 downdate, O(n²)
```

No matrix inversion at all. Symmetry is exact when the upper triangle is
computed and mirrored. A missing channel is a skipped iteration. The result
equals the block form up to round-off — a theorem, not an approximation.

**Whitening a full R.** `R = L·Lᵀ` (Cholesky), then `z' = L⁻¹z`,
`H' = L⁻¹H`, `R' = I`. Done once for a stationary `R`
(`Observation\DecorrelatedObservation`).

Weak spot: the downdate can produce negative eigenvalues when `r ≪ h·P·hᵀ`
(a sensor far more precise than the prior). Above `r ≈ 1e-6 · hPhᵀ` it is
fine; below, use UD or square-root.

### 5.3 Square-root filters

Store `S` with `P = S·Sᵀ`. The condition number of `S` is the square root of
that of `P`, so the filter works with twice the effective precision and `P`
cannot become indefinite by construction.

*Potter (scalar measurements)*

```
φ = Sᵀ · hᵢᵀ
a = 1 / (φᵀφ + rᵢ)
γ = 1 / (1 + sqrt(a · rᵢ))
K = a · S · φ
x̂ = x̂ + K · ỹ
S = S − γ · K · φᵀ
```

Prediction needs a QR (Householder) of `[F·S | Q^{1/2}]ᵀ` — the most
expensive predict, the most stable filter.

### 5.4 UD factorisation (Bierman–Thornton)

`P = U · D · Uᵀ` with `U` unit upper-triangular and `D` diagonal — n(n+1)/2
numbers, no square roots. Bierman's scalar correction is O(n²); Thornton's
predict is a modified weighted Gram–Schmidt (MWGS), O(n³) dense, O(n²) for
sparse `F`. Square-root stability at close to sequential cost: the
recommended form when `r ≪ hPhᵀ` and for `n ≤ 32`.

### 5.5 Comparison

| Form | correct (m channels) | predict | Stability | Use when |
|---|---|---|---|---|
| Naive (7) | n²m + nm² + m³ | 2n³ | ✗ diverges | test oracle only |
| Joseph | ~2n³ + nm² + m³ | 2n³ | ✓ symmetric, PSD for any K | full R, small m |
| Sequential | 2mn² | 2n³ (O(n²) sparse) | ✓ exact symmetry; PD almost always | **default** |
| Potter SRKF | ~3mn² | QR ~4n³ | ✓✓ PD by construction | ill-conditioned, r ≪ hPhᵀ |
| UD Bierman–Thornton | ~2mn² | MWGS ~2n³ (O(n²) sparse) | ✓✓ PD by construction, no sqrt | **precise sensors, n ≤ 32** |

Measured step times are in the README performance table and ADR-001.

**In the library.** `enum FilterForm { Sequential, Joseph, UD, SquareRoot }`;
`Contract\CovarianceRepresentation` with the two-phase scalar protocol
`prepareScalar(h, r) → s`, `gain()`, `commitScalar(r)` (ADR-003) so the
filter can gate or reweight a channel *between* computing `s` and committing.
Implementations: `Covariance\DenseSequential`, `Joseph`, `UD`, `SquareRoot`,
loop-free generated kernels `Kernels\Sequential2/4` for n = 2, 4, and the
optional `BlasDense` (OpenBLAS via FFI, ADR-006).

## 6. The information filter

The dual form stores `Y = P⁻¹` and `ŷ = P⁻¹x̂`. Correction becomes addition:

```
Y_k = Y⁻_k + Hᵀ R⁻¹ H
ŷ_k = ŷ⁻_k + Hᵀ R⁻¹ z
```

— ideal for `m ≫ n` (hundreds of quotes on a dozen states) and for
distributed fusion (every venue sends `HᵀR⁻¹H`, `HᵀR⁻¹z`; the centre adds).
Prediction requires an inversion. `Y₀ = 0` expresses "no prior", which the
covariance form cannot (`P₀ = ∞`).

**In the library.** `Filter\InformationFilter` with `addInformation(h, r, z)`
for remote contributions and `fromCovariance()` / `covariance()` conversions
through Cholesky.

## 7. Missing, asynchronous and out-of-sequence measurements

**Missing channel.** Skip the iteration. The filter predicts, `P` grows by `Q`,
and the remaining channels correct; the missing component is refined through
the off-diagonal of `P`.

**Blind step.** Prediction only. For RW components the variance grows
linearly — an honest statement of ignorance.

**Different source rates.** Every measurement carries its own exchange
timestamp:

```
on a measurement with t_i:
    Δt = t_i − t_last
    Δt > 0:  predict(Δt); t_last = t_i
    Δt = 0:  correct only (several measurements at one instant)
    Δt < 0:  out-of-sequence (below)
    correct(channel i)
```

**Out-of-sequence measurements (OOSM).** A tick from venue B arrives after a
tick from venue A although it happened earlier. Options:

1. *Drop* — the usual HFT choice (`OutOfSequencePolicy::Drop`).
2. *Retrodiction* (Bar-Shalom 2002) — fold the late measurement in through the
   inverse one-step transition; exact one step back (`Filter\Retrodiction`,
   `OutOfSequencePolicy::Retrodict`).
3. *Reorder window* — hold a window `W`, sort inside it, emit with lag `W`
   (`Infrastructure\Async\ReorderBuffer`, a Pipeline operator).

**In the library.** `Entity\Measurement` carries `timestampNs` (int
nanoseconds since the epoch, exchange time), never `dt`; the filter derives
`dt` itself (`KalmanFilter::step` / `stepRaw`). See [async.md](async.md) for
the buffer and the session policies.

## 8. Outlier gating and robustness

For a correct model the innovation is `N(0, S_k)`, so the normalised squared
innovation `d² = ỹᵀ S⁻¹ ỹ` (Mahalanobis distance) is `χ²(m)`; per channel,
`χ²(1)`.

**χ² gate.** Reject when `d² > χ²_{m,1−α}`:

| m | α = 0.01 | α = 0.001 | "3σ" |
|---|---|---|---|
| 1 | 6.63 | 10.83 | 9.00 |
| 2 | 9.21 | 13.82 | — |
| 4 | 13.28 | 18.47 | — |

A rejected measurement changes neither `x̂` nor `P` but is recorded. Several
rejections in a row (> 5) indicate a broken model (split, basket change) rather
than bad data.

**Huber weighting.** Instead of rejecting, down-weight:

```
ψ(u) = u              |u| ≤ c
       c · sign(u)    otherwise,      u = ỹ/√s,  c ≈ 1.345
```

equivalent to inflating `r` to `r_eff = r · (|u|/c)`. Softer than a gate, no
discontinuity, a slight bias under pure Gaussian noise.

**In the library.** `GatingPolicy::none() | sigma(k) | chiSquare(α) | huber(c)`
via `FilterConfig::withGating`; `Entity\ChannelOutcome::weight` is 1 for
accepted, in (0, 1) for Huber-weighted and 0 for rejected channels;
`Diagnostics\ChannelHealth` counts consecutive rejections and raises
`ModelBreakSuspected` past `FilterConfig::modelBreakThreshold`. Quantiles come
from `Diagnostics\ChiSquare` without ext-stats.

## 9. Consistency checks

A filter is *consistent* when its own uncertainty `P` matches the real error.
An overconfident filter (small `P`) rejects good data; an inert one (large `P`)
reacts slowly. Three tests (Bar-Shalom):

**NIS** — normalised innovation squared, the only test available without ground truth:

```
ε_k = ỹ_kᵀ S_k⁻¹ ỹ_k ~ χ²(m),    E[ε_k] = m
```

A window mean over `N` steps has `ε̄ ~ χ²(N·m)/N`; the two-sided 95 % interval
for `N = 100, m = 1` is `[0.74, 1.30]`. Persistently above → Q or R too small;
below → noise overestimated.

**NEES** — normalised estimation error squared, on synthetic data where the truth is known:

```
η_k = (x_k − x̂_k)ᵀ P_k⁻¹ (x_k − x̂_k) ~ χ²(n),    E[η_k] = n
```

The only test that checks `P` directly; mandatory in CI.

**Innovation whiteness.** The autocorrelation `ρ(τ)` must stay within
`±1.96/√N`; significant autocorrelation means missing dynamics or coloured
measurement noise.

**In the library.** `Diagnostics\ConsistencyMonitor` (rolling NIS per channel
and overall, autocorrelation at lags 1..L, χ² bounds), `Diagnostics\Nees` for
tests, `UpdateResult::nis()`. The consistency suite runs every catalogue model
on its own simulation for 50 000 steps and requires the mean NEES within
`[0.9n, 1.1n]`; regression models (pairs, time-varying beta) use an ensemble
protocol because a single path is not ergodic.

## 10. Observability, steady state and the alpha-beta filter

**Observability.** The state is observable when
`O = [H; HF; HF²; …; HF^{n−1}]` has rank `n`. An unobservable component (a
drift with a single "price" channel) is still estimated — slowly, through the
dynamics — and its variance may grow. `Diagnostics\ObservabilityCheck`
reports the rank at model assembly.

**Steady state.** With stationary `F, Q, H, R` the prior covariance converges
to the discrete algebraic Riccati equation (DARE)

```
P = F·P·Fᵀ − F·P·Hᵀ(H·P·Hᵀ + R)⁻¹·H·P·Fᵀ + Q
```

and `K` becomes constant: a step is `x̂ += K(z − Hx̂)`, O(nm).
`Diagnostics\SteadyStateSolver::dare` iterates predict/correct to convergence;
`Filter\SteadyStateFilter` runs with the constant gain and no `P` (it refuses
partial measurements — `K` is computed for the full channel set).

**Alpha-beta filter** — the steady-state KF for a CV model with one channel:

```
p̂ = p⁻ + α(z − p⁻)
v̂ = v⁻ + (β/Δt)(z − p⁻)
```

For the *discrete* white-noise-acceleration model (`Generic\DiscreteWhiteNoiseAcceleration`)
Kalata's closed form is exact in the tracking index `λ = σ_a Δt² / σ_r`:

```
r = (4 + λ − sqrt(8λ + λ²)) / 4,   α = 1 − r²,   β = 2(2 − α) − 4·sqrt(1 − α)
```

For the continuous CV model (§4) the gains come from the DARE instead
(`AlphaBetaFilter::forConstantVelocity`). At ~0.06 µs per step it is the
fastest trend filter possible — first-stage filtering of very high-rate streams.

## 11. Where Q and R come from

Wrong `Q`, `R` are the most common cause of a poorly working filter.

### 11.1 Directly from data

- `R` for a quote: `(half_spread)²` plus the discreteness variance `tick²/12`.
- `Q` for prices: the sample covariance of returns over `Δt` on history,
  cleaned of microstructure noise (realised covariance at an optimal sampling
  frequency, or two-scale realised variance).
- `θ, σ` for OU components: an AR(1) regression of `Δx` on `x` with the
  discretisation correction.

Fast, coarse, enough to start.

### 11.2 Maximum likelihood from innovations

The log-likelihood of the observations decomposes over innovations:

```
ℓ(θ) = −½ Σ_k [ ỹ_kᵀ S_k⁻¹ ỹ_k + ln|S_k| + m·ln(2π) ]
```

Each `ℓ(θ)` is one filter run over the history; maximise with Nelder–Mead
(no gradients, `n_θ ≤ 10`). Positive parameters are log-parametrised, SPD
matrices through their Cholesky factor. This is exactly the quantity the
filter accumulates in `logLikelihood()`; calibrate with gating off, because
rejected measurements leave the sum.

Cost: one run = N steps; an optimiser makes 100–1000 runs. Points of one
simplex iteration are independent — the first place `amphp/parallel` pays off.

### 11.3 Expectation–maximisation (Shumway–Stoffer 1982)

E-step: filter + RTS smoother (§12) give `x̂_{k|N}, P_{k|N}, P_{k,k−1|N}`.
M-step, closed form:

```
Q̂ = (1/N) Σ_k [ P_{k|N} − F P_{k,k−1|N}ᵀ − P_{k,k−1|N} Fᵀ + F P_{k−1|N} Fᵀ
              + (x̂_{k|N} − F x̂_{k−1|N})(x̂_{k|N} − F x̂_{k−1|N})ᵀ ]
R̂ = (1/N) Σ_k [ (z_k − H x̂_{k|N})(z_k − H x̂_{k|N})ᵀ + H P_{k|N} Hᵀ ]
```

The likelihood never decreases, no optimiser is needed and full matrices are
estimated; convergence near the optimum is slow (20–50 iterations).

### 11.4 Online adaptive schemes

- *Innovation-based adaptive estimation* (Mehra 1970): from a window of `N`
  innovations `Ĉ = (1/N) Σ ỹỹᵀ`, then `R̂ = Ĉ − H P⁻ Hᵀ`, `Q̂ ≈ K Ĉ Kᵀ`; `R̂` must
  be projected back onto positive values.
- *Sage–Husa*: exponential forgetting for `Q̂`, `R̂` with a positive floor.
- *Multiple models* (§14): several `Q` hypotheses mixed by posterior regime
  probability.

**In the library.** `App\Calibration\InnovationLikelihood` (an `@offloadable`
kernel), `Parametrization` (`log`, `linear`, `cholesky:n` on dotted config
paths), `NelderMead` (batch evaluator — 4 candidate points per iteration),
`MultiStartNelderMead` (K simplexes in lockstep — 4K points per round, robust
to local optima, fills a worker pool), `Calibrator`, `ExpectationMaximization`; `Infrastructure\Task\LikelihoodTask`
+ `ParallelCalibrator` for the worker fan-out; `Adaptive\InnovationAdaptive`,
`Adaptive\SageHusa`, `Adaptive\AdaptiveNoise`.

## 12. Smoothing

The filter gives `x̂_{k|k}`. In hindsight all data `1..N` are available and
`x̂_{k|N}` is more accurate. The Rauch–Tung–Striebel backward pass:

```
for k = N−1 .. 1:
    C_k     = P_{k|k} F_{k+1}ᵀ (P⁻_{k+1})⁻¹
    x̂_{k|N} = x̂_{k|k} + C_k (x̂_{k+1|N} − x̂⁻_{k+1})
    P_{k|N} = P_{k|k} + C_k (P_{k+1|N} − P⁻_{k+1}) C_kᵀ
```

It needs `x̂_{k|k}, P_{k|k}, x̂⁻_{k+1}, P⁻_{k+1}` for all `k` — O(N·n²) memory
(512 MB for a million ticks at n = 8). Remedies: fixed-lag smoothing over a
window of `L` steps, or spilling the trajectory to disk.

Why a trader cares: the "true" trend on history for labelling, the E-step of
EM, and an honest measure of how much the filter lagged.

**In the library.** `Contract\StepRecorder` + `KalmanFilter::setRecorder()`
feed `Smoothing\FilterTrajectory` (SplFixedArray blocks, optional ring cap);
`Smoothing\RauchTungStriebel` (with lag-one covariances for EM),
`Smoothing\FixedLagSmoother`; `Infrastructure\Output\TrajectoryFile` for the
binary spill; `App\Backtest\Engine` + `Report`.

## 13. Nonlinear extensions: EKF and UKF

When linearity is not enough: a state in log prices observed in prices
(`z = exp(x) + v`); volatility from `z = log(r²)`; order-book imbalance;
option prices as a function of hidden volatility.

**EKF.** Linearise with Jacobians at the current estimate:

```
predict:  x̂⁻ = f(x̂),        F = ∂f/∂x |_{x̂}
correct:  ỹ = z − h(x̂⁻),     H = ∂h/∂x |_{x̂⁻}
```

then the standard equations. Works for mild nonlinearity; diverges when the
linearisation point is far from the truth.

**UKF.** Instead of Jacobians, 2n+1 deterministic sigma points are propagated
through `f`, `h` and the moments are recovered. No derivatives, second-order
accurate (EKF: first). Parameters `α, β, κ` (typically `1e-3, 2, 0`). Sigma
points of strictly positive quantities can go negative — parametrise in logs.

**In the library.** `Contract\NonlinearMotionModel` (`propagate`, `jacobian`,
`processNoise`), `Contract\NonlinearObservationModel` (`project`,
`projectChannel`, `jacobianRow`, `channelVariance`);
`Filter\ExtendedKalmanFilter` reuses every `CovarianceRepresentation`;
`Filter\UnscentedKalmanFilter` processes channels sequentially with sigma
points regenerated from `P` per channel. On a linear model both reproduce the
KF to round-off (tested). `Generic\LinearMotionAdapter` /
`Observation\LinearObservationAdapter` wrap linear models. UKF cost relative
to the KF is 3–13× depending on `n` and the JIT mode (README).

## 14. Interacting multiple models

`M` filters with different hypotheses (`Q_calm`, `Q_volatile`, …) and a Markov
regime transition matrix `Π`. Per step: mix the initial conditions of every
filter from all posteriors with weights from `Π` and the current regime
probabilities; run every filter; update the regime probabilities from the
innovation likelihoods `Λ_j = N(ỹ_j; 0, S_j)`; output the mixture. Fast regime
switching without inflating `Q` in quiet times, at the cost of `M` filters
(`M = 2..3` on markets).

**In the library.** `MultiModel\InteractingMultipleModel`;
`regimeProbabilities()` is a signal in its own right. On synthetic
calm/volatile data a switch is detected within ≤ 5 steps (tested).

## 15. Correlated and coloured noise

**Correlated `w` and `v`** (`E[w_k v_kᵀ] = M ≠ 0`): the same news moves price
and spread. Modified gain `K = (P⁻Hᵀ + M)(HP⁻Hᵀ + R + HM + MᵀHᵀ)⁻¹`.

**Coloured measurement noise** — bid-ask bounce is negatively autocorrelated at
lag 1. Remedy: *state augmentation*, add `ν_k = ρ ν_{k−1} + white` so that
`z = Hx + ν`; the measurement noise becomes state and `R → 0`, which needs the
UD or square-root form.

**In the library.** `Observation\CorrelatedObservation` +
`DecorrelatedObservation` for a full `R`; `Finance\BidAskBounce` is the
augmented-state example (UD by default).

## 16. Market data specifics

**Log prices vs prices.** Price noise is multiplicative, so an additive model
in prices is inconsistent. Either work in `log(price)` (stationary `Q`; linear
relations like `ETF = Σ wᵢ pᵢ` become nonlinear → EKF,
`Finance\LogPriceEtfBasket`) or stay in prices with a heteroscedastic
`Q_k = diag(p̂_k) Σ diag(p̂_k) Δt` (`Generic\HeteroscedasticMotionModel`).

**Tick discreteness.** A price step `tick` adds uniform noise with variance
`tick²/12` to `R`.

**Intraday heteroscedasticity.** Volatility is U-shaped over the day.
`Finance\IntradayProfile` multiplies `Q(t)` by a time-of-day profile; or
estimate online (§11.4).

**Jumps.** News, splits, expiries are not Gaussian. The gate rejects them; a
run of rejections raises `ModelBreakSuspected`; corporate actions are applied
explicitly (`Infrastructure\Command\Split`, `Dividend`, `Rebalance`).

**Latency and timestamps.** Exchange time ≠ receive time. `dt` always comes
from exchange time; `receivedNs − timestampNs` is a monitoring quantity of its
own.

**Engine determinism.** PHP 8.2/8.3 JITs (both `opcache.jit=1205` and
`1255`) miscompile two common float-kernel shapes — a reused `a * const * b`
temporary and an in-place `C[i,j] += a·B[p,j]` accumulation. The library's
kernels avoid both, `Diagnostics\JitSanity::verify()` checks the shapes it
relies on at the first filter construction and `engineBugs()` reports what the
engine miscompiles; `Infrastructure\Task\WorkerPools::likeParent()` spawns
workers with the parent's flags so results are bit-identical across
processes. Follow the same two rules in your own models. See ADR-007.

---

## 17. Estimating a rate of change

Almost every indicator in the metric layer has a derivative attached to it in
practice — the rate at which an imbalance is building, a liquidity density is
draining, a spread is widening. There are three ways to produce that number,
and they are not close to equivalent.

Let the observed series be `y_t = x_t + ε_t` with `ε` white of standard
deviation σ, sampled every T.

**Finite difference.** `(y_t − y_{t−1}) / T` has an error of `σ√2 / T`. The
signal it is trying to recover is of order `ẋ`, so the signal-to-noise ratio is
`ẋT / (σ√2)` — proportional to T. Shortening the step to make the derivative
more local makes the estimate worse, without bound. This is the estimator
fourteen of the reference catalogue's fifty indicators use.

**Least-squares slope over a window** (Prometheus `deriv()`). Fitting a line to
N points spaced T apart gives a slope whose variance is

    Var(b̂) = 12σ² / (T²·N(N²−1))

so the error falls as `N^{-3/2}` — much better. The costs are that the window
length is a free parameter with no principled value, that the fit assumes the
trend is linear across the whole window, that every point is weighted equally
however stale, and that no uncertainty is reported alongside the number.

**Kalman filter with a constant-velocity model.** The state is `[p, v]ᵀ`, the
process noise is white acceleration of intensity σ_a, and the observation is
the series itself (§2.4 gives F and Q). The filter is the minimum-variance
estimator of `v` under that model, and it differs from the regression in three
ways that matter:

- the weighting of past observations follows from the model rather than from a
  chosen window — old data is discounted at the rate at which the velocity can
  actually have changed;
- no window exists, so there is nothing to tune per instrument and nothing to
  re-tune when the tick rate changes;
- `P_vv` comes out of the same recursion, so the rate arrives with a standard
  error. `v̂/√P_vv` is a t-statistic, and a trend can be *tested* rather than
  eyeballed against a threshold.

The steady-state behaviour is the alpha-beta filter of §10, and the trade-off
is governed by the single dimensionless tracking index

    λ = σ_a·√(T³/3) / σ_r

the position uncertainty injected per step divided by the measurement noise.
Small λ smooths heavily and lags; large λ follows a real move within a few
observations and tracks more of the noise with it.

`Metric\Filtered\Derivative` implements this, calibrating σ_r from the spread
of successive differences (for level-plus-white-noise that spread is σ√2) and
deriving σ_a from λ. It applies to any metric, which is why one class replaces
every `dX` in the catalogue. `examples/filtered-derivative.php` measures all
three estimators against a known rate.

**The caveat.** The t-statistic is only as good as the model behind it. A
constant-velocity model is misspecified against a pure random walk, and at a
heavily smoothed setting it will confidently report a velocity that does not
exist. That is not a flaw in the arithmetic; it is what a misspecified model
does, and it is why `Metric\Regime\FlatMarket` exists alongside it with a
measure that has no parameter to get wrong.

---
Further reading: Bar-Shalom, Li & Kirubarajan, *Estimation with Applications
to Tracking and Navigation* (2001); Grewal & Andrews, *Kalman Filtering:
Theory and Practice Using MATLAB* (4th ed.); Bierman, *Factorization Methods
for Discrete Sequential Estimation* (1977); Van Loan, "Computing integrals
involving the matrix exponential" (1978); Shumway & Stoffer (1982); Julier &
Uhlmann (1997), Wan & van der Merwe (2000); Blom & Bar-Shalom (1988).
