<?php declare(strict_types=1);

/**
 * Price delta (M-03) and mean-price difference (M-04): why the log return is
 * the default, and what the reference NdT configuration pays for its
 * overlapping windows.
 *
 *   php examples/price-delta.php
 */

use OpenCCK\Kalman\Domain\Metric\Price\MeanPriceDifference;
use OpenCCK\Kalman\Domain\Metric\Price\PriceDelta;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/price-delta.php\n";
	exit(0);
}

const HOUR = 60;          // one observation per minute
const TWO_HOURS = 120;

// A volatile series, so the second-order term is large enough to see.
$prices = Synthetic::prices(600, 100.0, 1.5, 0.05, 7);

Synthetic::heading(
	'PriceDelta — one-hour returns, log and simple',
	'One observation per minute, so a lag of 60 is an hour.',
);

$logReturns = PriceDelta::log($prices, HOUR);
$simpleReturns = PriceDelta::simple($prices, HOUR);

\printf("%6s  %9s  %11s  %11s  %11s\n", 'i', 'price', 'log %', 'simple %', 'diff bp');
for ($i = 120; $i < 600; $i += 55) {
	\printf(
		"%6d  %9s  %11s  %11s  %11s\n",
		$i,
		Synthetic::fmt($prices[$i], 3),
		Synthetic::fmt(100.0 * $logReturns[$i], 4),
		Synthetic::fmt(100.0 * $simpleReturns[$i], 4),
		Synthetic::fmt(10_000.0 * ($simpleReturns[$i] - $logReturns[$i]), 2),
	);
}

Synthetic::heading(
	'Additivity: hour 1 + hour 2 against the two-hour return',
	'Log returns add exactly. Simple returns miss the cross term -R1*R2.',
);

\printf("%6s  %11s  %11s  %11s  %11s\n", 'i', 'log error', 'simple err', '-R1*R2', 'match');
for ($i = 200; $i < 600; $i += 65) {
	$p0 = $prices[$i - TWO_HOURS];
	$p1 = $prices[$i - HOUR];
	$p2 = $prices[$i];

	$logFirst = \log($p1 / $p0);
	$logSecond = \log($p2 / $p1);
	$logBoth = \log($p2 / $p0);

	$simpleFirst = $p1 / $p0 - 1.0;
	$simpleSecond = $p2 / $p1 - 1.0;
	$simpleBoth = $p2 / $p0 - 1.0;

	$logError = $logFirst + $logSecond - $logBoth;
	$simpleError = $simpleFirst + $simpleSecond - $simpleBoth;
	$crossTerm = -$simpleFirst * $simpleSecond;

	\printf(
		"%6d  %11.2e  %11.2e  %11.2e  %11s\n",
		$i,
		$logError,
		$simpleError,
		$crossTerm,
		\abs($simpleError - $crossTerm) < 1e-15 ? 'exact' : 'no',
	);
}

Synthetic::heading(
	'MeanPriceDifference — how much of the two smoothed terms is shared',
	'overlapFor(smoothWindow, lag): 0 means disjoint windows, 1 means identical.',
);

\printf("%14s  %6s  %10s  %s\n", 'smoothWindow', 'lag', 'overlap', 'configuration');
foreach ([[30, 15, 'the reference NdT (30 min / 15 min)'], [30, 30, 'disjoint — no cancellation'], [120, 15, 'the reference dSMA (2 h / 15 min)'], [120, 120, 'disjoint over the same 2 h']] as $row) {
	\printf("%14d  %6d  %10s  %s\n", $row[0], $row[1], Synthetic::fmt(MeanPriceDifference::overlapFor($row[0], $row[1]), 4), $row[2]);
}

$ndt = MeanPriceDifference::compute($prices, 30, 15, 120);
$direct = PriceDelta::log($prices, 15);
$pairs = 0;
$ndtSum = 0.0;
$directSum = 0.0;
for ($i = 0; $i < 600; $i++) {
	if (!\is_nan($ndt[$i]) && !\is_nan($direct[$i])) {
		$pairs++;
		$ndtSum += $ndt[$i] * $ndt[$i];
		$directSum += $direct[$i] * $direct[$i];
	}
}
\printf(
	"\nrms over %d observations: NdT(30/15) = %s, plain 15-step log return = %s\n",
	$pairs,
	Synthetic::fmt(\sqrt($ndtSum / $pairs), 6),
	Synthetic::fmt(\sqrt($directSum / $pairs), 6),
);

echo "\n",
	"What to look at. In the second table the log error is zero to machine\n",
	"precision on every row — ln(P2/P1) + ln(P1/P0) is ln(P2/P0) by algebra — so\n",
	"log returns can be summed across horizons, averaged over a basket or fed to a\n",
	"regression without correction. The simple-return error is not zero, and it is\n",
	"exactly -R1*R2 (the 'exact' column): a second-order term that grows with\n",
	"volatility, which is precisely when the signal matters.\n\n",
	"The third table is the reference NdT's own configuration. A 30-minute average\n",
	"differenced against itself 15 minutes ago shares 50 % of its data with\n",
	"itself; the shared half cancels in the numerator while the independent noise\n",
	"does not, and the surviving signal spans 15 minutes, not the 30 the window\n",
	"name suggests. The reference dSMA is worse still at 0.875.\n";
