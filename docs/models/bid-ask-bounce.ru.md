# Bid-ask bounce как цветной шум (расширение состояния)

*English version: [bid-ask-bounce.md](bid-ask-bounce.md).*

Класс: `Domain\Model\Finance\BidAskBounce`. Демонстрирует §15 теории: микроструктурный
шум сделок отрицательно автокоррелирован на лаге 1 — это не белый шум измерения,
а часть состояния.

```
x = [p, v, ν]ᵀ            ν — автокоррелированный шум измерения
F:  CV-блок;  ν ← ρ^(dt/τ)·ν        (AR(1) в тиковом времени, τ — средний интервал сделок; ρ ≈ −0.3..−0.5)
Q:  CV-блок σ_a²;  q_ν = σ_ν²·(1 − ρ^(2dt/τ))   (стационарная Var(ν) = σ_ν²)
z = [trade_price],  H = [1 0 1],  R = tick²/12   (только дискретность)
```

## Почему UD

`R → 0` относительно `hPhᵀ`: скалярный rank-1 downdate последовательной формы
теряет положительную определённость (стресс-тест `StabilityStressTest`), UD и
SquareRoot держат P ≥ 0 по построению. `filter()` ставит `FilterForm::UD`.

## Использование

```php
$model = new BidAskBounce(sigmaA: 0.02, sigmaNu: 0.004, rho: -0.4, tickInterval: 0.5, tick: 0.01);
$filter = $model->filter(firstPrice: 50.0);
$filter->step(Measurement::at($ts, [0 => $tradePrice]));
$fair = $filter->meanAt(0);     // цена без bounce
$bounce = $filter->meanAt(2);   // текущая компонента ν
```
