# Stochastic volatility (QML, Harvey–Ruiz–Shephard)

*Русская версия: [stochastic-volatility.ru.md](stochastic-volatility.ru.md).*

Class: `Domain\Model\Finance\StochasticVolatility`. Online volatility
estimation that reacts faster than GARCH.

## Model

```
r_t = e^{h_t/2} ε_t,   h_t = μ + φ(h_{t−1} − μ) + η_t
z_t = ln r_t² = h_t + ln ε_t²        E[ln ε²] = −1.2704,  Var = π²/2 ≈ 4.9348
```

After the transformation the model is linear — no EKF needed. The noise
`ln ε²` is not Gaussian, but the KF remains the best linear filter
(quasi-maximum likelihood).

```
x = [h]                                  n = 1  (log variance per bar)
F = φ^{dt/bar},  u = μ(1 − F),  Q = σ_η²·(1 − F²)/(1 − φ²)   — the equivalent OU at arbitrary dt
z = ln(r² + c) + 1.2704                  m = 1, centred; c is a small floor against ln 0
H = [1],  R = π²/2
```

## Usage

```php
$sv = new StochasticVolatility(mu: -9.0, phi: 0.98, sigmaEta: 0.15, barSeconds: 60.0);
$filter = $sv->filter();
foreach ($bars as [$ts, $return]) {
    $filter->step(Measurement::at($ts, [0 => StochasticVolatility::observe($return)]));
}
$vol = StochasticVolatility::volatility($filter);   // e^{ĥ/2} per bar
```

## Limitations and extensions

- `r_t = 0` → `ln 0`: `observe()` uses the floor `c = 1e-12`; the alternative
  is to skip the channel.
- Leverage (correlation between ε and η): the UKF variant
  `StochasticVolatilityLeverage`.
- The consistency test uses Gaussian proxy noise with variance π²/2 — it
  checks the linear-Gaussian mechanics; with the real ln χ²₁ noise the NIS
  stays around 1 on average, but the innovation distribution is skewed.
