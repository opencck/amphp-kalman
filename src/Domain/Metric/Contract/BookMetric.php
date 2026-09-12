<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Contract;

use OpenCCK\Kalman\Domain\Entity\OrderBook;

/**
 * A metric computed from an order-book snapshot: imbalance, liquidity density,
 * book slope, cost to trade, weighted prices, spread, order-flow imbalance.
 *
 * `OrderBook` guarantees sorted sides, which the reference implementation this
 * library replaces did not (ROADMAP §4.5 M-17, defect 1): it read the best bid
 * with `array_slice($bid, 0, 1)` on a map keyed by price and updated in place,
 * so the "best" level was whichever price happened to be inserted first.
 */
interface BookMetric extends Metric
{
	public function updateBook(OrderBook $book): void;
}
