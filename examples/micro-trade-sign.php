<?php declare(strict_types=1);

/**
 * Who was the aggressor: three inference rules against a known truth (M-32).
 *
 *   php examples/micro-trade-sign.php
 *
 * Every flow measure in `Domain\Metric\Microstructure` — OFI, VPIN, Kyle's
 * lambda, volume delta — is a function of trade direction, and most public
 * tapes do not publish it. `TradeClassifier` infers it by a stated algorithm.
 * Here the tape is built with the sides known, so each algorithm can be scored
 * rather than trusted: Lee-Ready with quotes, the tick rule without them, and
 * bulk volume classification, which never looks at an individual print.
 */

use OpenCCK\Kalman\Domain\Metric\Microstructure\TradeClassifier;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

$count = 2000;
$halfSpread = 0.008;
$lambda = 0.004;            // the efficient price actually responds to flow
$tape = Synthetic::trades($count, 100.0, 0.5);
$noiseWalk = Synthetic::prices($count + 1, 0.0, 0.02);
// Real order flow arrives in runs, not as a coin flip: the sign of a slow
// random walk gives persistent buying and selling pressure.
$pressure = Synthetic::prices($count, 0.0, 1.0, 0.0, 23);

/** @var list<float> $truth +1 buyer-initiated, -1 seller-initiated */
$truth = [];
/** @var list<float> $prices the print: a buy lands on the ask, a sell on the bid */
$prices = [];
/** @var list<float> $mids the quote standing when the trade printed */
$mids = [];
/** @var list<float> $sizes */
$sizes = [];
$efficient = 100.0;
foreach ($tape as $i => $trade) {
	$sign = $pressure[$i] >= 0.0 ? 1.0 : -1.0;
	// Permanent impact plus public-information noise, so a bucket that bought
	// more really does close higher — the assumption bulk classification makes.
	$efficient += $lambda * $sign * $trade->size + ($noiseWalk[$i + 1] - $noiseWalk[$i]);
	$truth[] = $sign;
	$mids[] = $efficient;
	$prices[] = $efficient + $sign * $halfSpread;
	$sizes[] = $trade->size;
}

/**
 * Share of signs that match the known truth.
 *
 * @param list<float> $estimate
 * @param list<float> $actual
 */
function signAccuracy(array $estimate, array $actual): float
{
	$n = \count($actual);
	if ($n === 0) {
		return \NAN;
	}
	$hits = 0;
	foreach ($estimate as $i => $sign) {
		if (isset($actual[$i]) && $sign === $actual[$i]) {
			$hits++;
		}
	}
	return $hits / $n;
}

$leeReady = TradeClassifier::leeReady($prices, $mids);
$tick = TradeClassifier::tickRule($prices);
// Without quotes Lee-Ready has nothing to decide on and becomes the tick rule.
$blind = TradeClassifier::leeReady($prices, \array_fill(0, $count, \NAN));

Synthetic::heading(
	'Classification accuracy against a known tape (M-32)',
	\sprintf(
		'%d trades, half-spread %s, efficient price = %s x signed size + noise',
		$count,
		Synthetic::fmt($halfSpread, 3),
		Synthetic::fmt($lambda, 3),
	),
);

echo "  rule                              accuracy   agrees with Lee-Ready\n";
echo "  " . \str_repeat('-', 64) . "\n";
echo \sprintf("  Lee-Ready (quotes available)      %8s   %21s\n", Synthetic::fmt(signAccuracy($leeReady, $truth), 4), '-');
echo \sprintf("  tick rule (no quotes)             %8s   %21s\n", Synthetic::fmt(signAccuracy($tick, $truth), 4), Synthetic::fmt(signAccuracy($tick, $leeReady), 4));
echo \sprintf("  Lee-Ready with every mid unknown  %8s   %21s\n", Synthetic::fmt(signAccuracy($blind, $truth), 4), Synthetic::fmt(signAccuracy($blind, $leeReady), 4));

