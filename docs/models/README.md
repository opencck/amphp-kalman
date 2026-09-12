# Market-model catalogue

*Русская версия: [README.ru.md](README.ru.md).*

Every model is a class in `OpenCCK\Kalman\Domain\Model\Finance\` that either
implements `MotionModel` (plus `SparseMotionModel`, `StationaryModel`,
`SerializableModel`) or hands out a motion/observation pair through a
factory method. Each one comes with a consistency test on its own simulation
(`tests/Consistency/FinanceModelsConsistencyTest.php`), a golden file
(`tests/Golden/*.json`) and a page in this catalogue. The mathematics behind
the models is in [docs/theory.md](../theory.md).

| Model | Class | n | m | Default form | Page |
|---|---|---|---|---|---|
| Local linear trend | `LocalLinearTrend` | 2 | 1 | Sequential (`Sequential2` kernel) / alpha-beta | [local-linear-trend.md](local-linear-trend.md) |
| ETF basket | `EtfBasket` | 2k+1 | k+1 | Sequential + sparse predict | [etf-basket.md](etf-basket.md) |
| Pairs trading | `PairsHedge` | 2–3 | 1 | UD | [pairs-hedge.md](pairs-hedge.md) |
| Multi-venue fair value | `MultiVenue` | V+1 | V | Sequential + `ReorderBuffer` | [multi-venue.md](multi-venue.md) |
| Time-varying beta | `TimeVaryingBeta` | 2 | 1 | Sequential | [time-varying-beta.md](time-varying-beta.md) |
| Stochastic volatility | `StochasticVolatility` | 1 | 1 | Sequential (QML) | [stochastic-volatility.md](stochastic-volatility.md) |
| Order-book microprice | `Microprice` | 2 | 2 | Sequential, R changes every tick | [microprice.md](microprice.md) |
| Nelson–Siegel yield curve | `NelsonSiegel` | 3 | 8–15 | Sequential (m ≫ n → `InformationFilter`) | [nelson-siegel.md](nelson-siegel.md) |
| Bid-ask bounce | `BidAskBounce` | 3 | 1 | UD / SquareRoot | [bid-ask-bounce.md](bid-ask-bounce.md) |

Also in the library but documented in their class comments: `StochasticVolatilityLeverage`
(UKF, leverage effect), `LogPriceEtfBasket` (EKF in log prices),
`InteractingMultipleModel` (calm/volatile regime switching).

Common notation: `dt` is the interval between measurements in seconds,
computed from exchange timestamps; every `σ` is the intensity of a
continuous-time noise (per √second); `r` is a measurement-noise variance.

## Simulation results (20 000 steps, `FinanceModelsConsistencyTest`)

A filter is consistent when its mean NEES lies in [0.9n, 1.1n] and its mean
NIS per channel in [0.9, 1.1]. All nine models pass these bounds on a
simulation of their own equations; the exact values are printed when the test
fails.

| Model | Check | dt | Simulation detail |
|---|---|---|---|
| LLT | NEES ∈ [1.8, 2.2], NIS, innovation whiteness, χ² gate | 50–150 ms, irregular | 50 000 steps |
| ETF (k=3) | NEES ∈ [6.3, 7.7] | 0.5 s | random subset of quotes at every step |
| ETF (k=2, UD) | NEES ∈ [4.5, 5.5] | 0.5 s | UD form |
| MultiVenue (V=4) | NEES ∈ [4.5, 5.5] | 0.1 s | asynchronous channels |
| Nelson–Siegel (m=8) | NEES ∈ [2.7, 3.3] | 1 day | all maturities every day |
| SV | NEES ∈ [0.9, 1.1] | 60 s | Gaussian proxy noise π²/2 |
| Bounce (UD) | NEES ∈ [2.7, 3.3] | 0.5 s | R = tick²/12 ≪ hPhᵀ |
| Pairs | NEES ∈ [1.8, 2.2] | 1 s | H_t changes every step |
| Beta | NEES ∈ [1.8, 2.2] | 60 s | H_t = [1, r_m] |
| Microprice | NEES ∈ [1.8, 2.2] | 0.2 s | r_micro recomputed from depth |
