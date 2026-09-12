# Multi-venue fair value: venues as sensors

*Русская версия: [multi-venue.ru.md](multi-venue.ru.md).*

Class: `Domain\Model\Finance\MultiVenue`. One asset on V venues with
different liquidity, latency and systematic offsets → one fair price.

## Equations

```
x = [p, v, δ₂..δ_V]ᵀ          n = V + 1      offsets relative to venue 1
F:  (p, v) — CV;  δⱼ ← e^{−dt/τ_δ}·δⱼ       (OU towards zero: offsets are not permanent)
Q:  CV block σ_a²;  δ: σ_δ²·(1 − e^{−2dt/τ_δ})   (stationary variance σ_δ²)
z = [q₁..q_V]ᵀ                m = V
H:  [1 0 0 0 0]
    [1 0 1 0 0]
    [1 0 0 1 0]
    [1 0 0 0 1]
R = diag(r₁..r_V)   r_j = (half_spread_j)² + tick²/12 (+ v̂²·τ_latency,j²)
```

## Signals

- `δ̂ⱼ` — a persistent premium of venue j (arbitrage net of fees); the state
  index is `offsetIndex($j)`.
- `√P_pp` — how reliable the fair value is right now.

## Asynchrony

Channels arrive one at a time — the sequential form treats every tick as a
separate scalar measurement: `Measurement::at($ts, [$venue => $quote])`.
Out-of-sequence ticks are unavoidable → `Infrastructure\Async\ReorderBuffer`
with a window of 1–5 ms (co-location) or 50–200 ms (retail APIs).

## Information form

With distributed ingest every venue sends `HᵀR⁻¹z` and `HᵀR⁻¹H` and the
centre adds them up — `Domain\Filter\InformationFilter::addInformation()`.

## Usage

```php
$mv = new MultiVenue(sigmaA: 0.01, tauDelta: 60.0, sigmaDelta: 0.02, variances: [1e-4, 2e-4, 1.5e-4, 3e-4]);
$filter = $mv->filter(firstPrice: 100.0);
$filter->step(Measurement::at($ts, [2 => 100.03]));   // a tick from venue 3
$offset3 = $filter->meanAt($mv->offsetIndex(2));
```
