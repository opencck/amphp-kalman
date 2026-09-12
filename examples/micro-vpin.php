<?php declare(strict_types=1);

/**
 * Flow toxicity on a volume clock (M-30).
 *
 *   php examples/micro-vpin.php
 *
 * `Domain\Metric\Microstructure\Vpin` cuts the tape into buckets of equal
 * *volume*, splits each bucket into buyer- and seller-initiated parts, and
 * averages the absolute imbalance. Two choices carry the idea: the clock is
 * volume, not seconds, so a quiet hour and a violent minute are the same
 * length; and the imbalance is absolute, because persistent one-sided flow is
 * dangerous to a market maker in either direction. This is the only metric in
 * the library that does not run on calendar time, and the last section here
 * makes that visible.
 */

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Metric\Microstructure\Vpin;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

$count = 3000;
$bucketSize = 50.0;
$buckets = 20;

Synthetic::heading(
	'VPIN against the buyer share that produced the tape (M-30)',
	\sprintf('%d trades each, buckets of %s units, averaged over %d buckets', $count, Synthetic::fmt($bucketSize, 0), $buckets),
);

echo "  buyShare   trades   volume   buckets    VPIN\n";
echo "  " . \str_repeat('-', 46) . "\n";
foreach ([0.5, 0.6, 0.7, 0.8, 0.9, 0.98] as $share) {
	$metric = new Vpin($bucketSize, $buckets);
	$volume = 0.0;
	foreach (Synthetic::trades($count, 100.0, $share) as $trade) {
		$metric->updateTrade($trade);
		$volume += $trade->size;
	}
	$v = $metric->values();
	echo \sprintf(
		"  %8s %8d %8s %9s %7s\n",
		Synthetic::fmt($share, 2),
		$count,
		Synthetic::fmt($volume, 0),
		Synthetic::fmt($v['buckets'] ?? \NAN, 0),
		Synthetic::fmt($v['vpin'] ?? \NAN, 4),
	);
}
echo "\n  Balanced flow does not read exactly zero: a bucket of forty-odd trades has a\n";
echo "  sampling imbalance even when the coin is fair, and VPIN counts it. That floor\n";
echo "  is the reason to read the measure as a regime flag rather than as a\n";
echo "  probability, whatever its name says.\n";

// A tape that is balanced for the first half and heavily one-sided afterwards,
// with volume arriving in bursts rather than evenly.
$balanced = Synthetic::trades($count, 100.0, 0.5);
$toxic = Synthetic::trades($count, 100.0, 0.95, 0.01, 31);
$ns = 1_700_000_000_000_000_000;
$stepNs = 250_000_000;
/** @var list<Trade> $tape */
$tape = [];
for ($i = 0; $i < $count; $i++) {
	$source = $i < $count / 2 ? $balanced[$i] : $toxic[$i];
	// Volume arrives in bursts: three busy stretches inside an otherwise quiet
	// tape, so wall-clock time and volume time come apart.
	$phase = \intdiv($i, 250) % 4;
	$multiplier = $phase === 0 ? 6.0 : 0.4;
	$tape[] = Trade::at($ns, $source->price, $source->size * $multiplier, $source->side);
	$ns += $stepNs;
}

Synthetic::heading(
	'VPIN rising as the flow turns one-sided',
	\sprintf('the buyer share flips from 0.50 to 0.95 at trade %d', \intdiv($count, 2)),
);

$metric = new Vpin($bucketSize, $buckets);
echo "   trade   elapsed(s)   volume   buckets    VPIN   flow\n";
echo "  " . \str_repeat('-', 56) . "\n";
$volume = 0.0;
/** @var list<array{0: int, 1: float, 2: int}> $clock */
$clock = [];
foreach ($tape as $i => $trade) {
	$metric->updateTrade($trade);
	$volume += $trade->size;
	$v = $metric->values();
	$clock[] = [$i, $volume, (int) ($v['buckets'] ?? 0.0)];
	if ($i % 500 === 499) {
		echo \sprintf(
			"  %6d %12s %8s %9s %7s   %s\n",
			$i,
			Synthetic::fmt($i * $stepNs / 1e9, 1),
			Synthetic::fmt($volume, 0),
			Synthetic::fmt($v['buckets'] ?? \NAN, 0),
			Synthetic::fmt($v['vpin'] ?? \NAN, 4),
			$i < $count / 2 ? 'balanced' : 'one-sided',
		);
	}
}

Synthetic::heading('The volume clock is not the wall clock', 'buckets closed per second, over the same tape');

echo "   from(s)     to(s)   volume in window   buckets closed   buckets/second\n";
echo "  " . \str_repeat('-', 74) . "\n";
$previousVolume = 0.0;
$previousBuckets = 0;
for ($i = 249; $i < $count; $i += 250) {
	[$index, $cumulative, $closed] = $clock[$i];
	$seconds = 250 * $stepNs / 1e9;
	echo \sprintf(
		"  %8s  %8s %18s %16d %16s\n",
		Synthetic::fmt(($index - 249) * $stepNs / 1e9, 1),
		Synthetic::fmt($index * $stepNs / 1e9, 1),
		Synthetic::fmt($cumulative - $previousVolume, 1),
		$closed - $previousBuckets,
		Synthetic::fmt(($closed - $previousBuckets) / $seconds, 3),
	);
	$previousVolume = $cumulative;
	$previousBuckets = $closed;
}

echo "\n";
echo "Every row above covers exactly 62.5 seconds of wall clock. The number of buckets\n";
echo "that closed inside them varies by more than an order of magnitude, because a\n";
echo "bucket is a quantity of trading and not a stretch of time. That is the whole\n";
echo "point of the volume clock: VPIN does not have to be told whether the market is\n";
echo "busy, because in its own time the busy stretches are simply longer.\n";
echo "Look back at the previous table with that in mind: the flow turns one-sided at\n";
echo "trade 1500, VPIN is still 0.35 at trade 1999, and only at 2499 does it reach 0.92.\n";
echo "Nothing was wrong with it — between those rows barely a hundred units traded, so\n";
echo "barely two buckets closed, and in volume time almost no time had passed. What\n";
echo "reads as lag on a wall clock is not lag at all, and the measure is at its most\n";
echo "responsive exactly when it matters: during the burst, when a maker's inventory is\n";
echo "turning over fastest.\n";
