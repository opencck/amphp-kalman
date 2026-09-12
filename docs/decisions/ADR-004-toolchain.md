# ADR-004 — Инструменты: PHP ≥ 8.2, PHPUnit 9.6, PHPStan level 9

Дата: 2026-09-11. Статус: принято; решение «PHPStan вместо Psalm» заменено
[ADR-009](ADR-009-psalm-alongside-phpstan.md) — работают оба анализатора.

## Контекст

Исходный проектный документ требовал PHP ≥ 8.3 и `phpunit/phpunit ^11`, `vimeo/psalm ^5`.
Владелец проекта попросил поддержку PHP 8.2+. Скилл `amphp` предписывает
`amphp/phpunit-util ^3` для `AsyncTestCase`; его единственный стабильный релиз
`v3.0.0` требует `phpunit/phpunit ^9`.

## Решение

- `"php": "^8.2"`. Не используются фичи 8.3+: типизированные константы классов,
  `#[\Override]`, `json_validate`, `Randomizer::getFloat/nextFloat`.
  `readonly class` (8.2) — используется.
- `phpunit/phpunit ^9.6` + `amphp/phpunit-util ^3.0`. Аннотации `@dataProvider`,
  `@group`, без атрибутов PHPUnit 10+.
- `phpstan/phpstan ^2` на level 9 вместо psalm (явно допущено §0.3 DoD).
  В `src/Domain/Linalg` и `src/Domain/Covariance` подавлен `offsetAccess.notFound`:
  горячие циклы адресуют плоские массивы вычисляемым индексом, доказать существование
  элемента статически невозможно, а проверки `isset` в цикле противоречат §6.2 п.4.
- Бенчмарки — собственные скрипты с JSON-выводом и сравнением с baseline
  (`bench/run.php`), без `phpbench`: требование §6.6 «отдельный скрипт, вывод в JSON,
  регрессия > 5 % — падение сборки» реализуется проще напрямую.

## Последствия

- CI матрица: PHP 8.2, 8.3, 8.4.
- При выходе `amphp/phpunit-util` с поддержкой PHPUnit 10/11 — обновить оба пакета одной правкой.
