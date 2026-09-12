# Динамическая кривая доходности (Nelson–Siegel, Diebold–Li)

*English version: [nelson-siegel.md](nelson-siegel.md).*

Класс: `Domain\Model\Finance\NelsonSiegel`. Три фактора кривой — уровень,
наклон, кривизна — по десятку зашумлённых доходностей облигаций.

```
x = [L, S, C]ᵀ                 n = 3, каждый — OU (Generic\BlockDiagonal из трёх Generic\OrnsteinUhlenbeck)
z = [y(τ₁)..y(τ_m)]ᵀ           m = 8..15 сроков
H: строка i = [1,  (1 − e^{−λτᵢ})/(λτᵢ),  (1 − e^{−λτᵢ})/(λτᵢ) − e^{−λτᵢ}]
R = diag(rᵢ) — из bid-ask по каждой бумаге
```

`λ` — гиперпараметр (0.0609 при сроках в месяцах, Diebold–Li), калибруется MLE.

## Использование

```php
$ns = new NelsonSiegel(
    lambda: 0.0609,
    maturities: [3, 6, 12, 24, 36, 60, 84, 120],
    theta: [0.02, 0.05, 0.1], mu: [5.0, -1.5, 0.5], sigma: [0.05, 0.08, 0.15],
    variances: array_fill(0, 8, 4e-4),
);
$filter = $ns->filter();
$filter->step(Measurement::at($ts, $yieldsByChannel));
$y5y = $ns->yield($filter, 60.0);       // подгонка для произвольного срока
```

## Форма

`m ≫ n` → информационная форма выгодна (`InformationFilter`):
коррекция — сложение `HᵀR⁻¹H`, прогноз — n = 3, дёшево.
