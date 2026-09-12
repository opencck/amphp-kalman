<?php declare(strict_types=1);

/**
 * Kyle's lambda and Amihud, recovered from a tape with a known impact (M-31).
 *
 *   php examples/micro-price-impact.php
 *
 * `Domain\Metric\Microstructure\PriceImpact` regresses the price change on
 * signed order flow through the origin — no intercept, because zero net flow
 * must imply zero expected price change. Here the tape is generated with a
 * lambda chosen in advance, so the estimator can be checked rather than
 * believed. 1/lambda is the market's depth, and lambda times size is a cost
 * paid before any signal pays off, which is what caps order size.
 */

use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Metric\Microstructure\PriceImpact;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/**
 * Right-aligns a formatted number by display width. `sprintf('%12s', ...)` pads
 * by *bytes*, and the em-dash `Synthetic::fmt()` prints for NAN is three of
 * them, so a warm-up cell would sit two characters left of every other row.
 */
function impactCell(float $value, int $decimals, int $width): string
{
	$text = \ltrim(Synthetic::fmt($value, $decimals));
	$fill = $width - \mb_strlen($text);
	return ($fill > 0 ? \str_repeat(' ', $fill) : '') . $text;
}

$count = 4000;
$trueLambda = 0.0040;       // price units per unit of signed size
$start = 100.0;
$tape = Synthetic::trades($count, 100.0, 0.5);
$noiseWalk = Synthetic::prices($count + 1, 0.0, 0.012);

/** @var list<float> $prices */
$prices = [];
/** @var list<float> $signedSizes */
$signedSizes = [];
$price = $start;
foreach ($tape as $i => $trade) {
	$signed = $trade->size * ($trade->side === TradeSide::Buy ? 1.0 : -1.0);
	$price += $trueLambda * $signed + ($noiseWalk[$i + 1] - $noiseWalk[$i]);
	$prices[] = $price;
	$signedSizes[] = $signed;
}

Synthetic::heading(
	'Recovering a known lambda (M-31)',
	\sprintf(
		'%d trades, price change = %s x signed size + noise (sd %s per trade)',
		$count,
		Synthetic::fmt($trueLambda, 4),
		Synthetic::fmt(0.012 / \sqrt(3.0), 4),
	),
);

echo "   window   lambda_hat      error(%)   depth 1/lambda   true depth\n";
echo "  " . \str_repeat('-', 68) . "\n";
foreach ([100, 250, 500, 1000, 3999] as $window) {
	$series = PriceImpact::compute($prices, $signedSizes, $window);
	$lambda = $series['lambda'][$count - 1];
	echo \sprintf(
		"  %7d %12s %13s %16s %12s\n",
		$window,
		Synthetic::fmt($lambda, 5),
		Synthetic::fmt(($lambda - $trueLambda) / $trueLambda * 100.0, 2),
		Synthetic::fmt($series['depth'][$count - 1], 1),
		Synthetic::fmt(1.0 / $trueLambda, 1),
	);
}
echo "\n  The window is the usual bias-variance trade: a hundred trades follow a changing\n";
echo "  market but scatter badly, four thousand pin the number down and would miss a\n";
echo "  regime change entirely. That trade-off is exactly what the library's filtered\n";
echo "  form removes — see the docblock's note on treating lambda as a random-walk\n";
echo "  state with a variance attached, the same problem `PairsHedge` solves.\n";

Synthetic::heading('Lambda and Amihud through the tape', 'rolling window of 500 trades');

$rolling = PriceImpact::compute($prices, $signedSizes, 500);
echo "   trade   lambda_hat       depth        Amihud\n";
echo "  " . \str_repeat('-', 48) . "\n";
for ($i = 499; $i < $count; $i += 500) {
	echo \sprintf(
		"  %6d %s %s %13s\n",
		$i,
		impactCell($rolling['lambda'][$i], 5, 12),
		impactCell($rolling['depth'][$i], 1, 11),
		Synthetic::fmt($rolling['amihud'][$i] * 1e6, 4),
	);
}
echo "\n  Amihud is shown times 1e6: it is a mean absolute return per unit of notional, so\n";
echo "  on a $100 instrument the raw figure is around 1e-6 and unreadable as printed.\n";
echo "  It carries no regression and no sign, and it tracks lambda closely enough to\n";
echo "  have become the standard illiquidity proxy where tick data is unavailable.\n";

Synthetic::heading('What it means for order size', 'impact cost of a parent order, against a 5 basis point edge');

$edgeBps = 5.0;
$lambda = $rolling['lambda'][$count - 1];
echo "     size   impact(price)   impact(bp)   edge(bp)   net(bp)   verdict\n";
echo "  " . \str_repeat('-', 70) . "\n";
foreach ([1.0, 5.0, 10.0, 12.5, 25.0, 50.0] as $size) {
	// Permanent impact of walking a parent order of this size through the book.
	$impact = $lambda * $size;
	$impactBps = $impact / $start * 10000.0;
	$net = $edgeBps - $impactBps;
	echo \sprintf(
		"  %7s %15s %12s %10s %9s   %s\n",
		Synthetic::fmt($size, 1),
		Synthetic::fmt($impact, 4),
		Synthetic::fmt($impactBps, 3),
		Synthetic::fmt($edgeBps, 1),
		Synthetic::fmt($net, 3),
		$net > 0.0 ? 'tradable' : 'the impact eats the edge',
	);
}

$maxSize = $edgeBps / 10000.0 * $start / $lambda;
echo "\n";
echo \sprintf(
	"A 5 bp edge on a $100 instrument is worth %s in price, so it supports a parent\norder of about %s units before the impact of trading it consumes the whole\n",
	Synthetic::fmt($edgeBps / 10000.0 * $start, 4),
	Synthetic::fmt($maxSize, 1),
);
echo "reason for trading. That is the consequence: lambda is not a diagnostic printed\n";
echo "after the fact, it is the number that sizes the order before it is sent. Note\n";
echo "too that the estimate is made against the *tape*, not against a book snapshot,\n";
echo "so it captures the impact a book walk cannot see — the quotes that are pulled\n";
echo "when the flow arrives.\n";
