<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * §7.7 + ADR-002 + ADR-005: static rules over the source tree.
 */
final class ArchitectureTest extends TestCase
{
	private const SRC = __DIR__ . '/../../src';

	/** @return list<string> */
	private static function phpFiles(string $dir): array
	{
		$files = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}
		\sort($files);
		return $files;
	}

	private static function read(string $path): string
	{
		$c = \file_get_contents($path);
		self::assertNotFalse($c);
		return $c;
	}

	public function testDomainAndAppHaveNoAmpImports(): void
	{
		$offenders = [];
		foreach (['Domain', 'App'] as $layer) {
			$dir = self::SRC . '/' . $layer;
			if (!\is_dir($dir)) {
				continue;
			}
			foreach (self::phpFiles($dir) as $file) {
				$src = self::read($file);
				if (\preg_match('/^use (Amp|Revolt)\\\\/m', $src) === 1 || \preg_match('/\\\\(Amp|Revolt)\\\\/', $src) === 1) {
					$offenders[] = $file;
				}
			}
		}
		self::assertSame([], $offenders, 'Only src/Infrastructure may use Amp\\ / Revolt\\');
	}

	public function testLayerDependenciesPointDownwards(): void
	{
		$offenders = [];
		foreach (self::phpFiles(self::SRC . '/Domain') as $file) {
			$src = self::read($file);
			if (\preg_match('/OpenCCK\\\\Kalman\\\\(App|Infrastructure)\\\\/', $src) === 1) {
				$offenders[] = $file . ' → App/Infrastructure';
			}
		}
		if (\is_dir(self::SRC . '/App')) {
			foreach (self::phpFiles(self::SRC . '/App') as $file) {
				$src = self::read($file);
				if (\preg_match('/OpenCCK\\\\Kalman\\\\Infrastructure\\\\/', $src) === 1) {
					$offenders[] = $file . ' → Infrastructure';
				}
			}
		}
		self::assertSame([], $offenders);
	}

	public function testStrictTypesEverywhere(): void
	{
		$offenders = [];
		foreach (\array_merge(self::phpFiles(self::SRC), self::phpFiles(__DIR__ . '/..')) as $file) {
			$src = self::read($file);
			if (!\str_starts_with($src, "<?php declare(strict_types=1);")) {
				$offenders[] = $file;
			}
		}
		self::assertSame([], $offenders, 'Every file must start with "<?php declare(strict_types=1);"');
	}

	public function testJsonThrowsEverywhere(): void
	{
		$offenders = [];
		foreach (\array_merge(self::phpFiles(self::SRC), self::phpFiles(__DIR__ . '/..')) as $file) {
			$src = self::read($file);
			if (\preg_match_all('/json_(?:encode|decode)\s*\(([^;]*)\)\s*;/s', $src, $m) > 0) {
				foreach ($m[1] as $args) {
					if (!\str_contains($args, 'JSON_THROW_ON_ERROR')) {
						$offenders[] = $file;
						break;
					}
				}
			}
		}
		self::assertSame([], $offenders, 'json_encode/json_decode without JSON_THROW_ON_ERROR');
	}

	public function testNoNestedArraysInHotCore(): void
	{
		$offenders = [];
		foreach (['Domain/Linalg', 'Domain/Covariance', 'Domain/Filter'] as $dir) {
			$path = self::SRC . '/' . $dir;
			if (!\is_dir($path)) {
				continue;
			}
			foreach (self::phpFiles($path) as $file) {
				if (\basename($file) === 'Nested.php') {
					continue; // documented boundary converter (Domain\Linalg\Nested)
				}
				$src = self::read($file);
				// matrices must never be passed or returned as nested arrays in the hot core;
				// (maps of matrices in private caches are fine — only @param/@return are checked)
				if (\preg_match('/@(param|return)\s+(list<\s*list<[^>]*float|array<\s*int\s*,\s*array<\s*int\s*,\s*float|float\[\]\[\])/', $src) === 1) {
					$offenders[] = $file;
				}
			}
		}
		self::assertSame([], $offenders, 'Nested float arrays are forbidden in Linalg / Covariance / Filter (§0.2 p.13)');
	}

	public function testNoBlockingCallsOutsideInfrastructureTests(): void
	{
		$forbidden = ['sleep(', 'usleep(', 'file_get_contents(', 'file_put_contents(', 'curl_exec(', 'new \\PDO', 'new PDO'];
		$offenders = [];
		foreach (self::phpFiles(self::SRC) as $file) {
			$src = self::read($file);
			foreach ($forbidden as $needle) {
				if (\str_contains($src, $needle)) {
					$offenders[] = $file . ' uses ' . $needle;
				}
			}
		}
		self::assertSame([], $offenders);
	}

	/**
	 * ADR-005: every @offloadable method is public static and takes/returns
	 * only scalars/arrays (serialisable across a process boundary).
	 */
	public function testOffloadableMethodsArePureStaticAndSerializable(): void
	{
		$found = 0;
		$offenders = [];
		foreach (self::phpFiles(self::SRC) as $file) {
			$src = self::read($file);
			if (!\str_contains($src, '@offloadable')) {
				continue;
			}
			if (\preg_match('/^namespace\s+([^;]+);/m', $src, $ns) !== 1 || \preg_match('/^(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?(?:class|interface|enum|trait)\s+(\w+)/m', $src, $cls) !== 1) {
				$offenders[] = $file . ': cannot resolve class';
				continue;
			}
			$class = $ns[1] . '\\' . $cls[1];
			// Interfaces and enums may mention @offloadable in their prose (the
			// metric contract does); only their real methods are checked.
			if (!\class_exists($class) && !\interface_exists($class) && !\enum_exists($class)) {
				$offenders[] = $file . ': type ' . $class . ' does not exist';
				continue;
			}
			$ref = new \ReflectionClass($class);
			foreach ($ref->getMethods() as $method) {
				$doc = $method->getDocComment();
				if ($doc === false || !\str_contains($doc, '@offloadable')) {
					continue;
				}
				$found++;
				$label = $class . '::' . $method->getName();
				if (!$method->isStatic() || !$method->isPublic()) {
					$offenders[] = "$label must be public static";
				}
				if (!\str_contains($doc, '@deterministic')) {
					$offenders[] = "$label must also carry @deterministic";
				}
				foreach ($method->getParameters() as $p) {
					if (!self::isSerializableType($p->getType())) {
						$offenders[] = "$label parameter \${$p->getName()} is not scalar/array";
					}
				}
				if (!self::isSerializableType($method->getReturnType())) {
					$offenders[] = "$label return type is not scalar/array";
				}
			}
		}
		self::assertSame([], $offenders);
		self::assertGreaterThan(0, $found, 'expected at least one @offloadable method');
	}

	private static function isSerializableType(?\ReflectionType $type): bool
	{
		if ($type === null) {
			return false;
		}
		if ($type instanceof \ReflectionUnionType) {
			foreach ($type->getTypes() as $t) {
				if (!self::isSerializableType($t)) {
					return false;
				}
			}
			return true;
		}
		if (!$type instanceof \ReflectionNamedType) {
			return false;
		}
		return \in_array($type->getName(), ['int', 'float', 'string', 'bool', 'array', 'null'], true);
	}
	/** ADR-006: FFI is confined to Domain\Linalg\Ffi and the BLAS covariance representation. */
	public function testFfiIsConfinedToTheBlasBackend(): void
	{
		$offenders = [];
		foreach (self::phpFiles(self::SRC) as $file) {
			$normalised = \str_replace('\\', '/', $file);
			if (\str_contains($normalised, '/Domain/Linalg/Ffi/') || \str_ends_with($normalised, '/Domain/Covariance/BlasDense.php')) {
				continue;
			}
			$src = self::read($file);
			if (\preg_match('/\\\\FFI\b|\buse FFI\b|FFI::/', $src) === 1) {
				$offenders[] = $file;
			}
		}
		self::assertSame([], $offenders, 'FFI usage outside Domain\Linalg\Ffi / Covariance\BlasDense');
	}

	/**
	 * Every namespaced file sits at the path PSR-4 derives from its namespace,
	 * compared case-sensitively.
	 *
	 * This is a Windows-to-Linux guard rather than a style rule. The project is
	 * developed on a case-insensitive filesystem, where `examples/decoders/`
	 * happily autoloads `OpenCCK\Kalman\Examples\Decoders\…`; on the Linux
	 * runner the same code dies with "Class not found", and only at the moment
	 * something first touches that class — for the decoders, inside a benchmark
	 * child process, several jobs deep. Comparing the declared namespace with
	 * the real path catches it on the developer's own machine instead.
	 */
	public function testFilePathsMatchTheirNamespaceCase(): void
	{
		$root = \str_replace('\\', '/', \dirname(__DIR__, 2));
		$composer = \json_decode(self::read($root . '/composer.json'), true, 32, \JSON_THROW_ON_ERROR);
		self::assertIsArray($composer);

		/** @var array<string, string> $roots */
		$roots = [];
		foreach (['autoload', 'autoload-dev'] as $section) {
			$psr4 = $composer[$section]['psr-4'] ?? [];
			if (!\is_array($psr4)) {
				continue;
			}
			foreach ($psr4 as $prefix => $directory) {
				if (\is_string($prefix) && \is_string($directory)) {
					$roots[$prefix] = \rtrim($directory, '/');
				}
			}
		}
		self::assertNotSame([], $roots, 'composer.json must declare PSR-4 roots');

		$offenders = [];
		foreach ($roots as $prefix => $directory) {
			$base = $root . '/' . $directory;
			if (!\is_dir($base)) {
				continue;
			}
			foreach (self::phpFiles($base) as $file) {
				$source = self::read($file);
				if (\preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
					// a plain script, not a PSR-4 class file
					continue;
				}
				$namespace = \trim($matches[1]) . '\\';
				if (!\str_starts_with($namespace, $prefix)) {
					continue;
				}

				$expected = $base . '/' . \str_replace('\\', '/', \substr($namespace, \strlen($prefix)))
					. \basename($file);
				$actual = \str_replace('\\', '/', $file);

				// a case-sensitive comparison, which is the whole point
				if ($actual !== \str_replace('//', '/', $expected)) {
					$offenders[] = \substr($actual, \strlen($root) + 1) . ' declares ' . \trim($matches[1]);
				}
			}
		}

		self::assertSame([], $offenders, 'PSR-4 path and namespace disagree (case-sensitively)');
	}
}
