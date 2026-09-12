<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tools;

use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\MetricRegistry;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Generates the measurement tables in `examples/README.md` and
 * `examples/README.ru.md` from `MetricRegistry::describeAll()`.
 *
 *   php tools/generate-metric-index.php            rewrites both files
 *   php tools/generate-metric-index.php --check    exits 1 if they are stale
 *
 * The catalogue is the one piece of documentation that must never drift from
 * the code, because it is what a reader uses to find out what exists. Deriving
 * it removes the possibility: adding a metric without a row, or leaving a row
 * pointing at a deleted example, fails `--check` and therefore fails CI
 * (`tests/Architecture/MetricCatalogueTest`).
 */
final class MetricIndexGenerator
{
	private const BEGIN = '<!-- BEGIN GENERATED: metrics -->';

	private const END = '<!-- END GENERATED: metrics -->';

	private const ROOT = __DIR__ . '/..';

	/** @return array{0: string, 1: list<string>} the rendered block and any problems found */
	public static function render(bool $russian): array
	{
		$problems = [];
		$byCategory = [];
		foreach (MetricRegistry::describeAll() as $card) {
			$byCategory[$card->category->value][] = $card;
			$example = self::ROOT . '/' . $card->example;
			if (!\is_file($example)) {
				$problems[] = \sprintf('%s (%s) points at a missing example: %s', $card->id, $card->type, $card->example);
			}
		}

		$lines = [self::BEGIN, ''];
		$header = $russian
			? '| Измерение | Обозн. | Вход | Offloadable-методы | Для алготрейдинга | Описание | Пример |'
			: '| Measurement | Symbol | Input | Offloadable kernels | For algotrading | What it is | Example |';
		foreach ($byCategory as $categoryValue => $cards) {
			$category = MetricCategory::from($categoryValue);
			$lines[] = '### ' . self::capitalise($russian ? $category->labelRu() : $category->labelEn());
			$lines[] = '';
			$lines[] = $header;
			$lines[] = '|---|---|---|---|---|---|---|';
			foreach ($cards as $card) {
				$lines[] = self::row($card, $russian);
			}
			$lines[] = '';
		}
		$lines[] = self::END;

		return [\implode("\n", $lines), $problems];
	}

	private static function row(MetricDescriptor $card, bool $russian): string
	{
		$inputs = [];
		foreach ($card->inputs as $input) {
			$inputs[] = $russian ? $input->labelRu() : $input->labelEn();
		}
		$kernels = [];
		foreach ($card->kernels as $kernel) {
			$kernels[] = '`' . $kernel . '`';
		}
		$name = $russian ? $card->nameRu : $card->nameEn;
		$link = '[' . \basename($card->example) . '](' . \basename($card->example) . ')';

		return \sprintf(
			'| %s | `%s` %s | %s | %s | %s | %s | %s |',
			self::escape($name),
			self::escape($card->symbol),
			$card->id,
			self::escape(\implode(', ', $inputs)),
			\implode(', ', $kernels),
			self::escape($russian ? $card->algoRu : $card->algoEn),
			self::escape($russian ? $card->plainRu : $card->plainEn),
			$link,
		);
	}

	/** A pipe inside a cell would end it; the vertical bar is the only hazard. */
	private static function escape(string $text): string
	{
		return \str_replace('|', '\\|', $text);
	}

	/** Upper-cases the first character. `ucfirst()` is byte-based and would mangle Cyrillic. */
	private static function capitalise(string $text): string
	{
		return \mb_strtoupper(\mb_substr($text, 0, 1)) . \mb_substr($text, 1);
	}

	/** @return list<string> problems */
	public static function apply(string $file, bool $russian, bool $checkOnly): array
	{
		$path = self::ROOT . '/' . $file;
		$current = \file_get_contents($path);
		if ($current === false) {
			return [$file . ': cannot be read'];
		}
		[$block, $problems] = self::render($russian);

		$begin = \strpos($current, self::BEGIN);
		$end = \strpos($current, self::END);
		if ($begin === false || $end === false) {
			return [$file . ': missing the generated-block markers'];
		}
		$updated = \substr($current, 0, $begin) . $block . \substr($current, $end + \strlen(self::END));

		if ($updated === $current) {
			echo $file, ": up to date\n";
			return $problems;
		}
		if ($checkOnly) {
			$problems[] = $file . ': the committed table no longer matches the code — run tools/generate-metric-index.php';
			return $problems;
		}
		\file_put_contents($path, $updated);
		echo $file, ": rewritten\n";
		return $problems;
	}
}

$checkOnly = \in_array('--check', $argv, true);
$problems = [
	...MetricIndexGenerator::apply('examples/README.md', false, $checkOnly),
	...MetricIndexGenerator::apply('examples/README.ru.md', true, $checkOnly),
];

$count = \count(MetricRegistry::describeAll());
echo $count, " measurements in the catalogue\n";

if ($problems !== []) {
	echo "\n";
	foreach (\array_unique($problems) as $problem) {
		echo '  ! ', $problem, "\n";
	}
	exit(1);
}
exit(0);
