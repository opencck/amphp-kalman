<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Async\Clock;

use OpenCCK\Kalman\Domain\Contract\Clock;

/**
 * CLOCK_REALTIME in nanoseconds (microsecond resolution from microtime).
 * NOT hrtime(): hrtime is monotonic and not anchored to the epoch, so it
 * cannot be compared with exchange timestamps.
 */
final class SystemClock implements Clock
{
	public function realtimeNs(): int
	{
		[$usec, $sec] = \explode(' ', \microtime());
		return (int) $sec * 1_000_000_000 + (int) \round((float) $usec * 1e9);
	}
}
