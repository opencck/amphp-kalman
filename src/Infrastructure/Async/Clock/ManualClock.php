<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Async\Clock;

use OpenCCK\Kalman\Domain\Contract\Clock;

/**
 * Deterministic clock for tests and simulations: time moves only when told.
 */
final class ManualClock implements Clock
{
	public function __construct(private int $nowNs = 0)
	{
	}

	public function realtimeNs(): int
	{
		return $this->nowNs;
	}

	public function set(int $nowNs): void
	{
		$this->nowNs = $nowNs;
	}

	public function advance(int $deltaNs): void
	{
		$this->nowNs += $deltaNs;
	}
}
