# Local linear trend (LLT)

*Русская версия: [local-linear-trend.ru.md](local-linear-trend.ru.md).*

Class: `Domain\Model\Finance\LocalLinearTrend`. Purpose: a denoised price and
its current velocity for trend strategies.

## Equations

```
x = [p, v]ᵀ                          n = 2
F = [1  dt]        Q = σ_a² · [dt³/3  dt²/2]
    [0   1]                    [dt²/2  dt   ]
z = [quote]                          m = 1
H = [1 0]          R = half_spread² + tick²/12
```

This is the model `p̈ = white noise` (continuous white-noise acceleration).
`Q` is the exact discretisation; a diagonal `Q = diag(q_p, q_v)` corresponds to
no continuous process and gives an inconsistent filter — the consistency test
catches it.

## Parameters

| Parameter | Meaning | How to choose |
|---|---|---|
| `sigmaA` | intensity of trend changes | MLE on innovations (`App\Calibration`); start from daily return σ / √(seconds per day) × 3 |
| `halfSpread` | half of the bid-ask spread | from the order book |
| `tick` | price step | the discreteness variance tick²/12 is added to R |

## Usage

```php
$llt = new LocalLinearTrend(sigmaA: 0.5, halfSpread: 0.05, tick: 0.01);
$filter = $llt->filter(firstPrice: 100.0);          // Sequential2 — unrolled kernel, ~0.6 µs per step with the JIT
$filter->step(Measurement::at($tsNs, [0 => $quote]));
$score = LocalLinearTrend::trendScore($filter);      // v̂ / √P_vv — t-statistic of the trend
```

Steady state on regular bars: `AlphaBetaFilter::forConstantVelocity(σ_a, σ_r, dt)`
— the same estimates at constant dt without a covariance, ≈ 0.06 µs per step.

## Extension — Singer

If trends "run out of breath", replace `ConstantVelocity` with
`Generic\Singer(σ, τ)`: `F₂₂ = e^{−dt/τ}`, τ is the trend memory in seconds.

## Diagnostics

- The rolling NIS of the price channel (window 100) should lie in `[0.74, 1.30]`;
  persistently above — `σ_a` or `R` are too small.
- Innovation autocorrelation at lag 1 outside ±1.96/√N — missing dynamics
  (a drift term or Singer is needed).
