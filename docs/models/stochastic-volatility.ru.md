# Стохастическая волатильность (QML, Harvey–Ruiz–Shephard)

*English version: [stochastic-volatility.md](stochastic-volatility.md).*

Класс: `Domain\Model\Finance\StochasticVolatility`. Онлайн-оценка
волатильности, реагирующая быстрее GARCH.

## Модель

```
r_t = e^{h_t/2} ε_t,   h_t = μ + φ(h_{t−1} − μ) + η_t
z_t = ln r_t² = h_t + ln ε_t²        E[ln ε²] = −1.2704,  Var = π²/2 ≈ 4.9348
```

После преобразования модель линейна — EKF не нужен. Шум `ln ε²` не гауссов,
но KF остаётся лучшим линейным фильтром (квази-MLE).

```
x = [h]                                  n = 1  (лог-дисперсия за бар)
F = φ^{dt/bar},  u = μ(1 − F),  Q = σ_η²·(1 − F²)/(1 − φ²)   — эквивалентный OU при произвольном dt
z = ln(r² + c) + 1.2704                  m = 1, центрировано, c — малый floor против ln 0
H = [1],  R = π²/2
```

## Использование

```php
$sv = new StochasticVolatility(mu: -9.0, phi: 0.98, sigmaEta: 0.15, barSeconds: 60.0);
$filter = $sv->filter();
foreach ($bars as [$ts, $return]) {
    $filter->step(Measurement::at($ts, [0 => StochasticVolatility::observe($return)]));
}
$vol = StochasticVolatility::volatility($filter);   // e^{ĥ/2} за бар
```

## Ограничения и расширения

- `r_t = 0` → `ln 0`: `observe()` использует floor `c = 1e-12`; альтернатива — пропустить канал.
- Левередж (корреляция ε и η) — UKF-вариант `StochasticVolatilityLeverage`.
- Тест консистентности использует гауссов прокси-шум с дисперсией π²/2 —
  он проверяет линейно-гауссову механику; при реальном шуме ln χ²₁ NIS
  остаётся около 1 в среднем, но распределение невязок скошено.
