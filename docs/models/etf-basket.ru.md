# Корзина ETF — эталонная модель библиотеки

*English version: [etf-basket.md](etf-basket.md).*

Класс: `Domain\Model\Finance\EtfBasket`. Задача — справедливые цены компонент
и премия пая; ведение неторгуемых бумаг через цену фонда и корреляции.

## Уравнения

```
x = [p₁..p_k | v₁..v_k | b]ᵀ              n = 2k + 1
F:  pᵢ ← pᵢ + dt·vᵢ;   vᵢ ← vᵢ;   b ← e^{−θ_b dt}·b
Q:  блок цен      Σ·dt + σ_a²·dt³/3·I     (Σ — ПОЛНАЯ ковариация доходностей за секунду)
    p–v           σ_a²·dt²/2·I
    блок сносов   σ_a²·dt·I
    премия        σ_b²/(2θ_b)·(1 − e^{−2θ_b dt})      (σ_b²·dt при θ_b = 0)
z = [q₁..q_k, q_ETF]ᵀ                      m = k + 1
H:  строки 1..k — eᵢᵀ;  строка k+1 — [w₁..w_k | 0..0 | 1]
R = diag(r₁..r_k, r_ETF)
```

Отличие от исходного проектного документа: блок сносов и перекрёстные члены p–v взяты из точной
CV-дискретизации (dt³/3, dt²/2, dt), а не «σ_a² dt³/3» для скоростей — иначе
фильтр несогласован (NEES уходит из интервала).

## Что даёт

- `b̂` — премия/дисконт пая к NAV: сигнал арбитража.
- `p̂ᵢ` для бумаг без котировок — через Σ и строку ETF.
- `NAV = Σ wᵢ p̂ᵢ` (`EtfBasket::nav()`), дисперсия `wᵀPw` — `StateSnapshot::linearVariance($weights)`.

Строка ETF делает состояние наблюдаемым даже при отсутствии котировок по части бумаг
(`ObservabilityCheck::forModels`).

## Использование

```php
$etf = new EtfBasket(
    weights: [0.5, 0.3, 0.2],
    sigma: [...],                   // k×k, row-major, SPD
    sigmaA: 0.002, premiumTheta: 0.05, premiumSigma: 0.01,
    quoteVariances: [1e-4, 2e-4, 3e-4], etfVariance: 5e-5,
);
$filter = $etf->filter([100.0, 50.0, 20.0]);
$filter->step(Measurement::at($ts, [0 => 100.02, 3 => 65.9]));   // пришли только q₁ и q_ETF
```

## Ребалансировка корзины

Изменение `w` — корпоративное событие: пересобрать `H` и раздуть `P_bb`:

```php
$filter = new KalmanFilter($etf, $etf->observationFor($newWeights), $snap->mean, $snap->covariance);
$filter->addVariance([$etf->premiumIndex() => 0.01]);
```

В async-слое это `Infrastructure\Command\Rebalance`.

## Форма и стоимость

Последовательная форма (R диагональна), разреженный predict `advanceInPlace` за
O(k·n) + зеркалирование O(n²). k = 10 → n = 21, шаг ≈ 30 мкс с JIT.
