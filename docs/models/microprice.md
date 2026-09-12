# Order-book microprice

*Русская версия: [microprice.ru.md](microprice.ru.md).*

Class: `Domain\Model\Finance\Microprice`. The fair price inside the spread
from the volume imbalance (Stoikov 2018).

```
x = [p, v]ᵀ  (CV)
z = [mid_t, microprice_t]ᵀ,   microprice = (bid·V_ask + ask·V_bid)/(V_ask + V_bid)
H = [1 0; 1 0]
R = diag(r_mid, r_micro,t),   r_mid = half_spread² + tick²/12,   r_micro,t = depthScale / (V_bid + V_ask)
```

Two channels, one quantity. The second is more accurate on average but
noisier on a thin book — `r_micro,t` is recomputed on every book update
(`MutableObservation`).

## Usage

```php
$mp = new Microprice(sigmaA: 0.01, tick: 0.01, depthScale: 5e-3);
$filter = $mp->filter(firstMid: 100.0);
$measurement = $mp->updateBook($ts, $bid, $ask, $bidVolume, $askVolume);  // sets R and builds z
$filter->step($measurement);
```

`depthScale` is calibrated so that the NIS of the microprice channel is ≈ 1
(`ConsistencyMonitor::channel(1)->rollingNis()`).
