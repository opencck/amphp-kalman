<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench\Support;

/**
 * hrtime-based micro-benchmark helper. Benchmarks are the only place in the
 * project where wall-clock time is read (BRIEF §2, rule 16).
 */
final class Timer
{
	/**
	 * Runs $fn($iterations) after a warm-up and returns microseconds per iteration,
	 * taking the best of $repeats batches to reduce scheduler noise.
	 *
	 * @param callable(int): mixed $fn executes the operation $iterations times; any return
	 *                               value is ignored (kernels return a checksum so that the
	 *                               measured work cannot be optimised away)
	 */
	public static function microsPerOp(callable $fn, int $iterations, int $repeats = 5, int $warmup = 1): float
	{
		for ($w = 0; $w < $warmup; $w++) {
			$fn(\max(1, \intdiv($iterations, 10)));
		}
		$best = \INF;
		for ($r = 0; $r < $repeats; $r++) {
			$t0 = \hrtime(true);
			$fn($iterations);
			$t1 = \hrtime(true);
			$elapsed = ($t1 - $t0) / 1e3 / $iterations;
			if ($elapsed < $best) {
				$best = $elapsed;
			}
		}
		return $best;
	}

	/** Bytes of memory_get_usage(false) growth across $fn(). */
	public static function allocationDelta(callable $fn): int
	{
		\gc_collect_cycles();
		$before = \memory_get_usage(false);
		$fn();
		$after = \memory_get_usage(false);
		return $after - $before;
	}

	/** @return array{php: string, os: string, opcache_loaded: bool, opcache_cli: bool, jit: string|null, jit_buffer: string, xdebug: bool, assertions: string} */
	public static function environment(): array
	{
		$jit = \ini_get('opcache.jit');
		$opcacheCli = \ini_get('opcache.enable_cli');
		return [
			'php' => \PHP_VERSION,
			'os' => \PHP_OS_FAMILY,
			'opcache_loaded' => \extension_loaded('Zend OPcache'),
			'opcache_cli' => $opcacheCli === '1' || $opcacheCli === 'On',
			'jit' => $jit === false ? null : $jit,
			'jit_buffer' => (string) \ini_get('opcache.jit_buffer_size'),
			'xdebug' => \extension_loaded('xdebug'),
			'assertions' => (string) \ini_get('zend.assertions'),
		];
	}
}
