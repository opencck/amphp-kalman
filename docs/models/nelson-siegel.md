# Dynamic yield curve (Nelson–Siegel, Diebold–Li)

*Русская версия: [nelson-siegel.ru.md](nelson-siegel.ru.md).*

Class: `Domain\Model\Finance\NelsonSiegel`. Three curve factors — level,
slope, curvature — from a dozen noisy bond yields.

```
x = [L, S, C]ᵀ                 n = 3, each an OU process (Generic\BlockDiagonal of three Generic\OrnsteinUhlenbeck)
z = [y(τ₁)..y(τ_m)]ᵀ           m = 8..15 maturities
H: row i = [1,  (1 − e^{−λτᵢ})/(λτᵢ),  (1 − e^{−λτᵢ})/(λτᵢ) − e^{−λτᵢ}]
R = diag(rᵢ) — from the bid-ask of every bond
```

`λ` is a hyper-parameter (0.0609 with maturities in months, Diebold–Li),
calibrated by MLE.

## Usage

```php
$ns = new NelsonSiegel(
    lambda: 0.0609,
    maturities: [3, 6, 12, 24, 36, 60, 84, 120],
    theta: [0.02, 0.05, 0.1], mu: [5.0, -1.5, 0.5], sigma: [0.05, 0.08, 0.15],
    variances: array_fill(0, 8, 4e-4),
);
$filter = $ns->filter();
$filter->step(Measurement::at($ts, $yieldsByChannel));
$y5y = $ns->yield($filter, 60.0);       // the fitted yield for an arbitrary maturity
```

## Form

`m ≫ n` → the information form pays off (`InformationFilter`): the correction
is the addition of `HᵀR⁻¹H`, the prediction works on n = 3 and is cheap.
