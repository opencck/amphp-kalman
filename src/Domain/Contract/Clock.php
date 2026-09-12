<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Source of wall-clock time in nanoseconds since the Unix epoch, comparable
 * with exchange timestamps. Used ONLY where no data timestamp exists (blind
 * bar ticks, snapshot stamps) — never for dt between measurements (§0.2 p.16).
 */
interface Clock
{
	public function realtimeNs(): int;
}
