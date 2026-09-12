<?php declare(strict_types=1);

/**
 * The spread in four forms, and recovered from a tape with no book at all (M-20).
 *
 *   php examples/liquidity-spread.php
 *
 * `Domain\Metric\Liquidity\Spread` keeps apart what is displayed (quoted),
 * what is comparable (relative), and what a taker actually paid (effective).
 * `Spread::roll()` is the striking one: under Roll's model the only reason
 * consecutive price changes are negatively correlated is the bid-ask bounce,
 * so the spread falls out of the trade prices alone. When the autocovariance
 * comes out non-negative the model has no real root, and the implementation
 * returns NAN instead of a fabricated zero.
 */

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Metric\Liquidity\CostToTrade;
use OpenCCK\Kalman\Domain\Metric\Liquidity\Spread;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

$cheap = Synthetic::book(100.0, 20, 0.01);
$dear = Synthetic::book(20000.0, 20, 2.0);

Synthetic::heading('Quoted is not comparable; relative is (M-20)', 'two instruments two hundred times apart in price');

echo "  instrument       bid         ask      quoted   relative(bp)\n";
echo "  " . \str_repeat('-', 62) . "\n";
foreach (['$100 stock' => $cheap, '$20,000 future' => $dear] as $label => $book) {
	$metric = new Spread(100);
	$metric->updateBook($book);
	$v = $metric->values();
	echo \sprintf(
		"  %-14s %10s  %10s  %8s  %12s\n",
		$label,
		Synthetic::fmt($book->bestBid(), 2),
		Synthetic::fmt($book->bestAsk(), 2),
		Synthetic::fmt($v['quoted'] ?? \NAN, 2),
		Synthetic::fmt($v['relativeBps'] ?? \NAN, 3),
	);
}
echo "\n  A two-cent spread and a four-dollar spread are the same spread. Only the\n";
echo "  relative form says so.\n";

Synthetic::heading('Effective spread: what a taker pays once the order walks', 'buying from the $100 book, which quotes 2 cents');

$mid = $cheap->mid();
$quoted = $cheap->spread();
echo "     size   avgFill   effective   quoted   effective/quoted   levels touched\n";
echo "  " . \str_repeat('-', 76) . "\n";
foreach ([0.5, 1.0, 3.0, 10.0, 30.0] as $size) {
	$fill = CostToTrade::forSize($cheap->askPrices, $cheap->askSizes, $mid, $size, true);
	$effective = Spread::effective([$fill['avgPrice']], [$mid])[0];
	echo \sprintf(
		"  %7s  %8s  %10s  %7s  %17s  %14d\n",
		Synthetic::fmt($size, 1),
		Synthetic::fmt($fill['avgPrice'], 4),
		Synthetic::fmt($effective, 4),
		Synthetic::fmt($quoted, 4),
		Synthetic::fmt($effective / $quoted, 2),
		$fill['levels'],
	);
}
echo "\n  At the top level the effective spread equals the quoted one; past it, every\n";
echo "  additional level widens the gap. Quoting the displayed spread as the cost of\n";
echo "  immediacy is only correct for orders that never leave the touch.\n";

Synthetic::heading(
	"Roll's estimator: the spread from the tape alone",
	'a random walk plus a fixed half-spread bounce — no order book anywhere in this section',
);

$count = 4000;
$efficient = Synthetic::prices($count, 100.0, 0.01);
$bounce = Synthetic::trades($count, 100.0, 0.5);

echo "  true spread   Roll estimate    error   error(%)\n";
echo "  " . \str_repeat('-', 50) . "\n";
foreach ([0.02, 0.04, 0.10, 0.25] as $trueSpread) {
	$half = $trueSpread / 2.0;
	$observed = [];
	foreach ($efficient as $i => $price) {
		// The aggressor's side decides which quote the print lands on.
		$observed[] = $price + ($bounce[$i]->side === TradeSide::Buy ? $half : -$half);
	}
	$estimate = Spread::roll($observed);
	echo \sprintf(
		"  %11s   %13s  %7s  %9s\n",
		Synthetic::fmt($trueSpread, 4),
		Synthetic::fmt($estimate, 4),
		Synthetic::fmt($estimate - $trueSpread, 4),
		Synthetic::fmt(($estimate - $trueSpread) / $trueSpread * 100.0, 2),
	);
}

$trend = Synthetic::prices($count, 100.0, 0.0, 0.05);
$noBounce = Synthetic::prices($count, 100.0, 0.01);
echo "\n  and where the model has no root:\n\n";
echo \sprintf("  pure trend, no bounce      -> %s\n", \ltrim(Synthetic::fmt(Spread::roll($trend), 4)));
echo \sprintf("  random walk, no bounce     -> %s\n", \ltrim(Synthetic::fmt(Spread::roll($noBounce), 4)));

echo "\n";
echo "Four spreads spanning an order of magnitude, each recovered to within a few per\n";
echo "cent from four thousand prints and nothing else. That is what makes the estimator\n";
echo "worth having: venues that publish only a tape still yield a spread, and it is a\n";
echo "direct external check on the library's own `BidAskBounce` model, which estimates\n";
echo "the same effect as a filter state rather than as a moment.\n";
echo "On a pure trend the autocovariance is not negative, the square root does not\n";
echo "exist, and the answer is NAN. A zero there would have read as a frictionless\n";
echo "market, which is the opposite of what a trending tape is telling you. A bounce-\n";
echo "free random walk does return a number, 0.0016, but it is an order of magnitude\n";
echo "below the smallest real spread above — that is sampling noise, and it is the\n";
echo "estimator's honest floor rather than a claim about the market.\n";
