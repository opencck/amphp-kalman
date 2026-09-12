# ADR-012 — Windows are counted in observations, with a time-based form where it matters

**Status:** accepted

## Context

Classical indicators are defined on bars: RSI(14) means fourteen bars, whatever
a bar is. This library's core runs on ticks with exchange timestamps, and `dt`
comes from those timestamps precisely because tick arrival is not uniform
(BRIEF §2 p.6).

That leaves a genuine conflict. An "EMA(20)" on a tick stream has an averaging
horizon of twenty *observations*, which is two seconds during a burst and two
minutes in a lull. The indicator's memory therefore breathes with market
activity, and a threshold calibrated at one activity level does not hold at
another. The correct form on an irregular stream replaces the fixed smoothing
factor with

    α = 1 − exp(−Δt/τ)

which averages over τ seconds of market time regardless of how many ticks
arrive in it.

The counter-argument is just as real: a trader comparing the library's RSI
against the one in their terminal needs the bar-based definition, to the digit.
An indicator that silently used a different clock than every charting package
would be wrong in the only sense that matters to its user.

The ROADMAP's original principle M2 said windows are specified in seconds, full
stop. Implementation showed that to be too strong: the stochastic oscillator,
CCI, ADX and every OHLC volatility estimator are defined on bars by
construction — they need a high and a low, which only exist once an interval
has been closed.

## Decision

1. **The default window unit is observations**, so a metric fed bars reproduces
   the standard definition exactly and can be checked against published values.
2. **Metrics whose definition is recursive also offer a time-based form**, as a
   separate named kernel with τ in seconds: `MovingAverage::emaTimed()`,
   `Rsi::wilderTimed()`, `Macd::computeTimed()`. They are not the default,
   because the default has to be the definition everyone else implements.
3. **Bars are produced explicitly, never implied.**
   `Domain\Metric\Support\BarAggregator` turns a tick or trade stream into bars
   on a time, volume or tick clock. Time bars align to absolute epoch
   boundaries rather than to the first tick seen, so two processes fed the same
   stream cut it identically. A bar is emitted only when the observation that
   opens the next one arrives; the partial bar in progress is available through
   `flush()` and never reaches an indicator by accident.
4. **One metric runs on neither clock.** VPIN is defined in volume time, and
   `Support\VolumeClock` implements exactly that: buckets of equal traded
   volume, with a trade that overflows a bucket split across the boundary.
5. **Every metric states its unit in its constructor signature** — `period`,
   `window` and `lag` count observations; `tauSeconds` and `bandBps` carry
   their unit in the name.

## Consequences

- `Rsi::wilder($closes, 14)` on 14 one-minute bars is the RSI a terminal shows,
  digit for digit. That is checkable, and it is checked.
- On a raw tick stream the same call is a fourteen-tick RSI, whose horizon
  depends on activity. The class docblock says so, and `wilderTimed()` is one
  line away for callers who want market time.
- Choosing a bar clock is now an explicit decision with three options rather
  than an assumption. Volume and tick bars sample the market at a constant rate
  of activity, which makes their returns far better behaved than calendar bars'
  — the same reasoning that puts VPIN in volume time.
- Comparing two metrics requires checking they use the same clock. The
  catalogue's Input column is the first place to look.
