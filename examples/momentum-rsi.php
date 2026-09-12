<?php declare(strict_types=1);

/**
 * RSI (M-05): Wilder's index against the reference PromQL variant, which
 * differences against an observation 30 steps back and smooths with a flat
 * rolling average instead of Wilder's RMA. The two are not the same indicator
 * and the 70/30 thresholds do not transfer from one to the other.
 *
 *   php examples/momentum-rsi.php
 */

use OpenCCK\Kalman\Domain\Metric\Momentum\Rsi;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/momentum-rsi.php\n";
	exit(0);
}

const WINDOW = 14;
const REFERENCE_LAG = 30;

/**
 * @param list<float> $values
 * @return array{n: int, mean: float, min: float, max: float, pinned: int, hot: int}
 */
function summarise(array $values): array
{
	$n = 0;
	$sum = 0.0;
	$min = \INF;
	$max = -\INF;
	$pinned = 0;
	$hot = 0;
	foreach ($values as $v) {
		if (\is_nan($v)) {
			continue;
		}
		$n++;
		$sum += $v;
		$min = $v < $min ? $v : $min;
		$max = $v > $max ? $v : $max;
		if ($v >= 99.999 || $v <= 0.001) {
			$pinned++;
		}
		if ($v >= 70.0 || $v <= 30.0) {
			$hot++;
		}
	}
	return ['n' => $n, 'mean' => $n > 0 ? $sum / $n : \NAN, 'min' => $min, 'max' => $max, 'pinned' => $pinned, 'hot' => $hot];
}

// ------------------------------------------------ a steady, moderate uptrend

$trend = Synthetic::prices(400, 100.0, 0.2, 0.05, 7);
$wilder = Rsi::wilder($trend, WINDOW);
$reference = Rsi::lagged($trend, REFERENCE_LAG, WINDOW);

Synthetic::heading(
	'A steady uptrend: Wilder RSI(14) against the reference lagged variant',
	'Reference = differences 30 steps back, smoothed by a flat 14-sample average.',
);

\printf("%6s  %9s  %10s  %10s  %9s\n", 'i', 'price', 'Wilder', 'reference', 'gap');
for ($i = 60; $i < 400; $i += 38) {
	\printf(
		"%6d  %9s  %10s  %10s  %9s\n",
		$i,
		Synthetic::fmt($trend[$i], 3),
		Synthetic::fmt($wilder[$i], 2),
		Synthetic::fmt($reference[$i], 2),
		Synthetic::fmt($reference[$i] - $wilder[$i], 2),
	);
}

$w = summarise($wilder);
$r = summarise($reference);
\printf(
	"\n%-12s  %5s  %8s  %8s  %8s  %12s\n",
	'series',
	'n',
	'mean',
	'min',
	'max',
	'pinned 0/100',
);
\printf("%-12s  %5d  %8s  %8s  %8s  %12d\n", 'Wilder', $w['n'], Synthetic::fmt($w['mean'], 2), Synthetic::fmt($w['min'], 2), Synthetic::fmt($w['max'], 2), $w['pinned']);
\printf("%-12s  %5d  %8s  %8s  %8s  %12d\n", 'reference', $r['n'], Synthetic::fmt($r['mean'], 2), Synthetic::fmt($r['min'], 2), Synthetic::fmt($r['max'], 2), $r['pinned']);

// ------------------------------------------------------ up, chop, down again

$regimes = Synthetic::regimes(900, 100.0, 0.3, 11);
$wilderR = Rsi::wilder($regimes, WINDOW);
$referenceR = Rsi::lagged($regimes, REFERENCE_LAG, WINDOW);
$wr = summarise($wilderR);
$rr = summarise($referenceR);

Synthetic::heading(
	'Trend up, chop, trend down — where the thresholds are supposed to work',
	'"overbought/oversold" counts bars at or beyond 70 / 30.',
);

\printf("%6s  %9s  %10s  %10s\n", 'i', 'price', 'Wilder', 'reference');
for ($i = 60; $i < 900; $i += 95) {
	\printf(
		"%6d  %9s  %10s  %10s\n",
		$i,
		Synthetic::fmt($regimes[$i], 3),
		Synthetic::fmt($wilderR[$i], 2),
		Synthetic::fmt($referenceR[$i], 2),
	);
}
\printf(
	"\nWilder:    %d of %d bars overbought/oversold, %d pinned at 0 or 100\n",
	$wr['hot'],
	$wr['n'],
	$wr['pinned'],
);
\printf(
	"reference: %d of %d bars overbought/oversold, %d pinned at 0 or 100\n",
	$rr['hot'],
	$rr['n'],
	$rr['pinned'],
);

// ------------------------------------------------------------- the flat case

$flat = \array_fill(0, 60, 100.0);
$flatWilder = Rsi::wilder($flat, WINDOW);
$flatReference = Rsi::lagged($flat, REFERENCE_LAG, WINDOW);
\printf(
	"\nA perfectly flat series (no gains, no losses): Wilder = %s, reference = %s\n",
	Synthetic::fmt($flatWilder[59], 2),
	Synthetic::fmt($flatReference[59], 2),
);

echo "\n",
	"What to look at. On the steady uptrend the reference variant returns exactly\n",
	"100.00 on all 357 of its samples: differencing against a price 30 steps back\n",
	"makes the change drift-dominated and never negative, so the average loss is\n",
	"zero and the index is welded shut. Wilder's index on the same data ranges\n",
	"from 52 to 90 and keeps moving. Over the up-chop-down cycle the reference is\n",
	"pinned on 649 of 857 bars and flips 0 to 100 inside the chop; Wilder's\n",
	"touches neither extreme. The flat series returns 50 from both, the midpoint,\n",
	"rather than dividing a zero average gain by a zero average loss.\n\n",
	"Note the direction: the class docblock has this the other way round, saying\n",
	"the canonical index saturates while the lagged one does not. Measured here it\n",
	"is the lagged variant that saturates — the wider the lag, the more the drift\n",
	"dominates the difference. The conclusion still holds, and harder: 70 and 30\n",
	"mean nothing on the reference series.\n";
