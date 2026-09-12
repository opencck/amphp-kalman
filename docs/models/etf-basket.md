# ETF basket — the library's reference model

*Русская версия: [etf-basket.ru.md](etf-basket.ru.md).*

Class: `Domain\Model\Finance\EtfBasket`. Purpose: fair prices of the
constituents and the premium of the fund unit; tracking untraded instruments
through the fund price and the correlations.

## Equations

```
x = [p₁..p_k | v₁..v_k | b]ᵀ              n = 2k + 1
F:  pᵢ ← pᵢ + dt·vᵢ;   vᵢ ← vᵢ;   b ← e^{−θ_b dt}·b
Q:  price block   Σ·dt + σ_a²·dt³/3·I     (Σ — the FULL covariance of returns per second)
    p–v           σ_a²·dt²/2·I
    drift block   σ_a²·dt·I
    premium       σ_b²/(2θ_b)·(1 − e^{−2θ_b dt})      (σ_b²·dt when θ_b = 0)
z = [q₁..q_k, q_ETF]ᵀ                      m = k + 1
H:  rows 1..k — eᵢᵀ;  row k+1 — [w₁..w_k | 0..0 | 1]
R = diag(r₁..r_k, r_ETF)
```

Difference from the original design brief: the drift block and the p–v cross terms come
from the exact CV discretisation (dt³/3, dt²/2, dt) rather than "σ_a² dt³/3"
for the velocities — otherwise the filter is inconsistent (NEES leaves the
interval).

## What it gives

- `b̂` — premium/discount of the unit to its NAV: an arbitrage signal.
- `p̂ᵢ` for instruments without quotes — through Σ and the ETF row.
- `NAV = Σ wᵢ p̂ᵢ` (`EtfBasket::nav()`), its variance `wᵀPw` — `StateSnapshot::linearVariance($weights)`.

The ETF row keeps the state observable even when some constituents have no
quotes (`ObservabilityCheck::forModels`).

## Usage

```php
$etf = new EtfBasket(
    weights: [0.5, 0.3, 0.2],
    sigma: [...],                   // k×k, row-major, SPD
    sigmaA: 0.002, premiumTheta: 0.05, premiumSigma: 0.01,
    quoteVariances: [1e-4, 2e-4, 3e-4], etfVariance: 5e-5,
);
$filter = $etf->filter([100.0, 50.0, 20.0]);
$filter->step(Measurement::at($ts, [0 => 100.02, 3 => 65.9]));   // only q₁ and q_ETF arrived
```

## Rebalancing the basket

A change of `w` is a corporate action: rebuild `H` and inflate `P_bb`:

```php
$filter = new KalmanFilter($etf, $etf->observationFor($newWeights), $snap->mean, $snap->covariance);
$filter->addVariance([$etf->premiumIndex() => 0.01]);
```

In the async layer this is `Infrastructure\Command\Rebalance`.

## Form and cost

Sequential form (R is diagonal), sparse predict `advanceInPlace` in O(k·n)
plus O(n²) mirroring. k = 10 → n = 21, a step ≈ 30 µs with the JIT.
