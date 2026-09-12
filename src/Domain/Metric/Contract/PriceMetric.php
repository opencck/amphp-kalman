<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Contract;

/**
 * A metric fed by a price series: last trade, mid, or any other single number
 * carrying a timestamp.
 *
 * `$timestampNs` is exchange time in nanoseconds, never a local clock
 * (BRIEF §2 p.6). Time-based metrics use it for the decay `1 − exp(−dt/τ)`;
 * count-based ones ignore it.
 */
interface PriceMetric extends Metric
{
	public function updatePrice(int $timestampNs, float $price): void;
}
