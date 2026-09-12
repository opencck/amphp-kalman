# Каталог биржевых моделей

*English version: [README.md](README.md).*

Каждая модель — класс в `OpenCCK\Kalman\Domain\Model\Finance\`, реализующий
`MotionModel` (+ `SparseMotionModel`, `StationaryModel`, `SerializableModel`)
или отдающий пару motion/observation через фабрику. Для каждой есть:
симулятор-тест консистентности (`tests/Consistency/FinanceModelsConsistencyTest.php`),
golden-файл (`tests/Golden/*.json`), страница в этом каталоге. Математика моделей —
в [docs/theory.ru.md](../theory.ru.md).

Также в библиотеке, но описаны в комментариях классов: `StochasticVolatilityLeverage`
(UKF, эффект левереджа), `LogPriceEtfBasket` (EKF в лог-ценах),
`InteractingMultipleModel` (переключение режимов спокойно/волатильно).

| Модель | Класс | n | m | Форма по умолчанию | Страница |
|---|---|---|---|---|---|
| Локальный линейный тренд | `LocalLinearTrend` | 2 | 1 | Sequential (ядро `Sequential2`) / alpha-beta | [local-linear-trend.ru.md](local-linear-trend.ru.md) |
| Корзина ETF | `EtfBasket` | 2k+1 | k+1 | Sequential + sparse predict | [etf-basket.ru.md](etf-basket.ru.md) |
| Парный трейдинг | `PairsHedge` | 2–3 | 1 | UD | [pairs-hedge.ru.md](pairs-hedge.ru.md) |
| Мультивенью fair value | `MultiVenue` | V+1 | V | Sequential + `ReorderBuffer` | [multi-venue.ru.md](multi-venue.ru.md) |
| Динамическая бета | `TimeVaryingBeta` | 2 | 1 | Sequential | [time-varying-beta.ru.md](time-varying-beta.ru.md) |
| Стохастическая волатильность | `StochasticVolatility` | 1 | 1 | Sequential (QML) | [stochastic-volatility.ru.md](stochastic-volatility.ru.md) |
| Микроцена стакана | `Microprice` | 2 | 2 | Sequential, R меняется каждый тик | [microprice.ru.md](microprice.ru.md) |
| Кривая Нельсона–Сигела | `NelsonSiegel` | 3 | 8–15 | Sequential (m ≫ n → `InformationFilter`) | [nelson-siegel.ru.md](nelson-siegel.ru.md) |
| Bid-ask bounce | `BidAskBounce` | 3 | 1 | UD / SquareRoot | [bid-ask-bounce.ru.md](bid-ask-bounce.ru.md) |

Общие обозначения: `dt` — интервал между измерениями в секундах, вычисляется
из биржевых timestamp'ов; все `σ` — интенсивности непрерывных шумов
(на √секунду); `r` — дисперсии шума измерения.

## Результаты симуляций (20 000 шагов, `FinanceModelsConsistencyTest`)

Фильтр консистентен, если средний NEES ∈ [0.9n, 1.1n] и средний NIS ∈ [0.9, 1.1]
на канал. Все девять моделей проходят эти границы на симуляции по собственным
уравнениям; точные значения печатаются при падении теста.

| Модель | Проверка | dt | Особенность симуляции |
|---|---|---|---|
| LLT | NEES ∈ [1.8, 2.2], NIS, белизна невязок, гейт χ² | 50–150 мс, нерегулярно | 50 000 шагов |
| ETF (k=3) | NEES ∈ [6.3, 7.7] | 0.5 с | случайное подмножество котировок на каждом шаге |
| ETF (k=2, UD) | NEES ∈ [4.5, 5.5] | 0.5 с | форма UD |
| MultiVenue (V=4) | NEES ∈ [4.5, 5.5] | 0.1 с | асинхронные каналы |
| Nelson–Siegel (m=8) | NEES ∈ [2.7, 3.3] | 1 день | все сроки каждый день |
| SV | NEES ∈ [0.9, 1.1] | 60 с | гауссов прокси-шум π²/2 |
| Bounce (UD) | NEES ∈ [2.7, 3.3] | 0.5 с | R = tick²/12 ≪ hPhᵀ |
| Pairs | NEES ∈ [1.8, 2.2] | 1 с | H_t меняется каждый шаг |
| Beta | NEES ∈ [1.8, 2.2] | 60 с | H_t = [1, r_m] |
| Microprice | NEES ∈ [1.8, 2.2] | 0.2 с | r_micro пересчитывается от глубины |
