<?php declare(strict_types=1);

/**
 * Moving-average spread (M-12): the band-pass view of the trend and its
 * dimensionless form, then a measurement of what the reference's dSMA finite
 * difference actually reads — 30 of the 120 observations its window names.
 *
 *   php examples/trend-ma-spread.php
 */

use OpenCCK\Kalman\Domain\Metric\Trend\MaSpread;
use OpenCCK\Kalman\Domain\Metric\Trend\MovingAverage;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/trend-ma-spread.php\n";
	exit(0);
}

const FAST = 120;     // the reference's 2-hour window, one observation a minute
const SLOW = 2880;    // and its 2-day window
const DIFF_LAG = 15;  // the step its dSMA / ddSMA finite differences use

$prices = Synthetic::prices(4200, 100.0, 0.2, 0.0, 7);
$spread = MaSpread::compute($prices, FAST, SLOW);
$relative = MaSpread::relative($prices, FAST, SLOW);

Synthetic::heading(
	'MaSpread(120, 2880): SMA_2h - SMA_2d, and the same divided by SMA_2d',
	'Positive means the two-hour horizon is leading the two-day one.',
);

\printf("%6s  %10s  %10s  %12s\n", 'i', 'price', 'spread', 'relative');
for ($i = 2880; $i < 4200; $i += 110) {
	\printf(
		"%6d  %10s  %10s  %12s\n",
		$i,
		Synthetic::fmt($prices[$i], 3),
		Synthetic::fmt($spread[$i], 4),
		Synthetic::fmt($relative[$i], 6),
	);
}

// --------------------------------------------- what the finite difference reads

$fastMa = MovingAverage::sma($prices, FAST);
$n = \count($prices);

$identityError = 0.0;
$derivative = [];
$spreadValues = [];
for ($i = 0; $i < $n; $i++) {
	if ($i < SLOW + DIFF_LAG || \is_nan($spread[$i]) || \is_nan($spread[$i - DIFF_LAG])) {
		continue;
	}
	$derivative[] = ($spread[$i] - $spread[$i - DIFF_LAG]) / DIFF_LAG;
	$spreadValues[] = $spread[$i];

	// The fast leg of that difference, written as it really is: the mean of the
	// newest 15 observations minus the mean of the 15 that dropped out.
	$droppedFrom = $i - FAST - DIFF_LAG + 1;
	$newestFrom = $droppedFrom + FAST;
	$newestSum = 0.0;
	$droppedSum = 0.0;
	for ($k = 0; $k < DIFF_LAG; $k++) {
		$newestSum += $prices[$newestFrom + $k];
		$droppedSum += $prices[$droppedFrom + $k];
	}
	$edges = ($newestSum - $droppedSum) / FAST;
	$error = \abs(($fastMa[$i] - $fastMa[$i - DIFF_LAG]) - $edges);
	$identityError = $error > $identityError ? $error : $identityError;
}

/**
 * @param list<float> $values
 */
function dispersion(array $values): float
{
	$count = \count($values);
	if ($count < 2) {
		return \NAN;
	}
	$mean = \array_sum($values) / $count;
	$sum = 0.0;
	foreach ($values as $value) {
		$d = $value - $mean;
		$sum += $d * $d;
	}
	return \sqrt($sum / ($count - 1));
}

/**
 * @param list<float> $a
 * @param list<float> $b
 */
function correlation(array $a, array $b): float
{
	$count = \count($a);
	$meanA = \array_sum($a) / $count;
	$meanB = \array_sum($b) / $count;
	$cov = 0.0;
	$varA = 0.0;
	$varB = 0.0;
	for ($i = 0; $i < $count; $i++) {
		$da = $a[$i] - $meanA;
		$db = $b[$i] - $meanB;
		$cov += $da * $db;
		$varA += $da * $da;
		$varB += $db * $db;
	}
	return $cov / \sqrt($varA * $varB);
}

$now = [];
$before = [];
for ($i = SLOW + DIFF_LAG; $i < $n; $i++) {
	$now[] = $fastMa[$i];
	$before[] = $fastMa[$i - DIFF_LAG];
}

Synthetic::heading('The reference dSMA: a 2-hour window differenced 15 minutes apart');

\printf("observations compared                       %d\n", \count($derivative));
\printf("sd of the spread itself                     %s\n", Synthetic::fmt(dispersion($spreadValues), 6));
\printf("sd of dSMA = (spread_t - spread_t-15) / 15  %s\n", Synthetic::fmt(dispersion($derivative), 6));
\printf("shared fraction of the two 120-windows      %s\n", Synthetic::fmt((FAST - DIFF_LAG) / FAST, 4));
\printf("measured correlation of SMA_t and SMA_t-15  %s\n", Synthetic::fmt(correlation($now, $before), 6));
\printf("max error of the 30-observation identity    %.2e\n", $identityError);

echo "\n",
	"What to look at: the last line. SMA_120(t) - SMA_120(t-15) is identically\n",
	"(1/120) times [mean of the newest 15 prices minus mean of the 15 that just\n",
	"left the window] — the identity holds to under 2e-14, which is rounding. The\n",
	"105 observations the two windows share, 87.5 % of the data, contribute\n",
	"exactly nothing: they cancel term by term. The reference's \"2-hour\n",
	"derivative\" is a difference of two 15-minute means 2 hours apart, divided by\n",
	"120. It does not have a 2-hour window's noise rejection, only its name.\n\n",
	"The two sd lines show the consequence. The spread itself has a spread of\n",
	"2.45 price units; its finite difference has 0.0089, some 275 times smaller.\n",
	"That is why the reference formulas carry x100 and x1000 scale factors — they\n",
	"are rescaling something that fell apart in the subtraction. The measured\n",
	"correlation between the two differenced terms is 0.9983, well beyond the\n",
	"0.875 the shared window alone implies, because the input is a random walk:\n",
	"almost everything cancels and what survives is mostly the window edges.\n\n",
	"Filtered\\Derivative gives the same derivative from the filter, with an\n",
	"uncertainty attached instead of a scale factor.\n";
