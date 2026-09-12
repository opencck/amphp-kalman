<?php declare(strict_types=1);

/**
 * The weighted mid, and the side averages it is not (M-19).
 *
 *   php examples/liquidity-weighted-prices.php
 *
 * `Domain\Metric\Liquidity\WeightedPrices` reports both, and only one of them
 * is short-horizon fair value. The weighted mid crosses its weights — the bid
 * *size* multiplies the ask *price* — so size resting on the bid pushes fair
 * value up towards the ask. That is the whole content of the measure. The
 * whole-side averages the reference prefers describe the geometry of resting
 * orders, most of which will never trade, and they move when somebody posts
 * size far from the touch, which is not news.
 */

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Metric\Liquidity\WeightedPrices;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

$ts = 1_700_000_000_000_000_000;

/**
 * A five-deep book with a chosen size at the best bid and an optional block
 * parked far below the market.
 *
 * @return OrderBook
 */
$build = static function (float $bestBidSize, float $farBlock = 0.0) use ($ts): OrderBook {
	$bids = [[99.99, $bestBidSize], [99.98, 5.0], [99.97, 5.0], [99.96, 5.0], [99.95, 5.0]];
	$asks = [[100.01, 10.0], [100.02, 5.0], [100.03, 5.0], [100.04, 5.0], [100.05, 5.0]];
	if ($farBlock > 0.0) {
		$bids[] = [99.50, $farBlock];
	}
	return OrderBook::of($ts, $bids, $asks);
};

Synthetic::heading(
	'The weighted mid leans towards the side with less size (M-19)',
	'best ask fixed at 10 units on 100.01; the best bid size varies on 99.99',
);

echo "  bidSize  askSize        mid   weightedMid   tilt(bp)  leans\n";
echo "  " . \str_repeat('-', 60) . "\n";
foreach ([1.0, 5.0, 10.0, 20.0, 50.0] as $bidSize) {
	$metric = new WeightedPrices(5);
	$metric->updateBook($build($bidSize));
	$v = $metric->values();
	$tilt = $v['tilt'] ?? \NAN;
	echo \sprintf(
		"  %7s  %7s  %9s  %12s  %9s  %s\n",
		Synthetic::fmt($bidSize, 1),
		Synthetic::fmt(10.0, 1),
		Synthetic::fmt($v['mid'] ?? \NAN, 5),
		Synthetic::fmt($v['weightedMid'] ?? \NAN, 5),
		Synthetic::fmt($tilt, 3),
		$tilt > 0.0 ? 'ask' : ($tilt < 0.0 ? 'bid' : 'neither'),
	);
}

echo "\n  Fifty units bid against ten offered puts fair value 0.667 bp above the\n";
echo "  arithmetic mid — two thirds of the way from the mid to the ask. The crossed\n";
echo "  weighting is not a typo: a queue that deep on the bid is buying pressure, and\n";
echo "  the side that runs out first is where the price goes.\n";

Synthetic::heading(
	'A block parked 50 bps away moves the side averages, not the weighted mid',
	'the same book, plus 1,000 units resting at 99.50 — half a percent below the market',
);

echo "  book                         WABP        WAAP   weightedMid   tilt(bp)\n";
echo "  " . \str_repeat('-', 72) . "\n";
foreach (['no block' => $build(10.0), 'with 1,000 at 99.50' => $build(10.0, 1000.0)] as $label => $snapshot) {
	$wholeSide = new WeightedPrices(0);
	$wholeSide->updateBook($snapshot);
	$v = $wholeSide->values();
	echo \sprintf(
		"  %-22s %10s  %10s  %12s  %9s\n",
		$label,
		Synthetic::fmt($v['bid'] ?? \NAN, 5),
		Synthetic::fmt($v['ask'] ?? \NAN, 5),
		Synthetic::fmt($v['weightedMid'] ?? \NAN, 5),
		Synthetic::fmt($v['tilt'] ?? \NAN, 3),
	);
}

$plain = new WeightedPrices(0);
$plain->updateBook($build(10.0));
$blocked = new WeightedPrices(0);
$blocked->updateBook($build(10.0, 1000.0));
$plainValues = $plain->values();
$blockedValues = $blocked->values();
$shift = ($blockedValues['bid'] ?? \NAN) - ($plainValues['bid'] ?? \NAN);

echo "\n";
echo \sprintf(
	"The whole-side bid average moves %s in price, about %s basis points, because a\n",
	Synthetic::fmt($shift, 4),
	Synthetic::fmt(\abs($shift) / 100.0 * 10000.0, 1),
);
echo "block fifty basis points from the touch now dominates the weighting. Nothing\n";
echo "executable changed: the best bid, the best ask and their sizes are identical,\n";
echo "and the weighted mid is identical too. That insensitivity is the point. An\n";
echo "average taken over the whole side is a statement about where orders are parked,\n";
echo "and parking is cheap; the weighted mid is a statement about what is about to\n";
echo "trade, which is why it predicts the next mid change and the side average does\n";
echo "not. The `Microprice` model filters this same quantity rather than reading it raw.\n";
