<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Task;

use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;

/**
 * §5.9 worker pools whose child processes run with the SAME ini as the
 * parent (extension dir, loaded extensions, JIT mode, assertions, memory
 * limit). amphp/parallel spawns children with the bare PHP binary and its
 * php.ini, which may mean "Xdebug on, JIT off" — 10× slower workers — or a
 * DIFFERENT JIT mode than the parent. Filter results are bit-identical
 * between parent and worker only when both run the same engine configuration
 * (see Diagnostics\JitSanity), so production deployments should build their
 * pools here rather than with the default workerPool().
 */
final class WorkerPools
{
	private function __construct()
	{
	}

	/**
	 * @param bool $isolateOpcache Windows only: give the pool its own OPcache segment (see binary())
	 */
	public static function likeParent(int $limit, bool $isolateOpcache = true): ContextWorkerPool
	{
		return new ContextWorkerPool($limit, new ContextWorkerFactory(contextFactory: new ProcessContextFactory(binary: self::binary($isolateOpcache))));
	}

	/**
	 * The php command line that reproduces the parent's engine configuration.
	 *
	 * @param bool $isolateOpcache Windows only: add a process-group-specific opcache.cache_id
	 * @param string|null $cacheId explicit opcache.cache_id (default: kalman-<parent pid>)
	 * @return non-empty-list<string>
	 */
	public static function binary(bool $isolateOpcache = true, ?string $cacheId = null): array
	{
		$binary = [\PHP_BINARY, '-n'];
		$extDir = \ini_get('extension_dir');
		if (\is_string($extDir) && $extDir !== '') {
			$binary[] = '-dextension_dir=' . $extDir;
		}
		if (\extension_loaded('Zend OPcache')) {
			$binary[] = '-dzend_extension=opcache';
		}
		if (\PHP_OS_FAMILY === 'Windows') {
			// shared extensions on Windows; on Linux distributions they are usually compiled in or loaded via conf.d
			foreach (['openssl', 'sockets', 'mbstring', 'zlib', 'pcntl', 'posix', 'ffi'] as $ext) {
				if (\extension_loaded($ext)) {
					$binary[] = '-dextension=' . $ext;
				}
			}
		}
		foreach (['opcache.enable_cli', 'opcache.jit', 'opcache.jit_buffer_size', 'opcache.jit_max_root_traces', 'opcache.jit_max_side_traces', 'opcache.jit_max_exit_counters', 'zend.assertions', 'memory_limit', 'ffi.enable'] as $key) {
			$value = \ini_get($key);
			if (\is_string($value) && $value !== '') {
				$binary[] = '-d' . $key . '=' . $value;
			}
		}
		if (\PHP_OS_FAMILY === 'Windows' && $isolateOpcache) {
			// Windows CLI processes with the same binary and ini attach to ONE OPcache shared-memory segment,
			// tracing-JIT state included: traces specialised by one process make hot loops in another exit to the
			// interpreter (3–8× slower). A pool-specific cache id gives the workers their own segment.
			$pid = \getmypid();
			$binary[] = '-dopcache.cache_id=' . ($cacheId ?? ('kalman-' . ($pid === false ? 'parent' : $pid)));
		}
		return $binary;
	}
}
