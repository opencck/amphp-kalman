# Bid-ask bounce as coloured noise (state augmentation)

*Русская версия: [bid-ask-bounce.ru.md](bid-ask-bounce.ru.md).*

Class: `Domain\Model\Finance\BidAskBounce`. Demonstrates §15 of the theory:
the microstructure noise of trades is negatively autocorrelated at lag 1 — it
is not white measurement noise but part of the state.

```
x = [p, v, ν]ᵀ            ν — autocorrelated measurement noise
F:  CV block;  ν ← ρ^(dt/τ)·ν        (AR(1) in tick time, τ — mean trade interval; ρ ≈ −0.3..−0.5)
Q:  CV block σ_a²;  q_ν = σ_ν²·(1 − ρ^(2dt/τ))   (stationary Var(ν) = σ_ν²)
z = [trade_price],  H = [1 0 1],  R = tick²/12   (discreteness only)
```

## Why UD

`R → 0` relative to `hPhᵀ`: the scalar rank-1 downdate of the sequential form
loses positive definiteness (`StabilityStressTest`), while UD and SquareRoot
keep P ≥ 0 by construction. `filter()` sets `FilterForm::UD`.

## Usage

```php
$model = new BidAskBounce(sigmaA: 0.02, sigmaNu: 0.004, rho: -0.4, tickInterval: 0.5, tick: 0.01);
$filter = $model->filter(firstPrice: 50.0);
$filter->step(Measurement::at($ts, [0 => $tradePrice]));
$fair = $filter->meanAt(0);     // price without the bounce
$bounce = $filter->meanAt(2);   // the current ν component
```
