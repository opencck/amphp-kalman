# Pairs trading: a time-varying hedge ratio

*Русская версия: [pairs-hedge.ru.md](pairs-hedge.ru.md).*

Class: `Domain\Model\Finance\PairsHedge`. The cointegration regression
`y_t = α_t + β_t x_t + ε_t` with time-varying coefficients — the classic use
of the KF in statistical arbitrage.

## Equations

```
x = [α, β]ᵀ                 n = 2
F = I                       the coefficients are random walks
Q = diag(q_α, q_β)·dt       small: 1e-6..1e-4 relative to the scale
z = [y_t]                   m = 1
H_t = [1, x_t]              ← TIME-VARYING: setRegressor(x_t) before every step
R = σ_ε²
```

The innovation `ỹ_t = y_t − α̂ − β̂x_t` is the spread of the pair and `√S_t`
its current scale. Signal: `z-score = ỹ/√S` (`PairsHedge::zScore($filter)`),
enter at |z| > 2, exit at |z| < 0.5. `S_t` widens automatically when β̂ is
uncertain.

## Extension: the spread as an OU process

```
x = [α, β, s]ᵀ,   s ← e^{−θ dt}·s,   z = α + βx_t + s
```
`new PairsHedge($qAlpha, $qBeta, $sigmaEps, spreadTheta: θ, spreadSigma: σ_s)`;
`spreadHalfLife()` = ln2/θ — the half-life of the spread directly.

## Form

`q_β` is tiny → P is badly conditioned → UD by default (`filter()` sets
`FilterForm::UD`).

## Usage

```php
$pairs = new PairsHedge(qAlpha: 1e-6, qBeta: 1e-8, sigmaEps: 0.05);
$filter = $pairs->filter(alpha0: 0.0, beta0: 1.0);
foreach ($ticks as [$ts, $x, $y]) {
    $pairs->setRegressor($x);
    $filter->step(Measurement::at($ts, [0 => $y]));
    $z = PairsHedge::zScore($filter);
}
```

Workers: `MutableObservation` is not serialisable; for `FilterBatch` and
workers ship x_t inside the tick (`PairsHedge::tick()` produces the raw tick
with its `rows`) and let the consumer set the regressor.

## Calibration

`q_α, q_β, σ_ε` by MLE on innovations (`App\Calibration\NelderMead` +
`InnovationLikelihood`); see `examples/calibrate-pair.php`.
