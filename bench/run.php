<?php declare(strict_types=1);

/**
 * Benchmark runner (§6.6).
 *
 *   php -dopcache.enable_cli=1 -dopcache.jit=1255 -dopcache.jit_buffer_size=128M bench/run.php
 *       [--filter=layout,form] [--save-baseline] [--compare] [--tolerance=0.05] [--quiet] [--no-isolate]
 *
 * Every benchmark runs in its OWN child process with the parent's engine
 * configuration (Infrastructure\Task\WorkerPools::binary()) and, on Windows,
 * its own OPcache segment (opcache.cache_id). Reasons: the tracing JIT has a
 * per-segment budget of traces and specialises them for the first code path
 * that gets hot; a single process (or, on Windows, a family of processes
 * sharing one segment) running all benchmarks ends up with later benchmarks
 * exiting to the interpreter — 3–8× slower numbers that look like regressions.
 * --no-isolate runs everything in-process (what the children do).
 *
 * Writes bench/results/latest.json. With --compare, exits 1 when any metric is
 * slower than bench/results/baseline.json by more than the tolerance
 * (allocation metrics must be exactly 0).
 *
 * Both files record the engine environment twice: once at the top level for the
 * run as a whole, and once per benchmark under "environments". The per-benchmark
 * copy is what makes a partial baseline honest — `--filter=metric
 * --save-baseline` merges one benchmark into a baseline whose other entries were
 * recorded on another engine, and a single top-level block would then claim all
 * of them came from this run. --compare warns when a benchmark's baseline was
 * recorded on a different PHP or JIT setting, so that a number read off the
 * output is not mistaken for a like-for-like comparison. It still gates on it:
 * CI runs against the maintainer's baseline on purpose, with --tolerance=1.0, to
 * catch the one thing that survives a change of engine — a 2× fall back to the
 * interpreter. Allocation metrics are compared unconditionally.
 */

use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Bench\Support\Timer;
use OpenCCK\Kalman\Domain\Diagnostics\JitSanity;
use OpenCCK\Kalman\Infrastructure\Task\WorkerPools;

require __DIR__ . '/../vendor/autoload.php';

$options = \getopt('', ['filter::', 'save-baseline', 'compare', 'tolerance::', 'quiet', 'no-isolate', 'child']);
$filter = isset($options['filter']) && \is_string($options['filter']) ? \array_map('trim', \explode(',', $options['filter'])) : null;
$tolerance = isset($options['tolerance']) && \is_string($options['tolerance']) ? (float) $options['tolerance'] : 0.05;
$quiet = isset($options['quiet']);
$isolate = !isset($options['no-isolate']) && !isset($options['child']);
$child = isset($options['child']);

$env = Timer::environment();
if (!$quiet && !$child) {
	\fwrite(\STDOUT, \sprintf(
		"PHP %s | opcache %s | JIT %s | xdebug %s | assertions %s\n",
		$env['php'],
		$env['opcache_cli'] ? 'on' : 'OFF',
		$env['jit'] ?? 'OFF',
		$env['xdebug'] ? 'LOADED (numbers unreliable)' : 'off',
		$env['assertions'],
	));
}

// §8 / ADR-007: refuse to produce numbers with a JIT that miscompiles the library's own kernel shapes,
// and record which known-bad shapes the engine miscompiles (the library avoids them)
JitSanity::verify();
if (!$quiet && !$child && JitSanity::engineBugs() !== []) {
	\fwrite(\STDOUT, "engine miscompiles (avoided by the library): " . \implode('; ', JitSanity::engineBugs()) . "\n");
}

/** @var array<string, Benchmark> $benchmarks */
$benchmarks = [];
$benchFiles = \glob(__DIR__ . '/*Bench.php');
foreach ($benchFiles === false ? [] : $benchFiles as $file) {
	$class = 'OpenCCK\\Kalman\\Bench\\' . \basename($file, '.php');
	if (!\class_exists($class)) {
		continue;
	}
	/** @psalm-suppress MixedMethodCall — benchmarks are discovered by file name; class_exists() above is the guard */
	$instance = new $class();
	if (!$instance instanceof Benchmark) {
		continue;
	}
	if ($filter !== null && !\in_array($instance->name(), $filter, true)) {
		continue;
	}
	$benchmarks[$instance->name()] = $instance;
}
\ksort($benchmarks);

/**
 * Runs one benchmark in a child process with the same engine flags; returns its metrics.
 *
 * @return array<string, float>
 */
$runIsolated = static function (string $name): array {
	// own OPcache segment per child: on Windows CLI processes share one segment and the tracing-JIT state with it
	$cmd = [...WorkerPools::binary(true, 'bench-' . $name . '-' . (\getmypid() === false ? 'parent' : (string) \getmypid())), __FILE__, '--filter=' . $name, '--child', '--quiet'];
	$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
	$process = \proc_open($cmd, $descriptors, $pipes, \dirname(__DIR__), null, ['bypass_shell' => true]);
	if (!\is_resource($process)) {
		throw new RuntimeException("could not start a child process for benchmark $name");
	}
	\fclose($pipes[0]);
	$stdout = (string) \stream_get_contents($pipes[1]);
	$stderr = (string) \stream_get_contents($pipes[2]);
	\fclose($pipes[1]);
	\fclose($pipes[2]);
	$exit = \proc_close($process);
	if ($exit !== 0) {
		throw new RuntimeException("benchmark $name failed in its child process (exit $exit):\n$stderr\n$stdout");
	}
	$line = \strrchr(\rtrim($stdout), "\n");
	$json = $line === false ? \rtrim($stdout) : \trim($line);
	/** @var array<string, float> $metrics */
	$metrics = \json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
	return $metrics;
};

