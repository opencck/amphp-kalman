<?php declare(strict_types=1);

/**
 * ADX and the directional pair (M-13) across three regimes — trend up, chop,
 * trend down — so the index can be watched rising and falling with the market
 * it is measuring. Also makes the double-Wilder warm-up explicit.
 *
 *   php examples/trend-adx.php
 */

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Metric\Trend\Adx;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/trend-adx.php\n";
	exit(0);
}

const PERIOD = 14;
const TICKS_PER_BAR = 8;

// Synthetic::regimes() gives one price per tick; eight ticks make one bar, so
// the bars' highs and lows are real intrabar extremes rather than decoration.
$ticks = Synthetic::regimes(3600, 100.0, 1.2, 11);
$bars = [];
$ns = 1_700_000_000_000_000_000;
$minute = 60_000_000_000;
for ($i = 0; $i + TICKS_PER_BAR <= \count($ticks); $i += TICKS_PER_BAR) {
	$high = $ticks[$i];
	$low = $ticks[$i];
	for ($k = 1; $k < TICKS_PER_BAR; $k++) {
		$high = $ticks[$i + $k] > $high ? $ticks[$i + $k] : $high;
		$low = $ticks[$i + $k] < $low ? $ticks[$i + $k] : $low;
	}
	$bars[] = Bar::of($ns, $ns + $minute - 1, $ticks[$i], $high, $low, $ticks[$i + TICKS_PER_BAR - 1]);
	$ns += $minute;
}
$barCount = \count($bars);
$third = \intdiv($barCount, 3);

$metric = new Adx(PERIOD);
$adx = [];
$plus = [];
$minus = [];
$firstValue = -1;
$firstSettled = -1;
foreach ($bars as $index => $bar) {
	$metric->updateBar($bar);
	$values = $metric->values();
	$adx[] = $values['adx'];
	$plus[] = $values['plusDi'];
	$minus[] = $values['minusDi'];
	if ($firstValue < 0 && $metric->isReady()) {
		$firstValue = $index;
	}
	if ($firstSettled < 0 && $metric->isSettled()) {
		$firstSettled = $index;
	}
}

Synthetic::heading(
	'ADX(14) through an up trend, a flat stretch and a down trend',
	'"regime" is what the generator was doing; ADX is told nothing about it.',
);

// The three indicator columns are six wide, which is exactly what
// Synthetic::fmt() pads its NAN dash to at two decimals — so the warm-up rows
// line up with the rest instead of coming out two characters short.
\printf("%5s  %9s  %6s  %6s  %6s  %-8s  %s\n", 'bar', 'close', 'ADX', '+DI', '-DI', 'regime', 'reading');
for ($i = 25; $i < $barCount; $i += 30) {
	$regime = $i < $third ? 'up' : ($i < 2 * $third ? 'chop' : 'down');
	$value = $adx[$i];
	$reading = \is_nan($value) ? 'warming up' : ($value >= 25.0 ? 'trend' : ($value <= 20.0 ? 'no trend' : 'ambiguous'));
	if (!\is_nan($value) && $i < $firstSettled) {
		$reading .= ' (unsettled)';
	}
	\printf(
		"%5d  %9s  %6s  %6s  %6s  %-8s  %s\n",
		$i,
		Synthetic::fmt($bars[$i]->close, 3),
		Synthetic::fmt($value, 2),
		Synthetic::fmt($plus[$i], 2),
		Synthetic::fmt($minus[$i], 2),
		$regime,
		$reading,
	);
}

\printf(
	"\nbars in the series                        %d (%d per regime)\n",
	$barCount,
	$third,
);
\printf("first bar with a value (isReady)          %d, i.e. after %d bars\n", $firstValue, $firstValue + 1);
\printf(
	"first settled bar (isSettled)             %d, i.e. after %d bars = %d x period\n",
	$firstSettled,
	$firstSettled + 1,
	\intdiv($firstSettled + 1, PERIOD),
);
\printf(
	"so %s %% of this run is warm-up the index should not be traded on\n",
	Synthetic::fmt(100.0 * ($firstSettled + 1) / $barCount, 1),
);

echo "\n",
	"What to look at. ADX is blind to direction: it climbs in the up trend and\n",
	"climbs again in the down trend, and the only thing that tells the two apart\n",
	"is which of +DI and -DI is on top. Through the flat middle the pair\n",
	"converges and the index falls below 25, which is the whole point — a\n",
	"trend-following rule wants to be switched off there, and ADX is what\n",
	"switches it off.\n\n",
	"The warm-up line is the part implementations get wrong. A value appears\n",
	"after about two periods, but ADX is Wilder-smoothed twice — once into the DI\n",
	"pair, once into the index — and both stages carry their seed for roughly ten\n",
	"periods. Reading the number before isSettled() is reading the initialisation,\n",
	"not the market; the rows above are marked so the difference is visible.\n";
