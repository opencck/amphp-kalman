<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Architecture;

use OpenCCK\Kalman\Domain\Metric\Contract\Metric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\MetricRegistry;
use PHPUnit\Framework\TestCase;

/**
 * ADR-011 + ROADMAP §5: the published catalogue of measurements is derived
 * from the code, so it cannot drift from it.
 *
 * These checks are the reason the tables in `examples/README.md` and
 * `examples/README.ru.md` can be trusted: adding a metric without a card,
 * pointing a card at a deleted example, or editing a generated table by hand
 * all fail here rather than quietly misleading a reader.
 */
final class MetricCatalogueTest extends TestCase
{
	private const ROOT = __DIR__ . '/../..';

	/** @return list<MetricDescriptor> */
	private static function cards(): array
	{
		return MetricRegistry::describeAll();
	}

	public function testEveryMetricClassIsRegistered(): void
	{
		$missing = [];
		foreach (self::metricClasses() as $class) {
			if (!\in_array($class::type(), MetricRegistry::types(), true)) {
				$missing[] = $class;
			}
		}
		self::assertSame([], $missing, 'Every Metric implementation must be listed in MetricRegistry::BUILTINS');
	}

	public function testTypesAndCatalogueNumbersAreUnique(): void
	{
		$types = [];
		$ids = [];
		foreach (self::cards() as $card) {
			$types[] = $card->type;
			$ids[] = $card->id;
		}
		self::assertSame(\array_unique($types), $types, 'Two metrics share a type() slug');
		self::assertSame(\array_unique($ids), $ids, 'Two metrics share a catalogue number');
	}

	public function testCardsAreComplete(): void
	{
		$problems = [];
		foreach (self::cards() as $card) {
			$label = $card->id . ' (' . $card->type . ')';
			foreach ([
				'symbol' => $card->symbol,
				'nameEn' => $card->nameEn,
				'nameRu' => $card->nameRu,
				'algoEn' => $card->algoEn,
				'algoRu' => $card->algoRu,
				'plainEn' => $card->plainEn,
				'plainRu' => $card->plainRu,
				'example' => $card->example,
			] as $field => $value) {
				if (\trim($value) === '') {
					$problems[] = $label . ': ' . $field . ' is empty';
				}
			}
			if ($card->inputs === []) {
				$problems[] = $label . ': no inputs declared';
			}
			if ($card->kernels === []) {
				$problems[] = $label . ': no offloadable kernels listed';
			}
			if (\preg_match('/^M-\d{2}$/', $card->id) !== 1) {
				$problems[] = $label . ': catalogue number must look like M-07';
			}
		}
		self::assertSame([], $problems);
	}

	public function testEveryListedKernelExistsAndIsOffloadable(): void
	{
		$problems = [];
		foreach (self::cards() as $card) {
			$class = MetricRegistry::classFor($card->type);
			foreach ($card->kernels as $kernel) {
				if (\preg_match('/^(\w+)::(\w+)\(\)$/', $kernel, $m) !== 1) {
					$problems[] = $card->id . ': "' . $kernel . '" is not of the form Class::method()';
					continue;
				}
				[, $shortClass, $method] = $m;
				$reflection = new \ReflectionClass($class);
				if ($reflection->getShortName() !== $shortClass) {
					$problems[] = $card->id . ': kernel names ' . $shortClass . ', card belongs to ' . $reflection->getShortName();
					continue;
				}
				if (!$reflection->hasMethod($method)) {
					$problems[] = $card->id . ': ' . $kernel . ' does not exist';
					continue;
				}
				$doc = $reflection->getMethod($method)->getDocComment();
				if ($doc === false || !\str_contains($doc, '@offloadable')) {
					$problems[] = $card->id . ': ' . $kernel . ' is listed as a kernel but is not @offloadable';
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testEveryCardPointsAtAnExistingExample(): void
	{
		$problems = [];
		foreach (self::cards() as $card) {
			if (!\str_starts_with($card->example, 'examples/')) {
				$problems[] = $card->id . ': example path must be relative to the repository root';
				continue;
			}
			if (!\is_file(self::ROOT . '/' . $card->example)) {
				$problems[] = $card->id . ': missing example ' . $card->example;
			}
		}
		self::assertSame([], $problems);
	}

	/**
	 * The committed tables must match what the generator produces. Run
	 * `php tools/generate-metric-index.php` after adding or changing a metric.
	 */
	public function testGeneratedTablesAreUpToDate(): void
	{
		$script = self::ROOT . '/tools/generate-metric-index.php';
		self::assertFileExists($script);

		$command = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($script) . ' --check 2>&1';
		$output = [];
		$status = 0;
		\exec($command, $output, $status);
		self::assertSame(
			0,
			$status,
			"examples/README.md and examples/README.ru.md are out of date.\n"
			. "Run: php tools/generate-metric-index.php\n"
			. \implode("\n", $output),
		);
	}

	/** @return list<class-string<Metric>> */
	private static function metricClasses(): array
	{
		$classes = [];
		$dir = self::ROOT . '/src/Domain/Metric';
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
				continue;
			}
			$source = \file_get_contents($file->getPathname());
			self::assertNotFalse($source);
			if (\preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
				continue;
			}
			if (\preg_match('/^final (?:readonly )?class (\w+)/m', $source, $cls) !== 1) {
				continue;
			}
			$class = $ns[1] . '\\' . $cls[1];
			if (\class_exists($class) && \is_subclass_of($class, Metric::class)) {
				$classes[] = $class;
			}
		}
		\sort($classes);
		return $classes;
	}
}