$results = ['environment' => $env, 'generated' => \date(\DATE_ATOM), 'benchmarks' => [], 'environments' => []];
foreach ($benchmarks as $name => $bench) {
	if (!$quiet && !$child) {
		\fwrite(\STDOUT, "== $name\n");
	}
	$t0 = \hrtime(true);
	$metrics = $isolate ? $runIsolated($name) : $bench->run();
	$results['benchmarks'][$name] = $metrics;
	// children inherit the parent's engine flags (WorkerPools::binary), so the parent's reading describes them too
	$results['environments'][$name] = $env;
	if ($child) {
		// the last stdout line is the machine-readable result for the parent
		\fwrite(\STDOUT, \json_encode($metrics, \JSON_THROW_ON_ERROR) . "\n");
		continue;
	}
	foreach ($metrics as $metric => $value) {
		if (!$quiet) {
			\fwrite(\STDOUT, \sprintf("  %-40s %12.3f\n", $metric, $value));
		}
	}
	if (!$quiet) {
		\fwrite(\STDOUT, \sprintf("  (%.1f s)\n", (\hrtime(true) - $t0) / 1e9));
	}
}
if ($child) {
	exit(0);
}

$dir = __DIR__ . '/results';
if (!\is_dir($dir)) {
	\mkdir($dir, 0777, true);
}
$json = \json_encode($results, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
\file_put_contents($dir . '/latest.json', $json);
if (isset($options['save-baseline'])) {
	// merge with an existing baseline so that --filter runs update only their own benchmarks
	$merged = $results;
	if (\is_file($dir . '/baseline.json')) {
		/** @var array{benchmarks?: array<string, array<string, float>>, environments?: array<string, array<string, mixed>>} $existing */
		$existing = \json_decode((string) \file_get_contents($dir . '/baseline.json'), true, 512, \JSON_THROW_ON_ERROR);
		$merged['benchmarks'] = \array_merge($existing['benchmarks'] ?? [], $results['benchmarks']);
		\ksort($merged['benchmarks']);
		// an entry this run did not record keeps the environment it was recorded in; one it did record gets this run's
		$mergedEnvironments = \array_merge($existing['environments'] ?? [], $results['environments']);
		foreach (\array_keys($merged['benchmarks']) as $recorded) {
			if (!isset($mergedEnvironments[$recorded])) {
				// a baseline written before environments were recorded per benchmark
				$mergedEnvironments[$recorded] = $existing['environment'] ?? [];
			}
		}
		\ksort($mergedEnvironments);
		$merged['environments'] = $mergedEnvironments;
	}
	\file_put_contents($dir . '/baseline.json', \json_encode($merged, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
	if (!$quiet) {
		\fwrite(\STDOUT, "baseline saved\n");
	}
}

$exit = 0;
if (isset($options['compare'])) {
	$baselinePath = $dir . '/baseline.json';
	if (!\is_file($baselinePath)) {
		\fwrite(\STDERR, "no baseline at $baselinePath — run with --save-baseline first\n");
		exit(2);
	}
	/** @var array{benchmarks: array<string, array<string, float>>, environment?: array<string, mixed>, environments?: array<string, array<string, mixed>>} $baseline */
	$baseline = \json_decode((string) \file_get_contents($baselinePath), true, 512, \JSON_THROW_ON_ERROR);
	// a recorded environment value is whatever the JSON held; render it without assigning it to a typed local
	$describeEnv = static fn (mixed $value, string $fallback): string => \is_scalar($value) ? (string) $value : $fallback;
	foreach ($results['benchmarks'] as $name => $metrics) {
		// a baseline entry may predate any one of Timer::environment()'s keys, so every read is guarded
		/** @var array<string, mixed> $recordedIn */
		$recordedIn = $baseline['environments'][$name] ?? $baseline['environment'] ?? [];
		$sameEngine = ($recordedIn['php'] ?? null) === ($env['php'] ?? null)
			&& ($recordedIn['jit'] ?? null) === ($env['jit'] ?? null)
			&& ($recordedIn['xdebug'] ?? null) === ($env['xdebug'] ?? null);
		if (!$sameEngine && !$quiet && $recordedIn !== []) {
			\fwrite(\STDOUT, \sprintf(
				"  note: %s baseline comes from PHP %s (JIT %s, xdebug %s), not this engine — only a gross timing change means anything\n",
				$name,
				$describeEnv($recordedIn['php'] ?? null, '?'),
				$describeEnv($recordedIn['jit'] ?? null, 'off'),
				($recordedIn['xdebug'] ?? null) === true ? 'loaded' : 'off',
			));
		}
		foreach ($metrics as $metric => $value) {
			$base = $baseline['benchmarks'][$name][$metric] ?? null;
			// an allocation metric is absolute, not relative: it must be 0 even for a benchmark
			// the baseline does not know yet, or a new one would enter the suite ungated
			if (\str_ends_with($metric, '_bytes')) {
				if ($value > 0.0) {
					\fwrite(\STDERR, "REGRESSION $name.$metric: $value bytes allocated (must be 0)\n");
					$exit = 1;
				}
				continue;
			}
			if ($base === null) {
				continue;
			}
			if ($base > 0.0 && $value > $base * (1.0 + $tolerance)) {
				\fwrite(\STDERR, \sprintf("REGRESSION %s.%s: %.3f vs baseline %.3f (+%.1f%%)\n", $name, $metric, $value, $base, 100.0 * ($value / $base - 1.0)));
				$exit = 1;
			}
		}
	}
	if ($exit === 0 && !$quiet) {
		\fwrite(\STDOUT, "no regressions beyond " . ($tolerance * 100) . "%\n");
	}
}
exit($exit);
