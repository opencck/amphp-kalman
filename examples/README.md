# Catalogue of measurements — every quantity the library can compute

*Русская версия: [README.ru.md](README.ru.md).*

This page is the single index of everything `opencck/amphp-kalman` can measure:
filter state, model outputs, diagnostics, technical indicators, order-book
metrics, volatility estimators and microstructure measures. Each row names the
quantity, its symbol, its category, the `@offloadable` static kernels that
compute it, what it means for a trading bot, what it means in plain terms, and
the runnable example that demonstrates it.

Back to the project: [README.md](../README.md) ·
Model catalogue: [docs/models/README.md](../docs/models/README.md) ·
Mathematics: [docs/theory.md](../docs/theory.md) ·
Metric-layer plan: [ROADMAP.md](../ROADMAP.md) (Russian only).

## Running an example

Every metric example runs with no arguments at all:

```bash
php examples/momentum-rsi.php
php examples/liquidity-density.php
php examples/filtered-derivative.php
```

The three filter examples that predate the metric layer take a mode flag:

```bash
php examples/etf-live.php --mock
php examples/calibrate-pair.php --synthetic
php examples/backtest-trend.php --synthetic --em
```

None of them needs exchange credentials or a network. They run on the seeded
generators in `Support/Synthetic.php`, so their output is identical between
runs, and each one ends with a few sentences explaining what its numbers show.

## How to read the table

