<?php declare(strict_types=1);

/**
 * What a market order would actually cost (M-36).
 *
 *   php examples/liquidity-cost-to-trade.php
 *
 * `Domain\Metric\Liquidity\CostToTrade` walks the book level by level and
 * reports the gap between the average fill and the mid. Imbalance, density and
 * slope describe the book; this one answers the question asked immediately
 * before an order goes out. When the book cannot fill the request it reports a
 * partial fill and a NAN cost rather than pretending the remainder executes at
 * the last level — an unfillable order is a different event from an expensive
 * one, and a strategy has to be able to tell them apart.
 */

use OpenCCK\Kalman\Domain\Metric\Liquidity\CostToTrade;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** Right-aligns by display width, so the dash Synthetic::fmt prints for NAN lines up. */
$pad = static function (float $value, int $decimals, int $width): string {
	$text = \ltrim(Synthetic::fmt($value, $decimals));
	$fill = $width - \mb_strlen($text);
	return ($fill > 0 ? \str_repeat(' ', $fill) : '') . $text;
};

$book = Synthetic::book(100.0, 20, 0.01);
$capacityBuy = 0.0;
$capacitySell = 0.0;
foreach ($book->askPrices as $i => $price) {
	$capacityBuy += $price * $book->askSizes[$i];
}
foreach ($book->bidPrices as $i => $price) {
	$capacitySell += $price * $book->bidSizes[$i];
}

Synthetic::heading(
	'Cost to trade (M-36)',
	\sprintf(
		'20 levels a cent apart, sizes 1..20; the whole ask side is worth %s, the bid side %s',
		Synthetic::fmt($capacityBuy, 0),
		Synthetic::fmt($capacitySell, 0),
	),
);

echo "   notional   buyBps  sellBps  roundTrip  buyFill  sellFill\n";
echo "  " . \str_repeat('-', 58) . "\n";
foreach ([500.0, 1000.0, 2500.0, 5000.0, 10000.0, 15000.0, 20000.0, 21000.0, 25000.0, 40000.0] as $notional) {
	$metric = new CostToTrade($notional);
	$metric->updateBook($book);
	$v = $metric->values();
	echo \sprintf(
		"  %9s %8s %8s %10s %8s %9s\n",
		Synthetic::fmt($notional, 0),
		$pad($v['buyBps'] ?? \NAN, 2, 7),
		$pad($v['sellBps'] ?? \NAN, 2, 7),
		$pad($v['roundTripBps'] ?? \NAN, 2, 9),
		$pad($v['buyFilled'] ?? \NAN, 3, 7),
		$pad($v['sellFilled'] ?? \NAN, 3, 8),
	);
}

echo "\n  A dash is NAN: the order could not be filled from this book at all, and the fill\n";
echo "  columns say how much of it would have gone through. The sell side runs out\n";
echo "  first — the bids are cheaper, so the same notional needs more size — and the\n";
echo "  metric reports that asymmetry instead of averaging it away. At 25,000 the book\n";
echo "  fills 84 per cent of the order and quotes no price for it, which is the honest\n";
echo "  answer; a cost extrapolated past the last level is a number nobody can trade on.\n";

Synthetic::heading('Is the signal bigger than the cost?', 'a strategy with an 8 bp edge, sized against this book');

$edgeBps = 8.0;
echo "   notional  roundTrip   edge   net (bp)   verdict\n";
echo "  " . \str_repeat('-', 53) . "\n";
foreach ([500.0, 1000.0, 2000.0, 2500.0, 5000.0, 10000.0, 25000.0] as $notional) {
	$metric = new CostToTrade($notional);
	$metric->updateBook($book);
	$cost = $metric->value();
	$net = $edgeBps - $cost;
	echo \sprintf(
		"  %9s %10s %6s %10s   %s\n",
		Synthetic::fmt($notional, 0),
		$pad($cost, 2, 9),
		Synthetic::fmt($edgeBps, 1),
		$pad($net, 2, 9),
		\is_nan($cost) ? 'cannot fill' : ($net > 0.0 ? 'tradable' : 'gives the edge away'),
	);
}

echo "\n";
echo "The round trip is the floor under every strategy that crosses the spread, and it\n";
echo "grows with size while the edge does not. On this book an 8 bp signal is tradable\n";
echo "up to about fifteen hundred dollars and is gone by two thousand, so the sizing\n";
echo "decision is made here rather than in the backtest. A backtest that fills at the\n";
echo "mid — or, in the last rows, fills at all — is reporting profit the book cannot\n";
echo "provide.\n";
