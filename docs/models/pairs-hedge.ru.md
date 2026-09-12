# Парный трейдинг: динамический hedge-ratio

*English version: [pairs-hedge.md](pairs-hedge.md).*

Класс: `Domain\Model\Finance\PairsHedge`. Коинтеграционная регрессия
`y_t = α_t + β_t x_t + ε_t` с меняющимися коэффициентами — классическое
применение KF в статарбитраже.

## Уравнения

```
x = [α, β]ᵀ                 n = 2
F = I                       коэффициенты — случайные блуждания
Q = diag(q_α, q_β)·dt       малые: 1e-6..1e-4 относительно масштаба
z = [y_t]                   m = 1
H_t = [1, x_t]              ← ЗАВИСИТ ОТ ВРЕМЕНИ: setRegressor(x_t) перед каждым шагом
R = σ_ε²
```

Невязка `ỹ_t = y_t − α̂ − β̂x_t` — это спред пары, `√S_t` — его текущая шкала.
Сигнал: `z-score = ỹ/√S` (`PairsHedge::zScore($filter)`), вход при |z| > 2,
выход при |z| < 0.5. `S_t` расширяется автоматически, когда β̂ неуверен.

## Расширение: спред как OU-процесс

```
x = [α, β, s]ᵀ,   s ← e^{−θ dt}·s,   z = α + βx_t + s
```
`new PairsHedge($qAlpha, $qBeta, $sigmaEps, spreadTheta: θ, spreadSigma: σ_s)`;
`spreadHalfLife()` = ln2/θ — период полураспада спреда напрямую.

## Форма

`q_β` очень мало → P плохо обусловлена → по умолчанию UD (`filter()` ставит `FilterForm::UD`).

## Использование

```php
$pairs = new PairsHedge(qAlpha: 1e-6, qBeta: 1e-8, sigmaEps: 0.05);
$filter = $pairs->filter(alpha0: 0.0, beta0: 1.0);
foreach ($ticks as [$ts, $x, $y]) {
    $pairs->setRegressor($x);
    $filter->step(Measurement::at($ts, [0 => $y]));
    $z = PairsHedge::zScore($filter);
}
```

Воркеры: `MutableObservation` не сериализуется; для `FilterBatch`/воркера
передавайте x_t внутри тика (`PairsHedge::tick()` собирает сырой тик вместе с
его `rows`) и ставьте регрессор в обработчике консьюмера.

## Калибровка

`q_α, q_β, σ_ε` — по MLE невязок (`App\Calibration\NelderMead` +
`InnovationLikelihood`), пример `examples/calibrate-pair.php`.