- **Symbol** — the notation used in the code and in the docs. `M-NN` numbers
  come from [ROADMAP §4](../ROADMAP.md#4-каталог-метрик-анализ-каждой) and stay
  stable across versions.
- **Offloadable kernels** — `public static` methods marked
  `@deterministic @offloadable`. They take and return plain arrays only, so
  they can be sent to an `amphp/parallel` worker as-is. `ArchitectureTest`
  enforces the contract ([ADR-005](../docs/decisions/ADR-005-pure-offloadable-kernels.md)).
  A dash means the quantity is read from a filter accessor rather than computed
  by a kernel.
- **Example** — the runnable script that demonstrates the measurement. Every
  row in the generated tables has one, and the build checks that the file is
  there.
- **Input** — what the measurement consumes: ticks, OHLCV bars, an order-book
  snapshot, a trade tape, or several instruments at once.

Categories: price · momentum · trend · volume · liquidity and order book ·
volatility · cross-asset · fair value and regime · microstructure ·
filter state · diagnostics.

---

## 1. Filter state — what the filter itself measures

These are available from any filter, for any model, on every step. They are the
reason the library exists: each one comes with its own uncertainty, which no
indicator library gives you.

| Measurement | Symbol | Category | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Filtered state | `x̂` | filter state | `FilterBatch::run()` | The de-noised value you actually trade on: fair price, trend, spread, premium. Lags far less than a moving average of the same smoothness. | Posterior mean of the hidden state after the measurement update. | [etf-live.php](etf-live.php) |
| State covariance | `P` | filter state | `FilterBatch::run()` | Position sizing and confidence gating: `√P_ii` is the one-sigma band on each component. Widen stops when it grows. | Posterior covariance of the state estimate. | [etf-live.php](etf-live.php) |
| Velocity | `v̂` | trend | `ClosedForm::constantVelocity()` | Trend direction and strength without differencing a noisy series. Replaces every `dX` finite difference. | Rate-of-change component of a constant-velocity or local-linear-trend state. | [backtest-trend.php](backtest-trend.php) |
| Acceleration | `â` | trend | `ClosedForm::constantAcceleration()`, `ClosedForm::singer()` | Detects trend exhaustion before price turns: acceleration flips sign first. | Second derivative component of a constant-acceleration or Singer state. | `examples/trend-acceleration.php` *(M9)* |
| Trend significance | `t_v` | fair value and regime | — | Whether the trend is real or noise: `\|v̂\|/√P_vv` above 2 means significant at 95 %. A principled replacement for ADX thresholds. | t-statistic of the velocity component. | [backtest-trend.php](backtest-trend.php) |
| Innovation | `ỹ` | filter state | `InnovationLikelihood::evaluate()` | Raw surprise: how far the market moved from what the model expected. Mean-reversion entries fire on large innovations. | Difference between the measurement and its prediction. | [calibrate-pair.php](calibrate-pair.php) |
| Innovation variance | `S` | filter state | `InnovationLikelihood::evaluate()` | The current scale of surprise, used to normalise everything else. | Predicted variance of the innovation for a channel. | [calibrate-pair.php](calibrate-pair.php) |
| Innovation z-score | `z` | fair value and regime | — | The pairs-trading signal itself: enter at `\|z\| > 2`, exit near zero. Self-normalising, so thresholds carry across instruments. | Innovation divided by its predicted standard deviation. | [calibrate-pair.php](calibrate-pair.php) |
| Channel weight | `w` | diagnostics | — | Tells you whether a feed is being trusted: 0 means the gate rejected the tick as an outlier. | Robust weight applied to a channel, from the chi-square gate or Huber loss. | [etf-live.php](etf-live.php) |
| Log-likelihood | `ℓ` | diagnostics | `InnovationLikelihood::evaluate()`, `InnovationLikelihood::evaluateTheta()` | The objective you tune parameters against, and the alarm that tells you the model no longer fits the market. | Innovation log-likelihood accumulated over the history. | [calibrate-pair.php](calibrate-pair.php) |
| NIS | `NIS` | diagnostics | — | Online health check: persistent NIS above the chi-square quantile means `R` is too small and the gate will start eating good ticks. | Normalised innovation squared, per step. | [backtest-trend.php](backtest-trend.php) |
| NEES | `NEES` | diagnostics | `Nees::compute()` | Pre-flight check on synthetic data: proves the filter is consistent before real money touches it. | Normalised estimation error squared against a known truth. | `examples/diagnostics-consistency.php` *(M0)* |
| Rejection rate | — | diagnostics | — | The share of the feed being discarded. A jump means a venue outage, a stale book, or a corporate action. | Fraction of measurements rejected by the gate over a window. | [etf-live.php](etf-live.php) |
| Model-break alarm | — | diagnostics | — | Stop trading and re-calibrate: split, index rebalance, or regime change. | Sustained-rejection signal raised by `ChannelHealth`. | [etf-live.php](etf-live.php) |
| Smoothed trajectory | `x̂ᵃ` | filter state | `RauchTungStriebel::smooth()`, `::smoothArrays()`, `::smoothConfig()` | Backtest-only truth: removes filter lag, so you can measure how much of your P&L was signal and how much was delay. | Rauch–Tung–Striebel fixed-interval smoother output. | [backtest-trend.php](backtest-trend.php) |
| Steady-state gain | `K∞` | diagnostics | `SteadyStateSolver::dare()`, `::forModels()` | Free speed: once the gain converges, the covariance loop can be skipped entirely in the hot path. | Solution of the discrete algebraic Riccati equation. | `examples/diagnostics-steady-state.php` *(M0)* |
| Observability rank | — | diagnostics | `ObservabilityCheck::rank()`, `::isObservable()`, `::forModels()` | Catches unidentifiable models at configuration time instead of after an overnight run drifts. | Rank of the observability matrix for a model pair. | `examples/diagnostics-steady-state.php` *(M0)* |
| Estimated `Q`, `R` | `Q̂`, `R̂` | diagnostics | `ExpectationMaximization::fit()`, `::mStepQ()`, `::mStepR()` | Removes the guesswork from noise tuning: the data tells you how noisy it is. | Maximum-likelihood noise covariances from EM with RTS smoothing. | [backtest-trend.php](backtest-trend.php) |

## 2. Market models — measurements that come with a model

Full documentation for each model: [docs/models/README.md](../docs/models/README.md).

| Measurement | Symbol | Category | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Fair price and trend | `μ̂`, `v̂` | price | `ClosedForm::constantVelocity()` | The baseline every other signal is measured against, with far less lag than an equally smooth EMA. | Local-linear-trend level and slope. | [backtest-trend.php](backtest-trend.php) |
| ETF premium / discount | `b̂` | fair value and regime | `ClosedForm::randomWalk()` | The creation/redemption arbitrage signal: premium over NAV net of basket noise. | Bias state of the `EtfBasket` model against the basket NAV. | [etf-live.php](etf-live.php) |
| Hedge ratio | `β̂` | cross-asset | `ClosedForm::ornsteinUhlenbeck()` | Sizes the second leg of a pair. A drifting β is the early warning that the relationship is breaking. | Time-varying regression coefficient estimated as a state. | [calibrate-pair.php](calibrate-pair.php) |
| Spread half-life | `τ½` | cross-asset | `ClosedForm::ornsteinUhlenbeck()` | Sets the holding period and the stop: if half-life exceeds your horizon, do not take the trade. | Mean-reversion time of the Ornstein–Uhlenbeck spread state. | [calibrate-pair.php](calibrate-pair.php) |
| Venue offsets | `δ̂ⱼ` | cross-asset | `ClosedForm::randomWalk()` | Cross-venue arbitrage and stale-quote detection, after consensus fair value is stripped out. | Per-venue bias states of the `MultiVenue` model. | `examples/multivenue-offsets.php` *(M8)* |
| Dynamic beta | `β̂_t` | cross-asset | `ClosedForm::ornsteinUhlenbeck()` | Market-neutral hedging that tracks a beta which actually moves, with a confidence band around it. | `TimeVaryingBeta` regression state and its variance. | `examples/cross-relative-strength.php` *(M8)* |
| Instantaneous volatility | `σ̂` | volatility | — | Risk scaling in real time, one step ahead of realised volatility. | `exp(ĥ/2)` from the `StochasticVolatility` log-variance state. | `examples/volatility-stochastic.php` *(M6)* |
| Microprice | `m̂` | liquidity and order book | — | The price a taker will actually get. Beats the mid as a short-horizon predictor. | Order-book-imbalance-weighted fair value from the `Microprice` model. | `examples/liquidity-microprice.php` *(M5)* |
| Curve factors | `L`, `S`, `C` | fair value and regime | `LU::solve()` | Trades the shape of the curve, not its level: steepeners, flatteners, butterflies. | Nelson–Siegel level, slope and curvature states. | `examples/curve-nelson-siegel.php` *(M8)* |
| Bid-ask bounce | `ν̂` | microstructure | — | Strips the tick-by-tick zig-zag so momentum signals stop firing on the spread. | Bounce component separated from the efficient price. | `examples/micro-bounce.php` *(M7)* |
| Regime probabilities | `π_k` | fair value and regime | — | Switches strategy between calm and volatile markets, with a probability instead of a threshold. | Mode probabilities of the interacting-multiple-model filter. | `examples/regime-imm.php` *(M10)* |

## 3. Indicators and metrics

Everything below is produced by the metric layer. The tables in this section
are **generated** from `Domain\Metric\MetricRegistry` by
`tools/generate-metric-index.php`; `tests/Architecture/MetricCatalogueTest`
fails the build if they drift from the code or if a row points at an example
that does not exist. Do not edit them by hand.

Each metric comes in two forms: a streaming object that costs O(1) per
observation and allocates nothing, and the pure `@deterministic @offloadable`
kernels listed in the table, which compute the whole series at once and can be
sent to an `amphp/parallel` worker as they are. The kernels drive the same
streaming object internally, so the live path and the batch path cannot
disagree.

Where a metric replaces a formula from the reference PromQL catalogue and
departs from it, the class docblock says exactly how and why, and
[ROADMAP §4](../ROADMAP.md#4-каталог-метрик-анализ-каждой) carries the full
analysis.
<!-- BEGIN GENERATED: metrics -->

### Price

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Normalised price | `NP` M-01 | ticks | `NormalizedPrice::zScore()`, `NormalizedPrice::ratio()`, `NormalizedPrice::logRatio()` | A scale-free input for models and thresholds that transfer between instruments. The ratio form the reference uses has no fixed scale, so its thresholds do not transfer. | Price expressed in standard deviations from its rolling mean, as a ratio to that mean, or as the log of that ratio. | [price-normalized.php](price-normalized.php) |
| Price delta | `dP` M-03 | ticks | `PriceDelta::log()`, `PriceDelta::simple()` | The plainest momentum reading, over any horizon. Use the log form so that returns add up across horizons and across a basket. | Return over a fixed lookback, as a plain ratio minus one or as a logarithm. | [price-delta.php](price-delta.php) |
| Mean-price difference | `NdT` M-04 | ticks | `MeanPriceDifference::compute()`, `MeanPriceDifference::overlapFor()` | Kept for compatibility with the PromQL stack. Its two smoothed terms overlap, which cancels signal and keeps noise; prefer the filtered derivative. | Difference between two lagged moving averages of the price, normalised by a longer-run average level. | [price-delta.php](price-delta.php) |

### Momentum

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Relative strength index | `RSI` M-05 | ticks, bars | `Rsi::wilder()`, `Rsi::wilderTimed()`, `Rsi::lagged()`, `Rsi::compute()` | Overbought above 70, oversold below 30 — but only with Wilder smoothing on consecutive closes. The lagged PromQL variant is a momentum series and carries none of those thresholds. | Ratio of average gain to average loss over a window, scaled to 0-100. | [momentum-rsi.php](momentum-rsi.php) |
| Stochastic oscillator | `SO` M-06 | bars | `Stochastic::percentK()`, `Stochastic::percentD()`, `Stochastic::full()` | The signal is the crossing of %K and %D; %K alone, as the reference computes it, carries no crossover signal at all. | Position of the close inside the high-low range of the last N bars, with a smoothed signal line. | [momentum-stochastic.php](momentum-stochastic.php) |
| Commodity channel index | `CCI` M-08 | bars | `Cci::compute()`, `Cci::typicalPrices()` | Readings beyond ±100 flag a move out of the typical range. The calibration only holds with the mean absolute deviation in the denominator, not the standard deviation. | Deviation of the typical price from its moving average, measured in mean absolute deviations and scaled by Lambert's 0.015. | [momentum-cci.php](momentum-cci.php) |
| Normalised momentum | `NAPM` M-09 | ticks | `Momentum::normalized()`, `Momentum::blockReturns()` | Return per unit of risk over the window, so one threshold works across instruments of very different volatility. | Average log return across equal non-overlapping blocks, divided by the standard deviation across those blocks. | [momentum-normalized.php](momentum-normalized.php) |

### Trend

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Moving averages | `SMA/EMA/RMA/WMA` M-10 | ticks, bars | `MovingAverage::sma()`, `MovingAverage::ema()`, `MovingAverage::rma()`, `MovingAverage::wma()`, `MovingAverage::emaTimed()` | The primitive under MACD, RSI, CCI and ADX. The time-based variant is the one that is correct on irregular tick streams, where a fixed alpha lets the averaging horizon breathe with activity. | Weighted averages of a series over a window: equal weights (SMA), exponential decay (EMA), Wilder decay (RMA) and linear weights (WMA). | [trend-moving-averages.php](trend-moving-averages.php) |
| MACD | `MACD` M-11 | ticks, bars | `Macd::compute()`, `Macd::computeTimed()` | Three tradable lines: the zero crossing, the signal crossing and histogram divergence. The reference expression exposes only the histogram, and builds it from flat averages over 8 and 17 samples instead of EMAs of 12 and 26. | Difference between a fast and a slow exponential average of the price, together with an exponential average of that difference. | [trend-macd.php](trend-macd.php) |
| Moving-average spread | `dSMA` M-12 | ticks, bars | `MaSpread::compute()`, `MaSpread::relative()` | A band-pass view of the trend: positive means the short horizon leads the long one. Its finite differences, which the reference also computes, are mostly noise. | Difference between a short and a long moving average, optionally divided by the long one. | [trend-ma-spread.php](trend-ma-spread.php) |
| Average directional index | `ADX/+DI/-DI` M-13 | bars | `Adx::compute()`, `Adx::directional()` | Above 25 trend-follow, below 20 stay out; the DI pair gives the direction. Double Wilder smoothing means it needs roughly ten periods of warm-up before it is meaningful. | Wilder-smoothed strength of directional movement, indifferent to direction, with the directional indicator pair it is built from. | [trend-adx.php](trend-adx.php) |

### Volume

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Volume profile | `NV/NVD` M-14 | trades | `VolumeProfile::compute()`, `VolumeProfile::normalized()`, `VolumeProfile::delta()` | A volume spike confirms a breakout and its absence marks a fade. Computed from executions, because summing a sampled volume gauge measures the scrape interval rather than the market. | Traded volume over a window, standardised against a longer reference window, with the buyer/seller split inside it. | [volume-profile.php](volume-profile.php) |
| VWAP and bands | `VWAP` M-15 | trades | `Vwap::anchoredSeries()`, `Vwap::rollingSeries()`, `Vwap::bands()`, `Vwap::relativeDeviation()` | The execution benchmark: above it you are paying up. The bands turn a level into a mean-reversion signal, which the reference formula cannot produce at all. | Average price weighted by traded volume, anchored to a session or rolling over a window, with volume-weighted dispersion bands. | [volume-vwap.php](volume-vwap.php) |

### Liquidity and order book

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Order-book imbalance | `OBI` M-16 | book | `OrderBookImbalance::imbalance()`, `OrderBookImbalance::ratio()`, `OrderBookImbalance::logRatio()`, `OrderBookImbalance::sideVolume()` | A short-horizon direction predictor. Use the top levels: summing the whole book is dominated by orders that will never execute and by however many levels the venue happens to publish. | Normalised excess of bid-side volume over ask-side volume, over a chosen depth or with distance weighting. | [liquidity-obi.php](liquidity-obi.php) |
| Liquidity density | `LD` M-17 | book | `LiquidityDensity::within()`, `LiquidityDensity::share()`, `LiquidityDensity::notional()` | How much size rests next to the market. A sudden drop precedes fast moves, because makers pull quotes before price moves rather than after. | Volume resting within a basis-point band around the mid price, optionally weighted by distance and normalised by the whole book. | [liquidity-density.php](liquidity-density.php) |
| Side-weighted prices | `WABP/WAAP` M-19 | book | `WeightedPrices::side()`, `WeightedPrices::weightedMid()`, `WeightedPrices::all()` | The weighted mid is short-horizon fair value and predicts the next mid change; whole-side averages describe book geometry, not execution. | Volume-weighted average price of each side, and the size-weighted mid between the best quotes. | [liquidity-weighted-prices.php](liquidity-weighted-prices.php) |
| Spread | `S` M-20 | book, trades | `Spread::quoted()`, `Spread::relativeBps()`, `Spread::effective()`, `Spread::realized()`, `Spread::roll()` | Your round-trip cost floor. The effective spread is what a taker actually pays, and Roll's estimator recovers the spread from the tape alone when no book is available. | Quoted, relative, effective and realised spread, plus Roll's estimator inferred from the autocovariance of price changes. | [liquidity-spread.php](liquidity-spread.php) |
| Order-book slope | `SLOPE` M-35 | book | `BookSlope::side()`, `BookSlope::both()` | A steep slope means shallow price impact: depth piles up close to the touch, so an order walks a short distance. Side asymmetry is itself directional. | Regression coefficient of cumulative depth on relative distance from the mid, computed for each side. | [liquidity-density.php](liquidity-density.php) |
| Cost to trade | `CTT` M-36 | book | `CostToTrade::forNotional()`, `CostToTrade::forSize()`, `CostToTrade::bothSides()` | Answers the question asked before every market order: what will this cost. A signal worth four basis points is not tradable where clearing the size costs six. | Average execution price from walking the book for a given size, expressed as slippage from the mid in basis points. | [liquidity-cost-to-trade.php](liquidity-cost-to-trade.php) |

### Volatility

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Relative price range | `RPR` M-21 | ticks | `RangeVolatility::compute()`, `RangeVolatility::sigmaSeries()`, `RangeVolatility::toSigma()` | A quick volatility reading, but it overstates sigma by about 1.67x until converted. Pre-smoothing the price, as the reference does, clips the very spikes the range is meant to measure. | High-low range over a rolling window divided by the window mean, with the Parkinson conversion to a standard deviation. | [volatility-range.php](volatility-range.php) |
| OHLC volatility | `σ` M-22 | bars | `OhlcVolatility::parkinson()`, `OhlcVolatility::garmanKlass()`, `OhlcVolatility::rogersSatchell()`, `OhlcVolatility::yangZhang()`, `OhlcVolatility::closeToClose()` | Five to eight times more efficient than close-to-close, so a risk limit reacts within the hour instead of the next day. Rogers-Satchell is the one to use on a trending instrument. | Variance estimators that use the intrabar high and low as well as the open and close. | [volatility-ohlc.php](volatility-ohlc.php) |
| Average true range | `ATR` M-23 | bars | `Atr::compute()`, `Atr::trueRanges()` | Stop distance and position size in price units. Expressing both in ATR makes one parameter set work across instruments with completely different price scales. | Wilder-smoothed true range, which counts the gap to the previous close as well as the bar range. | [volatility-atr.php](volatility-atr.php) |
| Realised and EWMA volatility | `RV` M-37 | ticks | `RealizedVolatility::realized()`, `RealizedVolatility::ewma()`, `RealizedVolatility::logReturns()` | The volatility that actually happened. The flat window steps down a window after a shock for no reason; the EWMA decays it smoothly instead. | Root mean square of log returns over a window, and its exponentially weighted counterpart. | [volatility-realized.php](volatility-realized.php) |

### Cross-asset

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Basket return | `ADC` M-24 | multi | `BasketReturn::equalWeight()`, `BasketReturn::volumeWeighted()`, `BasketReturn::inverseVolWeighted()` | The market factor everything else is measured against. Equal weights give the loudest vote to the smallest names, so volume and inverse-volatility weightings are offered; the constituent list is explicit, never an exclusion pattern that a new listing can slip through. | Weighted average of the log returns of several instruments, with the weighted spread between them. | [cross-basket-return.php](cross-basket-return.php) |
| Relative strength | `DD` M-25 | multi | `RelativeStrength::excess()`, `RelativeStrength::betaAdjusted()`, `RelativeStrength::rollingBeta()` | What the asset did that the market did not. The plain difference assumes beta = 1 and therefore ranks assets by leverage, not by strength; subtracting beta times the market removes it, and TimeVaryingBeta does it better still. | Excess return of one instrument over its basket, plain and beta-adjusted, with the rolling beta itself. | [cross-relative-strength.php](cross-relative-strength.php) |
| Market strength index | `MSI` M-26 | multi | `MarketStrength::compute()`, `MarketStrength::breadth()` | How many constituents are rising, combined with how large the move is against its own noise. The reference names this indicator without defining it; the composite here is this library's own definition, not a reproduction. | Share of a basket that is rising, mapped to [−1, +1], blended with its volume-weighted standardised momentum. | [cross-market-strength.php](cross-market-strength.php) |

### Fair value and regime

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Flat-market indicator | `FMI` M-27 | ticks | `FlatMarket::efficiencyRatio()`, `FlatMarket::bandWidth()`, `FlatMarket::compute()` | The gate that keeps trend strategies out of chop, which is where they lose most of their money. Scale-free, so it needs no per-instrument calibration. | Share of the distance travelled that became net displacement, together with the width of the dispersion band around the mean. | [regime-flat-market.php](regime-flat-market.php) |

### Microstructure

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Order-flow imbalance | `OFI` M-29 | book | `OrderFlowImbalance::event()`, `OrderFlowImbalance::compute()` | One of the strongest short-horizon price predictors published, with a roughly linear impact whose slope falls with depth. It is an observation of fair value that arrives before the prints do. | Net order flow at the best bid and ask across consecutive book updates, summed over a window. | [micro-ofi.php](micro-ofi.php) |
| Flow toxicity | `VPIN` M-30 | trades | `Vpin::compute()`, `Vpin::fromBuckets()` | Tells a market maker when to widen or step away: it rises before spreads widen and volatility bursts. Treat it as a regime flag rather than a probability. | Average absolute buy-sell imbalance across buckets of equal traded volume, normalised to a fraction. | [micro-vpin.php](micro-vpin.php) |
| Price impact | `λ/ILLIQ` M-31 | trades | `PriceImpact::kyleLambda()`, `PriceImpact::amihud()`, `PriceImpact::compute()` | Caps your order size: impact times size is a cost paid before any signal pays off. An edge of four basis points cannot trade a size whose impact is six. | Regression slope of price change on signed order flow, and the average absolute return per unit of turnover. | [micro-price-impact.php](micro-price-impact.php) |
| Trade classification | `b` M-32 | trades | `TradeClassifier::leeReady()`, `TradeClassifier::tickRule()`, `TradeClassifier::bulkVolume()` | The prerequisite for every flow measure: who lifted whom. Public tapes rarely publish it, so it is inferred by a stated algorithm rather than assumed. | Buyer- or seller-initiated label for each trade, from the quote it crossed or from the direction of the price change. | [micro-trade-sign.php](micro-trade-sign.php) |

### Filter state

| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |
|---|---|---|---|---|---|---|
| Filtered derivative | `dX` M-02 | any metric | `Derivative::ofSeries()`, `Derivative::rateOf()` | Replaces all fourteen finite differences in the reference catalogue with one estimator that has strictly lower error and reports its own uncertainty, so a rate can be tested for significance. | Any metric passed through a constant-velocity Kalman filter to obtain its level, its rate of change and the uncertainty of both. | [filtered-derivative.php](filtered-derivative.php) |

<!-- END GENERATED: metrics -->

---

## Decoders

`examples/decoders/` holds two reference `TickDecoder` implementations that turn
exchange WebSocket frames into `Measurement` objects:
[`BinanceTradeDecoder.php`](decoders/BinanceTradeDecoder.php) and
[`CoinbaseTickerDecoder.php`](decoders/CoinbaseTickerDecoder.php).
They are the entry point for every measurement above that consumes ticks or
trades. Streaming details: [docs/async.md](../docs/async.md).

## Contributing a measurement

A measurement is complete when it has a pure `@offloadable` kernel, a streaming
object that allocates nothing per tick, a test proving the two agree
bit-for-bit, a reference test against an independent implementation, an entry
in `MetricRegistry`, a row in both language versions of this table, and a
runnable example. The full definition of done is in
[ROADMAP §9](../ROADMAP.md#9-определение-готовности).
