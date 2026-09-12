# ADR-002 — Раскладка каталогов: DDD-слои OpenCCK вместо плоской структуры исходного плана

Дата: 2026-09-11. Статус: принято.

## Контекст

Исходный проектный документ описывал плоскую структуру `src/{Contract,Linalg,Covariance,Core,...,Async}`.
Владелец проекта попросил структуру каталогов «как по opencck» — DDD-слои
`Domain / App / Infrastructure` с зависимостями строго вниз
(`Infrastructure → App → Domain`). По правилу приоритета §0.1 конфликт архитектуры
фиксируется в ADR и разрешается в пользу варианта, согласованного с §4
(слои и запрет `use Amp\` в ядре). Оба требования совместимы: §4.1 — это и есть
слоистая архитектура, меняется только группировка каталогов.

## Решение

| Исходный план | Namespace в проекте | Слой |
|---|---|---|
| `src/Contract` | `Domain\Contract` | Domain |
| `src/Exception` | `Domain\Exception` | Domain |
| `src/Linalg` | `Domain\Linalg` (`Kernels\`, `Ffi\`) | Domain |
| `src/Covariance` | `Domain\Covariance` | Domain |
| `src/Core` (фильтры) | `Domain\Filter` | Domain |
| `src/Core` (value objects: Measurement, StateSnapshot, UpdateResult, ChannelOutcome, FilterConfig, GatingPolicy, FilterForm) | `Domain\Entity` | Domain |
| `src/Discretization` | `Domain\Discretization` | Domain |
| `src/Model/{Generic,Finance}` | `Domain\Model\{Generic,Finance,Observation}` | Domain |
| `src/Diagnostics` | `Domain\Diagnostics` | Domain |
| `src/Adaptive`, `src/MultiModel`, `src/Smoothing` | `Domain\Adaptive`, `Domain\MultiModel`, `Domain\Smoothing` | Domain |
| `FilterFactory::fromConfig`, выбор представления по `FilterForm` | `Domain\Factory` | Domain |
| `src/Calibration` | `App\Calibration` | App |
| `src/Backtest` | `App\Backtest` | App |
| события (`ModelBreakSuspected`) | `App\Event` | App |
| `src/Async/{FilterSession,ReorderBuffer,BarClock,Clock}` | `Infrastructure\Async` | Infrastructure |
| `src/Async/Ingest` | `Infrastructure\Ingest` | Infrastructure |
| `src/Async/Commands` | `Infrastructure\Command` | Infrastructure |
| `src/Async/Output` | `Infrastructure\Output` | Infrastructure |
| `src/Async/Parallel` (`Amp\Parallel\Worker\Task`) | `Infrastructure\Task` | Infrastructure |

Правила:

- `use Amp\` и `use Revolt\` разрешены **только** в `src/Infrastructure`. Проверяется
  `ArchitectureTest::testDomainAndAppHaveNoAmpImports`.
- `Domain` не импортирует ничего из `App` и `Infrastructure`; `App` не импортирует
  `Infrastructure`. Проверяется `ArchitectureTest::testLayerDependenciesPointDownwards`.
- Value-объекты в `Domain\Entity` — `final readonly class` (правило immutability OpenCCK),
  создаются только на границе API (§4.3).
- Singleton-паттерн OpenCCK (`getInstance()`) в библиотеке **не применяется**: фильтр —
  объект с владельцем (§4.5), а не глобальный сервис. Это осознанное отклонение
  от opencck-скилла, продиктованное §0.2 п.11.

Тесты остаются в `tests/` с подкаталогами по пирамиде §7.1 (Unit, Reference,
Invariant, Property, Consistency, Golden, Async, Architecture, Simulation, Support).

## Уточнение (2026-09-12): регистр корня namespace

Корень namespace был `Opencck\Kalman`, а конвенция, на которую ссылается этот же
ADR, называется **OpenCCK** — и именно так она записана в прозе BRIEF §3, обоих
README и в заголовке этого документа. Расхождение между кодом и его собственной
документацией устранено в пользу документации: корень стал `OpenCCK\Kalman`
(1734 вхождения в 323 файлах).

Что *не* менялось:

- **Вендорное имя пакета остаётся строчным** — `opencck/amphp-kalman`. Composer
  требует нижнего регистра для `name`, и к PHP-namespace это имя отношения не
  имеет. Строчными остались и адрес репозитория, и ссылки на скилл
  `opencck/skill-amphp`, и строка копирайта в LICENSE.
- **Каталоги не переезжали.** PSR-4 отображает префикс прямо на `src/`,
  `tests/`, `bench/`, `examples/`, поэтому смена регистра корня — это правка
  четырёх ключей в `composer.json` и объявлений `namespace` / `use`.

Менять пришлось и те места, где имя живёт строкой, а не идентификатором: префикс
поиска бенчмарков в `bench/run.php`, `MetricContractTest::NAMESPACE_PREFIX`, два
`preg_match` проверки слоёв в `ArchitectureTest` и namespace, который печатает
генератор ядер `tools/generate-kernels.php`. После правки обязателен
`composer dump-autoload` и сброс кешей PHPStan, Psalm и PHPUnit — все три
ключуются по именам классов.

Это не архитектурное решение, а приведение кода к уже принятой здесь конвенции,
поэтому отдельного ADR под него нет.

## Последствия

- Путь к классу читается как «слой → подсистема → класс», что соответствует
  соседним проектам на opencck/server.
- Все ссылки на пути из исходного плана читать через таблицу выше; актуальная карта репозитория — в [BRIEF](../../BRIEF.md) §7.
- Корень namespace — `OpenCCK\Kalman`; вендорное имя пакета — `opencck/amphp-kalman`.
  Разный регистр у этих двух имён намеренный, а не опечатка.
