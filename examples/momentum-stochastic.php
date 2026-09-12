<?php declare(strict_types=1);

/**
 * Stochastic oscillator (M-06): %K with its signal line %D, the crossovers
 * that are the actual trading event, and fast against slow. The reference
 * PromQL formula computes %K only, so none of the signals below exist there.
 *
 *   php examples/momentum-stochastic.php
 */

use OpenCCK\Kalman\Domain\Metric\Momentum\Stochastic;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/momentum-stochastic.php\n";
	exit(0);
}

const K_PERIOD = 14;
const D_PERIOD = 3;

$bars = Synthetic::bars(160, 100.0, 0.015, 0.0, 13, 40);
$highs = [];
$lows = [];
$closes = [];
foreach ($bars as $bar) {
	$highs[] = $bar->high;
	$lows[] = $bar->low;
	$closes[] = $bar->close;
}

$fast = Stochastic::full($highs, $lows, $closes, K_PERIOD, 1, D_PERIOD);
$slow = Stochastic::full($highs, $lows, $closes, K_PERIOD, 3, D_PERIOD);

/**
 * Crossover direction at index `$i`, as a word: %K rising through %D is a buy,
 * %K falling through %D is a sell, and everything else is silence.
 *
 * @param list<float> $k
 * @param list<float> $d
 */
function crossing(array $k, array $d, int $i): string
{
	if ($i < 1 || \is_nan($k[$i]) || \is_nan($d[$i]) || \is_nan($k[$i - 1]) || \is_nan($d[$i - 1])) {
		return '';
	}
	$before = $k[$i - 1] - $d[$i - 1];
	$now = $k[$i] - $d[$i];
	if ($before <= 0.0 && $now > 0.0) {
		return 'buy';
	}
	return $before >= 0.0 && $now < 0.0 ? 'sell' : '';
}

Synthetic::heading(
	'Fast (%K raw) and slow (%K smoothed over 3) stochastics, 14/3',
	'The "signal" columns fire where %K crosses its own signal line %D.',
);

\printf("%5s  %8s  %7s %7s %7s  %7s %7s %7s\n", 'bar', 'close', 'fastK', 'fastD', 'signal', 'slowK', 'slowD', 'signal');
for ($i = 30; $i < 50; $i++) {
	\printf(
		"%5d  %8s  %7s %7s %7s  %7s %7s %7s\n",
		$i,
		Synthetic::fmt($closes[$i], 3),
		Synthetic::fmt($fast['k'][$i], 1),
		Synthetic::fmt($fast['d'][$i], 1),
		crossing($fast['k'], $fast['d'], $i),
		Synthetic::fmt($slow['k'][$i], 1),
		Synthetic::fmt($slow['d'][$i], 1),
		crossing($slow['k'], $slow['d'], $i),
	);
}

$fastCrossings = 0;
$slowCrossings = 0;
$n = \count($closes);
for ($i = 0; $i < $n; $i++) {
	if (crossing($fast['k'], $fast['d'], $i) !== '') {
		$fastCrossings++;
	}
	if (crossing($slow['k'], $slow['d'], $i) !== '') {
		$slowCrossings++;
	}
}

// What a scraper sees: quotes sampled at the close, never the intrabar wick.
$sampled = Stochastic::full($closes, $closes, $closes, K_PERIOD, 1, D_PERIOD);
$trueExtreme = 0;
$sampledExtreme = 0;
for ($i = 0; $i < $n; $i++) {
	if (!\is_nan($fast['k'][$i]) && ($fast['k'][$i] >= 95.0 || $fast['k'][$i] <= 5.0)) {
		$trueExtreme++;
	}
	if (!\is_nan($sampled['k'][$i]) && ($sampled['k'][$i] >= 95.0 || $sampled['k'][$i] <= 5.0)) {
		$sampledExtreme++;
	}
}

\printf(
	"\nover %d bars: fast %%K/%%D crossings = %d, slow = %d\n",
	$n,
	$fastCrossings,
	$slowCrossings,
);
\printf(
	"bars reading beyond 95 or below 5: from real highs and lows = %d, from sampled closes = %d\n",
	$trueExtreme,
	$sampledExtreme,
);

\printf(
	"\nWhat to look at: the two signal columns. They are the whole indicator.\n"
	. "Lane's oscillator is a pair of lines and the tradable event is the moment\n"
	. "%%K crosses %%D; the reference expression stops at %%K, so it can report a\n"
	. "level but never an event. This series carries %d of those events on the fast\n"
	. "pair and %d on the slow one: smoothing %%K over three bars before the signal\n"
	. "line halves the whipsaw without moving the crossings that matter.\n\n"
	. "The last line is the second thing the reference gets wrong. Replacing the\n"
	. "bars' own highs and lows with sampled quotes (here, the closes) narrows the\n"
	. "range the oscillator divides by, so the reading is pushed to the edges: %d\n"
	. "bars beyond 95/5 instead of %d. It calls \"overbought\" because it never saw\n"
	. "how far the market actually traded inside the bar.\n",
	$fastCrossings,
	$slowCrossings,
	$sampledExtreme,
	$trueExtreme,
);
