<?php declare(strict_types=1);

/**
 * CCI (M-08): Lambert's index with the mean absolute deviation in the
 * denominator, against the usual shortcut of putting the standard deviation
 * there. The shortcut shrinks every reading by about a fifth, which is enough
 * to break the +-100 calibration.
 *
 *   php examples/momentum-cci.php
 */

use OpenCCK\Kalman\Domain\Metric\Momentum\Cci;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/momentum-cci.php\n";
	exit(0);
}

const PERIOD = 20;

$bars = Synthetic::bars(400, 100.0, 0.01, 0.0, 13, 40);
$highs = [];
$lows = [];
$closes = [];
foreach ($bars as $bar) {
	$highs[] = $bar->high;
	$lows[] = $bar->low;
	$closes[] = $bar->close;
}

$typical = Cci::typicalPrices($highs, $lows, $closes);
$cci = Cci::compute($highs, $lows, $closes, PERIOD);

// The shortcut, written out here rather than taken from the library: the same
// formula with the population standard deviation where the MAD belongs.
$shortcut = [];
$n = \count($typical);
for ($i = 0; $i < $n; $i++) {
	if ($i < PERIOD - 1) {
		$shortcut[] = \NAN;
		continue;
	}
	$window = \array_slice($typical, $i - PERIOD + 1, PERIOD);
	$mean = \array_sum($window) / PERIOD;
	$squares = 0.0;
	foreach ($window as $value) {
		$d = $value - $mean;
		$squares += $d * $d;
	}
	$sigma = \sqrt($squares / PERIOD);
	$shortcut[] = $sigma > 0.0 ? ($typical[$i] - $mean) / (Cci::LAMBERT_CONSTANT * $sigma) : 0.0;
}

Synthetic::heading(
	'CCI(20): mean absolute deviation against the standard-deviation shortcut',
	'Same typical price, same 0.015 constant, different measure of dispersion.',
);

\printf("%6s  %10s  %11s  %11s  %9s\n", 'bar', 'typical', 'CCI (MAD)', 'CCI (sd)', 'ratio');
for ($i = 40; $i < 400; $i += 30) {
	\printf(
		"%6d  %10s  %11s  %11s  %9s\n",
		$i,
		Synthetic::fmt($typical[$i], 4),
		Synthetic::fmt($cci[$i], 2),
		Synthetic::fmt($shortcut[$i], 2),
		Synthetic::fmt($cci[$i] / $shortcut[$i], 4),
	);
}

$ratioSum = 0.0;
$ratioCount = 0;
$madBeyond = 0;
$sdBeyond = 0;
$compared = 0;
for ($i = 0; $i < $n; $i++) {
	if (\is_nan($cci[$i]) || \is_nan($shortcut[$i])) {
		continue;
	}
	$compared++;
	if (\abs($shortcut[$i]) > 1e-9) {
		$ratioSum += $cci[$i] / $shortcut[$i];
		$ratioCount++;
	}
	if (\abs($cci[$i]) > 100.0) {
		$madBeyond++;
	}
	if (\abs($shortcut[$i]) > 100.0) {
		$sdBeyond++;
	}
}

\printf("\nmean CCI(MAD) / CCI(sd) over %d bars: %s\n", $ratioCount, Synthetic::fmt($ratioSum / $ratioCount, 4));
\printf("the Gaussian value of sigma / MAD:            %s\n", Synthetic::fmt(1.0 / \sqrt(2.0 / \M_PI), 4));
\printf(
	"bars beyond +-100: MAD form %d of %d (%s %%), sd form %d of %d (%s %%)\n",
	$madBeyond,
	$compared,
	Synthetic::fmt(100.0 * $madBeyond / $compared, 1),
	$sdBeyond,
	$compared,
	Synthetic::fmt(100.0 * $sdBeyond / $compared, 1),
);

echo "\n",
	"What to look at: the ratio column. Every row sits in a narrow band around\n",
	"1.20, averaging 1.2032, and it has no reason to move far: for a roughly\n",
	"normal window the mean absolute deviation is sqrt(2/pi) = 0.7979 of the\n",
	"standard deviation, so putting sigma in the denominator divides by a\n",
	"quantity 1/0.7979 = 1.2533 times larger. The measured figure falls a little\n",
	"short of the Gaussian one because a synthetic bar's typical price is built\n",
	"from a finite number of intrabar steps — but it is a systematic bias, not\n",
	"noise, and no downstream rescaling undoes it, because MAD and sigma drift\n",
	"apart again as soon as the window's tails change.\n\n",
	"The consequence is the last line. Lambert picked 0.015 so that roughly\n",
	"70-80 % of readings fall inside +-100; that is the entire meaning of the\n",
	"threshold. Shrinking every reading by a fifth pulls a large slice of the\n",
	"distribution back inside the band — here 41.5 % of bars beyond +-100 becomes\n",
	"29.1 % — so the shortcut signals much less often than the indicator it\n",
	"claims to be. (A pure random walk overshoots Lambert's 20-30 % to begin\n",
	"with; it is the shift between the two columns that is the point.)\n";
