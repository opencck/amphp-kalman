# Мультивенью fair value: слияние площадок как сенсоров

*English version: [multi-venue.md](multi-venue.md).*

Класс: `Domain\Model\Finance\MultiVenue`. Один актив на V площадках с разной
ликвидностью, латентностью и систематическим смещением → единая справедливая цена.

## Уравнения

```
x = [p, v, δ₂..δ_V]ᵀ          n = V + 1      смещения относительно площадки 1
F:  (p, v) — CV;  δⱼ ← e^{−dt/τ_δ}·δⱼ       (OU к нулю: смещения не постоянны)
Q:  CV-блок σ_a²;  δ: σ_δ²·(1 − e^{−2dt/τ_δ})   (стационарная дисперсия σ_δ²)
z = [q₁..q_V]ᵀ                m = V
H:  [1 0 0 0 0]
    [1 0 1 0 0]
    [1 0 0 1 0]
    [1 0 0 0 1]
R = diag(r₁..r_V)   r_j = (half_spread_j)² + tick²/12 (+ v̂²·τ_latency,j²)
```

## Сигналы

- `δ̂ⱼ` — устойчивая дороговизна площадки j (арбитраж с учётом комиссий);
  индекс состояния — `offsetIndex($j)`.
- `√P_pp` — насколько надёжна fair value сейчас.

## Асинхронность

Каналы приходят по одному — последовательная форма обрабатывает каждый тик
как отдельное скалярное измерение: `Measurement::at($ts, [$venue => $quote])`.
Внеочередные тики неизбежны → `Infrastructure\Async\ReorderBuffer` с окном
1–5 мс (колокейшен) или 50–200 мс (розничные API).

## Информационная форма

При распределённом ингесте каждая площадка шлёт `HᵀR⁻¹z` и `HᵀR⁻¹H`, центр
складывает — `Domain\Filter\InformationFilter::addInformation()`.

## Использование

```php
$mv = new MultiVenue(sigmaA: 0.01, tauDelta: 60.0, sigmaDelta: 0.02, variances: [1e-4, 2e-4, 1.5e-4, 3e-4]);
$filter = $mv->filter(firstPrice: 100.0);
$filter->step(Measurement::at($ts, [2 => 100.03]));   // тик с площадки 3
$offset3 = $filter->meanAt($mv->offsetIndex(2));
```
