# Time-varying beta (динамический CAPM)

*English version: [time-varying-beta.md](time-varying-beta.md).*

Класс: `Domain\Model\Finance\TimeVaryingBeta`.

```
x = [α_t, β_t]ᵀ,   F = I,   Q = diag(q_α, q_β)·dt
z = [r_asset,t],   H_t = [1, r_market,t],   R = σ²_idio
```

Структурно идентична парному трейдингу, но на доходностях.
`β̂_t ± √P_ββ` (`TimeVaryingBeta::betaBand($filter, k)`) — коридор беты для
хеджирования портфеля.

## Выбор q_β

Из соображения «бета меняется на Δ за горизонт T»:
`q_β = Δ²/T` (`TimeVaryingBeta::betaNoiseFromHorizon(0.1, 90 * 86400)`).

## Использование

```php
$model = new TimeVaryingBeta(qAlpha: 1e-9, qBeta: TimeVaryingBeta::betaNoiseFromHorizon(0.1, 90 * 86400), sigmaIdio: 0.01);
$filter = $model->filter(alpha0: 0.0, beta0: 1.0);
foreach ($bars as [$ts, $rMarket, $rAsset]) {
    $model->setMarketReturn($rMarket);
    $filter->step(Measurement::at($ts, [0 => $rAsset]));
}
[$lo, $hi] = TimeVaryingBeta::betaBand($filter, 2.0);
```