// Bulk volume classification: buckets of 20 trades, scored on volume rather
// than on prints, because it never claims a side for an individual print.
$bucket = 20;
$closes = [];
$trueBuyShare = [];
for ($start = 0; $start + $bucket <= $count; $start += $bucket) {
	$buy = 0.0;
	$total = 0.0;
	for ($i = $start; $i < $start + $bucket; $i++) {
		$total += $sizes[$i];
		if ($truth[$i] > 0.0) {
			$buy += $sizes[$i];
		}
	}
	$closes[] = $prices[$start + $bucket - 1];
	$trueBuyShare[] = $total > 0.0 ? $buy / $total : \NAN;
}
$bvc = TradeClassifier::bulkVolume($closes, 50);

$scored = 0;
$volumeAccuracy = 0.0;
$absoluteError = 0.0;
foreach ($bvc as $k => $fraction) {
	if (\is_nan($fraction) || \is_nan($trueBuyShare[$k])) {
		continue;
	}
	$scored++;
	// Volume classified correctly if the assignment were made at random with
	// probability `fraction`: f·buyShare + (1−f)·sellShare.
	$volumeAccuracy += $fraction * $trueBuyShare[$k] + (1.0 - $fraction) * (1.0 - $trueBuyShare[$k]);
	$absoluteError += \abs($fraction - $trueBuyShare[$k]);
}
echo \sprintf(
	"  bulk volume (%d buckets of %d)     %8s   volume-weighted, mean |error| %s\n",
	$scored,
	$bucket,
	Synthetic::fmt($scored > 0 ? $volumeAccuracy / $scored : \NAN, 4),
	Synthetic::fmt($scored > 0 ? $absoluteError / $scored : \NAN, 4),
);

Synthetic::heading('A print exactly at the mid: the tick rule breaks the tie', 'ten trades forced onto the mid inside an otherwise normal tape');

$tieAt = \range(500, 509);
/** @var list<float> $tiePrices */
$tiePrices = [];
foreach ($prices as $i => $price) {
	$tiePrices[] = \in_array($i, $tieAt, true) ? $mids[$i] : $price;
}
$tieLeeReady = TradeClassifier::leeReady($tiePrices, $mids);
$tieTick = TradeClassifier::tickRule($tiePrices);

echo "  trade      price        mid   price-mid   Lee-Ready   tick rule   true\n";
echo "  " . \str_repeat('-', 70) . "\n";
foreach (\range(498, 511) as $i) {
	echo \sprintf(
		"  %5d  %9s  %9s  %10s  %10s  %10s  %5s%s\n",
		$i,
		Synthetic::fmt($tiePrices[$i], 4),
		Synthetic::fmt($mids[$i], 4),
		Synthetic::fmt($tiePrices[$i] - $mids[$i], 4),
		Synthetic::fmt($tieLeeReady[$i], 1),
		Synthetic::fmt($tieTick[$i], 1),
		Synthetic::fmt($truth[$i], 1),
		\in_array($i, $tieAt, true) ? '  <- tie' : '',
	);
}

echo "\n";
echo "Lee-Ready is exact here by construction — every print lands on a quote, which is\n";
echo "what a quote-based rule is for. Strip the quotes away and it collapses to exactly\n";
echo "the tick rule's 0.6150, digit for digit: that is the documented fallback working,\n";
echo "not a failure, and it is also a warning, because 0.61 is not far above a coin\n";
echo "flip. The efficient price here moves more between prints than the spread is wide,\n";
echo "so the last price change says little about who crossed.\n";
echo "Bulk volume classification reaches 0.85 without ever labelling a single print. It\n";
echo "gives up the per-trade question and answers the aggregate one, which is the only\n";
echo "one the flow measures actually ask — and it is the rule to reach for when a\n";
echo "matching engine chops one parent order into hundreds of prints, precisely where\n";
echo "tick-level rules fall apart.\n";
echo "On the tied rows Lee-Ready and the tick rule print the same sign: the quote test\n";
echo "is indecisive at the mid and hands the decision over, and inherits its errors.\n";
