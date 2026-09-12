<?php declare(strict_types=1);

/**
 * Average true range (M-23): volatility in price units, the position size it
 * implies, and the gap that the plain bar range H - L does not see.
 *
 *   php examples/volatility-atr.php
 */

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Metric\Volatility\Atr;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/volatility-atr.php\n";
	exit(0);
}

const PERIOD = 14;
const GAP_BAR = 40;
const GAP_FACTOR = 0.97;   // the market reopens three percent lower
const RISK_FRACTION = 0.01; // one percent of equity per trade
const STOP_IN_ATR = 2.0;

// One gap, at GAP_BAR: every bar from there on is shifted down together, so the
// only discontinuity is between bar 39's close and bar 40's open.
$source = Synthetic::bars(80, 100.0, 0.008, 0.0, 13, 100);
$bars = [];
foreach ($source as $index => $bar) {
	$scale = $index >= GAP_BAR ? GAP_FACTOR : 1.0;
	$bars[] = Bar::of(
		$bar->openNs,
		$bar->closeNs,
		$bar->open * $scale,
		$bar->high * $scale,
		$bar->low * $scale,
		$bar->close * $scale,
		$bar->volume,
		$bar->trades,
	);
}

$highs = [];
$lows = [];
$closes = [];
foreach ($bars as $bar) {
	$highs[] = $bar->high;
	$lows[] = $bar->low;
	$closes[] = $bar->close;
}
// The unsmoothed per-bar figure comes from the kernel, the smoothed one from
// the streaming metric: two paths over the same bars.
$trueRange = Atr::trueRanges($highs, $lows, $closes);

$metric = new Atr(PERIOD);
$atr = [];
$relative = [];
$lastAtr = \NAN;
$lastRelative = \NAN;
$lastClose = \NAN;
foreach ($bars as $bar) {
	$metric->updateBar($bar);
	$lastAtr = $metric->value();
	$lastRelative = $metric->relativeTo($bar->close);
	$lastClose = $bar->close;
	$atr[] = $lastAtr;
	$relative[] = $lastRelative;
}

Synthetic::heading(
	'ATR(14) across a gapped bar',
	'Bar 40 opens 3 % below bar 39\'s close; every later bar moves with it.',
);

\printf("%5s  %9s  %9s  %9s  %9s  %9s\n", 'bar', 'close', 'H - L', 'trueRange', 'ATR', 'ATR/P %');
for ($i = 34; $i < 48; $i++) {
	\printf(
		"%5d  %9s  %9s  %9s  %9s  %9s  %s\n",
		$i,
		Synthetic::fmt($bars[$i]->close, 3),
		Synthetic::fmt($bars[$i]->range(), 4),
		Synthetic::fmt($trueRange[$i], 4),
		Synthetic::fmt($atr[$i], 4),
		Synthetic::fmt(100.0 * $relative[$i], 3),
		$i === GAP_BAR ? '<- the gap' : '',
	);
}

$gapRange = $bars[GAP_BAR]->range();
\printf(
	"\nat the gap: bar range %s, true range %s — the true range is %sx larger\n",
	Synthetic::fmt($gapRange, 4),
	Synthetic::fmt($trueRange[GAP_BAR], 4),
	Synthetic::fmt($trueRange[GAP_BAR] / $gapRange, 2),
);
\printf(
	"ATR before the gap %s, after it %s (+%s %%)\n",
	Synthetic::fmt($atr[GAP_BAR - 1], 4),
	Synthetic::fmt($atr[GAP_BAR], 4),
	Synthetic::fmt(100.0 * ($atr[GAP_BAR] / $atr[GAP_BAR - 1] - 1.0), 1),
);

\printf(
	"\nposition sizing at the last bar: ATR = %s on a price of %s, i.e. %s %% of price.\n",
	Synthetic::fmt($lastAtr, 4),
	Synthetic::fmt($lastClose, 3),
	Synthetic::fmt(100.0 * $lastRelative, 3),
);
\printf(
	"risking %s %% of equity behind a %s x ATR stop puts %s %% of equity in the position.\n",
	Synthetic::fmt(100.0 * RISK_FRACTION, 1),
	Synthetic::fmt(STOP_IN_ATR, 1),
	Synthetic::fmt(100.0 * RISK_FRACTION / (STOP_IN_ATR * $lastRelative), 1),
);

echo "\n",
	"What to look at: bar 40. The bar itself is unremarkable — it opened, traded\n",
	"in a narrow band and closed, and H - L says so. But the market got there by\n",
	"gapping three percent away from where it last traded, and anybody holding\n",
	"overnight took that move. The true range, which also measures the distance\n",
	"from the previous close to this bar's high and low, is several times the bar\n",
	"range and drags the ATR up with it in one step.\n\n",
	"That is the whole reason risk rules are written in ATR rather than in the\n",
	"bar range or in a close-to-close sigma. relativeTo() turns the figure into a\n",
	"fraction of price, which is what makes one parameter set — stop at 2 ATR,\n",
	"risk 1 % — carry unchanged across instruments quoted in completely different\n",
	"numbers. ATR is missing from the reference catalogue entirely, which is why\n",
	"none of its thresholds can be expressed this way.\n";
