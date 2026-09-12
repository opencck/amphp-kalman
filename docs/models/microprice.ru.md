# Микроцена стакана

*English version: [microprice.md](microprice.md).*

Класс: `Domain\Model\Finance\Microprice`. Справедливая цена внутри спреда
по дисбалансу объёмов (Stoikov 2018).

```
x = [p, v]ᵀ  (CV)
z = [mid_t, microprice_t]ᵀ,   microprice = (bid·V_ask + ask·V_bid)/(V_ask + V_bid)
H = [1 0; 1 0]
R = diag(r_mid, r_micro,t),   r_mid = half_spread² + tick²/12,   r_micro,t = depthScale / (V_bid + V_ask)
```

Два канала, одна величина. Второй точнее в среднем, но шумнее при тонком
стакане — `r_micro,t` пересчитывается на каждом обновлении стакана
(`MutableObservation`).

## Использование

```php
$mp = new Microprice(sigmaA: 0.01, tick: 0.01, depthScale: 5e-3);
$filter = $mp->filter(firstMid: 100.0);
$measurement = $mp->updateBook($ts, $bid, $ask, $bidVolume, $askVolume);  // ставит R и собирает z
$filter->step($measurement);
```

`depthScale` калибруется так, чтобы NIS канала microprice был ≈ 1
(`ConsistencyMonitor::channel(1)->rollingNis()`).
