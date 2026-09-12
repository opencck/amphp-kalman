# opencck/amphp-kalman

[![CI](https://github.com/opencck/amphp-kalman/actions/workflows/ci.yml/badge.svg)](https://github.com/opencck/amphp-kalman/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/opencck/amphp-kalman.svg)](https://packagist.org/packages/opencck/amphp-kalman)
[![PHP](https://img.shields.io/packagist/dependency-v/opencck/amphp-kalman/php.svg)](https://www.php.net/)
[![License](https://img.shields.io/packagist/l/opencck/amphp-kalman.svg)](LICENSE)

*English version: [README.md](README.md).*

Высокопроизводительный n-мерный дискретный фильтр Калмана для PHP 8.2+ с
каталогом моделей рыночных данных и асинхронным потоковым слоем на
AMPHP v3 / Revolt.

- **Численно устойчивые формы** — последовательная скалярная коррекция
  (по умолчанию), форма Джозефа, UD (Бирман–Торнтон), square-root (Поттер +
  Хаусхолдер); ни одного обращения матрицы в продакшен-коде; точная симметрия
  по построению.
- **Время из данных** — `dt` вычисляется из биржевых timestamp'ов, `F(dt)` и
  `Q(dt)` — точная дискретизация непрерывных моделей (замкнутые формы или
  Van Loan).
- **Встроенная диагностика** — гейтинг χ² / σ / Хьюбера, консистентность
  NIS/NEES, белизна невязок, наблюдаемость, детекция «поломки модели».
- **Всё вокруг шага асинхронно** — WebSocket-ингест с back-pressure, буфер
  переупорядочивания, сессия-владелец фильтра, часовой тик, снапшоты в
  WebSocket/HTTP/файлы/Redis, воркер-процессы, параллельная калибровка,
  бэктесты из файлов.
- **Чистые выносимые ядра** — `FilterBatch::run`, `InnovationLikelihood`,
  RTS-сглаживание, шаги EM и другие — `public static`, детерминированные,
  принимают только сериализуемые массивы, поэтому без изменений работают в
  воркере, за консьюмером очереди или в HTTP-сервисе вычислений
  (`@offloadable`, ADR-005).
- **Ноль аллокаций в горячем цикле**, развёрнутые ядра для n = 2/4,
  опциональный OpenBLAS-бэкенд через FFI, путь `stepRaw()` без объектов в
  каждом фильтре.

## Установка

```bash
composer require opencck/amphp-kalman
```

Требования: PHP ≥ 8.2 (рекомендуется 8.4+), `ext-json`; `ext-opcache` с JIT
для продакшен-скорости; опционально `ext-ffi` + OpenBLAS для n > 32 (на
Windows `php tools/fetch-openblas.php` скачивает DLL, затем задайте
`KALMAN_BLAS_LIB`); пакеты `amphp/*` подтягивает Composer. Компилируемых
расширений не требуется.

### Включите JIT (2–4× на шаге фильтра)

```ini
opcache.enable_cli=1        ; для CLI-воркеров и бэктестов
opcache.jit=1255
opcache.jit_buffer_size=128M
```

Ядро фильтра написано JIT-безопасно: PHP 8.2 и 8.3 неверно компилируют две
распространённые формы операций с плавающей точкой (в обоих режимах JIT);
библиотека их избегает и проверяет свои ядра при старте
(`Diagnostics\JitSanity`; см. [ADR-007](docs/decisions/ADR-007-jit-mode-and-determinism.md)).
Для процессов, обслуживающих много моделей или путей кода, увеличьте бюджет
трассирующего JIT — когда он исчерпан, остальной код молча выполняется
интерпретатором (в 3–8 раз медленнее, без предупреждений):

```ini
opcache.jit_max_root_traces=100000
opcache.jit_max_side_traces=100000
opcache.jit_max_exit_counters=32768
```

На Windows CLI-процессы с одним и тем же бинарником и ini подключаются к
*одному* сегменту разделяемой памяти OPcache — включая скомпилированные
скрипты, JIT-буфер и состояние трассирующего JIT. Трассы, специализированные
одним процессом, заставляют горячие циклы другого выходить в интерпретатор
(мы измерили 3–8× на малых ядрах). Выдавайте каждому семейству процессов
свой сегмент через `opcache.cache_id`; `WorkerPools::likeParent()` делает это
для пулов воркеров, а `bench/run.php` — для каждого дочернего процесса
бенчмарка.

## Быстрый старт

### 1. Локальный линейный тренд на одном потоке цен

```php
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;

// σ_a: интенсивность шума скорости (единицы цены / с^1.5), полуспред, шаг цены → R
$llt = new LocalLinearTrend(sigmaA: 0.5, halfSpread: 0.05, tick: 0.01);
$filter = $llt->filter(firstPrice: 100.0, config: FilterConfig::default()->withGating(GatingPolicy::chiSquare(0.001)));

foreach ($ticks as [$exchangeTimestampNs, $price]) {
    $result = $filter->step(Measurement::at($exchangeTimestampNs, [0 => $price]));   // predict(dt) + correct, атомарно
    // $result->outcomes[0]->innovation, ->nis(); $result->logLikelihood
}

$fair = $filter->meanAt(0);                 // отфильтрованная цена
$trend = LocalLinearTrend::trendScore($filter);   // v̂ / √P_vv
```

`stepRaw(int $tsNs, array $values)` делает то же без создания объектов;
результаты читаются через `lastInnovation($channel)` и соседние методы.

### 2. Корзина ETF: справедливая цена фонда и каждой компоненты

```php
use OpenCCK\Kalman\Domain\Model\Finance\EtfBasket;

$etf = new EtfBasket(
    weights: [0.5, 0.3, 0.2],
    sigma: [4e-6, 1e-6, 5e-7,  1e-6, 3e-6, 2e-7,  5e-7, 2e-7, 2e-6],   // k×k ковариация доходностей за секунду
    sigmaA: 0.002,                        // шум скорости компонент
    premiumTheta: 0.05, premiumSigma: 0.01,   // OU-премия фонда к NAV
    quoteVariances: [1e-4, 2e-4, 3e-4],   // r_i котировок компонент
    etfVariance: 5e-5,                    // r котировки фонда
);
$filter = $etf->filter(firstPrices: [100.0, 50.0, 20.0]);

// каналы 0..k-1 — компоненты, канал k — фонд; любое подмножество в одном измерении допустимо
$filter->step(Measurement::at($ts, [0 => 100.02, 3 => 71.9]));
$nav = $etf->nav($filter);   // Σ wᵢ p̂ᵢ
```

Состояние — `[p₁, v₁, …, p_k, v_k, premium]` (n = 2k + 1); прогноз разреженный,
O(k·n).

### 3. Парный трейдинг: динамический hedge-ratio

```php
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Model\Finance\PairsHedge;

$pair = new PairsHedge(qAlpha: 1e-6, qBeta: 1e-5, sigmaEps: 0.02);   // y = α + β·x + ε
$filter = $pair->filter(alpha0: 0.0, beta0: 1.2, config: FilterConfig::default()->withForm(FilterForm::UD));

foreach ($ticks as [$ts, $y, $x]) {
    $raw = $pair->tick($ts, $y, $x);        // ['ts', 'values', 'rows'] — строка наблюдения зависит от x
    $filter->step(Measurement::at($raw['ts'], $raw['values']));
}
$z = PairsHedge::zScore($filter);          // нормированный спред — торговый сигнал
```

Каждая модель каталога сериализуема (`toArray()` / `ModelRegistry`), поэтому
тот же фильтр можно пересобрать из массива-конфига в другом процессе:
`FilterFactory::fromConfig($modelConfig, $filterConfig, $snapshot)`.

Другие модели: мультивенью fair value, динамическая бета, стохастическая
волатильность (QML и UKF с левереджем), микроцена стакана, кривая
Нельсона–Сигела, bid-ask bounce, корзина ETF в лог-ценах (EKF), переключение
режимов (IMM). См. [docs/models](docs/models/README.ru.md).

## Потоковая обработка на AMPHP

```php
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;
use OpenCCK\Kalman\Infrastructure\Async\MeasurementBatcher;
use OpenCCK\Kalman\Infrastructure\Async\ReorderBuffer;
use OpenCCK\Kalman\Infrastructure\Ingest\IngestOrchestrator;
use OpenCCK\Kalman\Infrastructure\Ingest\WebsocketFeed;
use function Amp\async;

$feeds = [new WebsocketFeed($urlA, $decoderA), new WebsocketFeed($urlB, $decoderB)];
$handle = (new IngestOrchestrator($feeds))->start();          // Queue<Measurement> с back-pressure + stop()

$inbox = new Queue(1024);
$snapshots = new Queue(16);
$session = new FilterSession($filter, $inbox, $snapshots, snapshotEvery: 100);
$final = $session->start();                                   // fiber-владелец; Future<StateSnapshot>

async(static function () use ($handle, $inbox): void {
    $ordered = (new ReorderBuffer(windowNs: 2_000_000))->apply($handle->queue->iterate());   // порядок по биржевому времени в окне 2 мс
    (new MeasurementBatcher(256))->pump($ordered, $inbox);    // массивы тиков на элемент очереди: накладные расходы loop → 0
    $inbox->complete();
});
foreach ($snapshots->iterate() as $snapshot) {                // раздача / персистенция
    // ...
}
```

Сам шаг синхронен и атомарен; асинхронность живёт вокруг него. Подробности,
воркер-процессы, параллельная калибровка и порядок остановки:
[docs/async.ru.md](docs/async.ru.md).

Запускаемые примеры: `examples/etf-live.php --mock`, `examples/calibrate-pair.php --synthetic`,
`examples/backtest-trend.php --synthetic --em`. Полный указатель всех величин,
которые библиотека умеет считать, с примером на каждую строку —
[examples/README.ru.md](examples/README.ru.md) ([English](examples/README.md)).

## Калибровка, сглаживание, бэктесты

```php
use OpenCCK\Kalman\App\Calibration\Calibrator;
use OpenCCK\Kalman\App\Calibration\Parametrization;

$param = new Parametrization(['motion.sigmaA' => 'log', 'observation.variances.0' => 'log']);
$best = (new Calibrator($param))->calibrate($modelConfig, filterConfig: null, ticks: $history);
// $best['config'] — конфиг модели в максимуме правдоподобия невязок (Нелдер–Мид)
```

`ParallelCalibrator` считает каждый батч симплекса на пуле воркеров
`amphp/parallel`; передайте ему `new MultiStartNelderMead(starts: 4)`, чтобы
вести четыре симплекса синхронно (16 точек-кандидатов за раунд — достаточно
для 8 воркеров, и устойчивость к локальным оптимумам).
`ExpectationMaximization::fit()` оценивает полные `Q`, `R` через
RTS-сглаживание; `App\Backtest\Engine` прогоняет файл истории через фильтр и
отчитывается о NIS, доле отказов и сглаженных траекториях.

## Метрики, индикаторы и метрики стакана

Фильтрация — половина задачи: фильтруют обычно индикатор, а индикатор,
посчитанный по сырым тикам, сам является шумным рядом. `Domain\Metric` даёт 32
измерения — технические индикаторы, метрики стакана, оцениватели волатильности
и микроструктурные величины — каждое в виде потокового объекта со стоимостью
O(1) на наблюдение и в виде чистого ядра `@deterministic @offloadable`, которое
воркер может прогнать по всей истории.

```php
use OpenCCK\Kalman\Domain\Metric\Momentum\Rsi;
use OpenCCK\Kalman\Domain\Metric\Liquidity\LiquidityDensity;
use OpenCCK\Kalman\Domain\Metric\Filtered\Derivative;

$rsi = new Rsi(period: 14);                       // сглаживание Уайлдера, каноническое определение
foreach ($closes as $close) {
    $rsi->updatePrice($timestampNs, $close);
}
$rsi->value();                                     // 0-100, NAN до окончания прогрева

$series = Rsi::wilder($closes, 14);                // то же определение, вся история за раз

$density = LiquidityDensity::within(               // объём в полосе 10 б.п. вокруг середины спреда
    $book->bidPrices, $book->bidSizes, $book->askPrices, $book->askSizes, bandBps: 10.0,
);

$rate = Derivative::ofSeries($timestamps, $series);   // d(RSI)/dt вместе с неопределённостью
$rate['rate'][-1];                                    // скорость, в секунду
$rate['tStat'][-1];                                   // больше 2 — значит реальна, а не шум
```

Последний вызов — смысл всего слоя. Четырнадцать из пятидесяти индикаторов
референсного каталога, под который писалась библиотека, это конечные разности
шумного ряда, то есть худший из возможных оценивателей производной.
`Filtered\Derivative` заменяет их все одним фильтром постоянной скорости,
который возвращает скорость **и** её стандартную ошибку, так что тренд можно
проверить на значимость, а не оценивать на глаз.

Полный каталог — каждое измерение с обозначением, категорией,
offloadable-ядрами, описанием с точки зрения алготрейдинга и запускаемым
примером — в **[examples/README.ru.md](examples/README.ru.md)**
([English](examples/README.md)); он генерируется из кода и поэтому не может
разойтись с ним. Там, где метрика расходится с референсной формулой,
[ROADMAP.md](ROADMAP.md) говорит, чем именно и почему: референсный RSI берёт лаг
30 минут и простое среднее вместо сглаживания Уайлдера, MACD строится на простых
средних 8 и 17 вместо EMA 12 и 26, у стохастика нет сигнальной линии, а
плотность ликвидности читает лучший бид из неотсортированного массива.
## Производительность

Замерено `composer bench` на одном ядре (PHP 8.3.33, `opcache.jit=1255`,
Windows 11 x64, `zend.assertions=-1`); каждый бенчмарк работает в отдельном
процессе. Полный набор — в `bench/results/baseline.json`; CI падает при
аллокациях ≠ 0 и при грубых (2×) регрессиях — облачные раннеры слишком сильно
отличаются от десктопа для более жёсткого порога.

| Метрика (цели §1.3 в скобках) | n=4, m=2 | n=8, m=4 | n=16, m=8 | n=32, m=16 |
|---|---|---|---|---|
| Шаг фильтра, последовательная форма (≤ 3 / 12 / 60 / 300 мкс) | **1.4 мкс** | **9.2 мкс** | **53 мкс** | 363 мкс ✗ |
| Шаг фильтра, UD-форма (≤ 5 / 20 / 100 / 500 мкс) | **3.6 мкс** | **16.3 мкс** | **99 мкс** | 718 мкс ✗ |
| Шаг фильтра, `Backend::Blas` (OpenBLAS) | — | 13.7 мкс | **28 мкс** | **76 мкс** |
| Аллокаций на шаг, все типы фильтров (0) | **0** | **0** | **0** | **0** |
| Пропускная способность без I/O, тиков/с (≥ 300k / 80k / 15k / 3k) | **≈ 727k** | **≈ 108k** | **≈ 19k** | 2.8k ✗ |
| Накладные расходы event loop на тик (≤ 2 мкс) | **1.1 мкс** одиночные тики, **0.0 мкс** с массивами `MeasurementBatcher` | | | |
| WebSocket-ингест → фильтр, тиков/с (≥ 50k / 30k / 10k / 2.5k) | 15–20k ✗ (loopback на Windows) | | | |
| Масштабирование на 8 воркеров, 32 независимых правдоподобия (≥ 6.5×) | 4.0× ✗ (1.8× / 2.9× на 2 / 4 воркерах) | | | |

Другие цифры: шаг LLT (n=2, развёрнутое ядро) 0.62 мкс; шаг alpha-beta
0.055 мкс; развёрнутое ядро против общего: n=2 0.80 против 0.98 мкс, n=4 1.62
против 2.41 мкс; IPC 35 мкс/тик без батчей → 1.4–2.3 мкс/тик при батче ≥ 16;
декодирование SBE 0.78 мкс против компактного JSON 0.92 мкс (1 канал),
реальное trade-событие Binance 2.6 мкс; отношение стоимости UKF/KF 3.2–4.8×
(n = 2…8); параллельная калибровка Нелдера–Мида на 8 воркерах 2.2× с одним
симплексом и **4.6×** по числу вычислений в секунду с
`MultiStartNelderMead(starts: 4)`; одно только F·P·Fᵀ при n=8: 4.0 мкс на
плоских массивах PHP, 4.3 на вложенных, 18 на `SplFixedArray`, 18 на
FFI-буферах, 2.4 на `dgemm` из OpenBLAS — точка пересечения для полного шага
n ≈ 12 (ADR-006).

Отклонения от целей §1.3 и их причины:

- **n = 32 на чистом PHP** — 363 мкс против 300 (UD 718 против 500): O(n³)
  прогноз на PHP. `Backend::Blas` делает тот же шаг за 76 мкс (ADR-006), либо
  используйте разреженную модель движения при таком размере.
- **Пропускная способность WebSocket** — 15–20k тиков/с против 30k, в
  зависимости от загрузки машины: ограничена loopback-стеком WebSocket на
  Windows (50–67 мкс на кадр от края до края, из них шаг фильтра — 1 мкс); на
  Linux ожидаются более высокие цифры — замерьте
  `composer bench -- --filter=throughput`.
- **Масштабирование на 8 воркеров** — 4.0× против 6.5×: каждая задача — лишь
  ~65 мс работы против фиксированной цены IPC и сериализации на задачу, а
  16 обнаруженных «ядер» — это 8 физических с hyper-threading; тренд
  (1.8× → 2.9× → 4.0×) — кривая физических ядер. Более длинные истории на
  задачу приближают ускорение к числу ядер.
- **Отношение UKF/KF** — исходный план просил ≤ 3×; последовательный UKF даёт
  3.2–4.8× (2n+1 сигма-точек пересчитываются на каждый канал).
- **Батчинг на стороне потребителя** (`batchSize` у `FilterSession`) сам по
  себе экономит лишь ~0.05–0.25 мкс на тик; `MeasurementBatcher` на стороне
  продюсера (массивы измерений на элемент очереди) убирает накладные расходы
  цикла полностью — используйте его.
Два эффекта движка, обнаруженные при измерениях, задокументированы, потому
что касаются пользователей не меньше, чем бенчмарков: неверная компиляция
двух форм float-ядер JIT-ом PHP 8.2/8.3 (ADR-007) и общий сегмент OPcache у
CLI-процессов Windows (ADR-008).

## Проектная документация

- [docs/theory.ru.md](docs/theory.ru.md) — математика по разделам с указанием
  класса, реализующего каждую идею ([English](docs/theory.md)).
- [docs/async.ru.md](docs/async.ru.md) — топология, back-pressure,
  сессия-владелец, воркеры, остановка, типовые ошибки AMPHP
  ([English](docs/async.md)).
- [docs/models](docs/models/README.ru.md) — каталог рыночных моделей
  (русская и английская версии).
- [examples/README.ru.md](examples/README.ru.md) — каталог измерений: каждая
  метрика, индикатор и выход фильтра с обозначением, категорией, списком
  offloadable-ядер и запускаемым примером ([English](examples/README.md)).
- [ROADMAP.md](ROADMAP.md) — план слоя метрик: теория по каждому индикатору,
  канонические формулы и их расхождения с исходными выражениями PromQL.
- [docs/decisions](docs/decisions) — записи решений (ADR-001…005 на русском,
  006–008 на английском):
  ADR-001 раскладка памяти, ADR-002
  DDD-структура, ADR-003 двухфазная скалярная коррекция, ADR-004 инструменты,
  ADR-005 выносимые ядра, ADR-006 BLAS-бэкенд, ADR-007 ошибки JIT и
  детерминизм, ADR-008 изоляция OPcache на Windows, ADR-009 Psalm рядом с
  PHPStan, ADR-010 сущность стакана, ADR-011 контракт метрики, ADR-012 окна
  метрик (на английском).
- [BRIEF.md](BRIEF.md) — контекст проекта: постановка, непреложные правила,
  архитектура, статус, карта репозитория, глоссарий (на английском).
- [CLAUDE.md](CLAUDE.md) — короткая точка входа для агентов (на английском).

## Разработка

```bash
composer test            # PHPUnit 9.6, zend.assertions=1 (unit, reference, invariant, property, consistency, golden, async, architecture)
composer test:fast       # без наборов @group slow
composer test:blas       # тесты BLAS-бэкенда (нужен ext-ffi и KALMAN_BLAS_LIB, см. tools/fetch-openblas.php)
composer analyse         # PHPStan уровень 9 + Psalm errorLevel 1
composer bench           # все бенчмарки, каждый в своём процессе → bench/results/latest.json
composer bench:baseline  # сохранить/объединить bench/results/baseline.json
```

Структура каталогов следует конвенции OpenCCK DDD: `src/Domain` (чистая
численная логика, без `Amp\`), `src/App` (сценарии калибровки и бэктеста),
`src/Infrastructure` (всё, что связано с AMPHP). Архитектурные тесты
контролируют направление зависимостей между слоями, `strict_types`,
`JSON_THROW_ON_ERROR`, плоские матрицы в горячем ядре, контракт
`@offloadable` и изоляцию FFI. Статический анализ выполняется дважды —
PHPStan уровня 9 и Psalm errorLevel 1, оба без baseline-файла (ADR-009).

## Лицензия

MIT — см. [LICENSE](LICENSE).
