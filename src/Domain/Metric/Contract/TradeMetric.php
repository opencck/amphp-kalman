<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Contract;

use OpenCCK\Kalman\Domain\Entity\Trade;

/**
 * A metric fed by the trade tape: volume, VWAP, VPIN, Kyle's lambda, Amihud.
 *
 * The tape carries size and aggressor side, which a sampled price gauge cannot
 * reconstruct — see the ROADMAP note on `sum_over_time` over a volume gauge
 * (§4.4 M-14): summing a gauge measures the scrape interval, not the market.
 */
interface TradeMetric extends Metric
{
	public function updateTrade(Trade $trade): void;
}
