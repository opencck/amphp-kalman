<?php declare(strict_types=1);

/**
 * Order-book imbalance, and why depth is a parameter (M-16).
 *
 *   php examples/liquidity-obi.php
 *
 * `Domain\Metric\Liquidity\OrderBookImbalance` defaults to five levels. The
 * reference summed the whole book, and this example shows what that costs:
 * one book, four depths, and two opposite answers, because a wall parked six
 * basis points out — where quoting is nearly free and nothing will execute in
 * any horizon the signal is used on — outvotes everything at the touch.
 */

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Metric\Liquidity\OrderBookImbalance;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/**
 * Prints one row of the depth table.
 *
 * The Metric contract declares `values()` as `array<string, float>`, so no key
 * is statically guaranteed; each is read with a coalesce.
 *
 * @param array<string, float> $values
 */
function obiRow(string $label, array $values): void
{
	$imbalance = $values['imbalance'] ?? \NAN;
	echo \sprintf(
		"  %5s  %8s  %8s  %9s  %8s  %8s   %s\n",
		$label,
		Synthetic::fmt($values['bidVolume'] ?? \NAN, 1),
		Synthetic::fmt($values['askVolume'] ?? \NAN, 1),
		Synthetic::fmt($imbalance, 3),
		Synthetic::fmt($values['ratio'] ?? \NAN, 3),
		Synthetic::fmt($values['logRatio'] ?? \NAN, 3),
		$imbalance > 0.0 ? 'buyers' : 'sellers',
	);
}

$mid = 100.0;
$tick = 0.01;

// Levels 1-5 (1-5 bps out) are the market: small bids, larger asks, so the
// executable book leans to the sell side. Levels 6-20 carry a bid wall.
$bids = [];
$asks = [];
for ($i = 1; $i <= 20; $i++) {
	$bids[] = [\round($mid - $tick * $i, 6), $i <= 5 ? 2.0 : 60.0];
	$asks[] = [\round($mid + $tick * $i, 6), $i <= 5 ? 6.0 : 5.0];
}
$book = OrderBook::of(1_700_000_000_000_000_000, $bids, $asks);

Synthetic::heading(
	'Order-book imbalance at four depths (M-16)',
	'one snapshot: 2 per bid level and 6 per ask level at the touch, a 60-lot bid wall from 6 bps out',
);

echo "  depth     V_bid     V_ask  imbalance     ratio  logRatio   reading\n";
echo "  " . \str_repeat('-', 72) . "\n";
foreach ([1, 5, 10, 0] as $levels) {
	$metric = new OrderBookImbalance($levels);
	$metric->updateBook($book);
	obiRow($levels === 0 ? 'all' : (string) $levels, $metric->values());
}

$decay = new OrderBookImbalance(0, 3.0);
$decay->updateBook($book);
obiRow('e^-d', $decay->values());
echo "  (the last row is the whole book with decayBps = 3: no cut-off, exponential distance weight)\n";

Synthetic::heading(
	'The ratio is asymmetric, the log ratio is not',
	'the same three-to-one preponderance, read from each side',
);

// The same book reflected: now the bids at the touch carry three times the asks.
$mirrorBids = [];
$mirrorAsks = [];
for ($i = 1; $i <= 20; $i++) {
	$mirrorBids[] = [\round($mid - $tick * $i, 6), $i <= 5 ? 6.0 : 60.0];
	$mirrorAsks[] = [\round($mid + $tick * $i, 6), $i <= 5 ? 2.0 : 5.0];
}
$mirror = OrderBook::of(1_700_000_000_000_000_000, $mirrorBids, $mirrorAsks);

echo "  case                        V_bid   V_ask     ratio  |ratio-1|  logRatio\n";
echo "  " . \str_repeat('-', 72) . "\n";
foreach ([['asks three times the bids', $book], ['bids three times the asks', $mirror]] as [$label, $snapshot]) {
	$metric = new OrderBookImbalance(5);
	$metric->updateBook($snapshot);
	$v = $metric->values();
	$ratio = $v['ratio'] ?? \NAN;
	echo \sprintf(
		"  %-26s %6s  %6s  %8s  %9s  %8s\n",
		$label,
		Synthetic::fmt($v['bidVolume'] ?? \NAN, 1),
		Synthetic::fmt($v['askVolume'] ?? \NAN, 1),
		Synthetic::fmt($ratio, 3),
		Synthetic::fmt(\abs($ratio - 1.0), 3),
		Synthetic::fmt($v['logRatio'] ?? \NAN, 3),
	);
}

echo "\n";
echo "Read at one or five levels this book is sell-side: three times as much size is\n";
echo "offered as bid. Read whole, it flips to strongly buy-side, and nothing about the\n";
echo "market changed — only how many levels were counted. That also means the same\n";
echo "market reads differently on a 20-level feed and a 200-level one, which is the\n";
echo "practical reason the default is five.\n";
echo "The decayed row is the honest middle: it is continuous in price, so no level\n";
echo "flips the reading by drifting across a cut-off, but it discounts the wall rather\n";
echo "than excluding it — it fixes the discontinuity, not the spoof.\n";
echo "The ratio form is lopsided by construction: the identical three-to-one\n";
echo "preponderance reads 0.333 one way and 3.000 the other, so a symmetric threshold\n";
echo "on it is not symmetric at all. logRatio gives -1.099 and +1.099, and imbalance\n";
echo "gives -0.500 and +0.500 — bounded, which is why it is the default output.\n";
