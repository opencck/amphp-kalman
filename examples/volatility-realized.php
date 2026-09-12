<?php declare(strict_types=1);

/**
 * Realised and EWMA volatility (M-37) through a volatility shock: a calm
 * stretch, ten violent observations, then calm again. The flat window holds
 * the shock at full weight and then drops it off a cliff; the EWMA decays.
 *
 *   php examples/volatility-realized.php
 */

use OpenCCK\Kalman\Domain\Metric\Volatility\RealizedVolatility;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/volatility-realized.php\n";
	exit(0);
}

const WINDOW = 60;
const CALM_BARS = 120;
const SHOCK_BARS = 10;

// Three segments spliced at their endpoints, so the only discontinuity in the
// series is the volatility itself, never the level.
$calm = Synthetic::prices(CALM_BARS, 100.0, 0.05, 0.0, 3);
$shock = Synthetic::prices(SHOCK_BARS, $calm[CALM_BARS - 1], 1.2, 0.0, 5);
$after = Synthetic::prices(220, $shock[SHOCK_BARS - 1], 0.05, 0.0, 9);
$prices = \array_merge($calm, $shock, $after);

$shockFrom = CALM_BARS;
$shockTo = CALM_BARS + SHOCK_BARS - 1;

$realized = RealizedVolatility::realized($prices, WINDOW);
$ewma = RealizedVolatility::ewma($prices, RealizedVolatility::RISKMETRICS_LAMBDA);

Synthetic::heading(
	'A volatility shock at observations 120-129, seen two ways',
	'Realised sigma over a flat 60-observation window, and the RiskMetrics EWMA.',
);

\printf("%6s  %10s  %10s  %10s  %s\n", 'i', 'price', 'realised', 'EWMA', 'phase');
for ($i = 110; $i <= 260; $i += 10) {
	$phase = $i < $shockFrom
		? 'calm'
		: ($i <= $shockTo ? 'SHOCK' : ($i <= $shockTo + WINDOW ? 'shock still inside the window' : 'window clear'));
	\printf(
		"%6d  %10s  %10s  %10s  %s\n",
		$i,
		Synthetic::fmt($prices[$i], 3),
		Synthetic::fmt($realized[$i], 6),
		Synthetic::fmt($ewma[$i], 6),
		$phase,
	);
}

// Where the flat window lets go.
$biggestDrop = 0.0;
$dropAt = 0;
$n = \count($prices);
for ($i = $shockTo + 1; $i < $n; $i++) {
	if (\is_nan($realized[$i]) || \is_nan($realized[$i - 1]) || $realized[$i - 1] <= 0.0) {
		continue;
	}
	$drop = 1.0 - $realized[$i] / $realized[$i - 1];
	if ($drop > $biggestDrop) {
		$biggestDrop = $drop;
		$dropAt = $i;
	}
}

\printf(
	"\nlargest single-step fall in the realised figure: %s %% at observation %d,\n",
	Synthetic::fmt(100.0 * $biggestDrop, 1),
	$dropAt,
);
\printf(
	"which is %d observations after the shock ended — one full window.\n",
	$dropAt - $shockTo,
);
\printf(
	"EWMA half-life at lambda = %s: %s observations.\n",
	Synthetic::fmt(RealizedVolatility::RISKMETRICS_LAMBDA, 2),
	Synthetic::fmt(\log(0.5) / \log(RealizedVolatility::RISKMETRICS_LAMBDA), 1),
);
\printf(
	"peak EWMA %s against a calm level of %s; at observation 260 it is back to %s.\n",
	Synthetic::fmt($ewma[$shockTo + 1], 6),
	Synthetic::fmt($ewma[110], 6),
	Synthetic::fmt($ewma[260], 6),
);

echo "\n",
	"What to look at: the realised column between observations 130 and 180. It\n",
	"does not move. Ten violent observations entered a 60-wide box and each one\n",
	"counts exactly as much on its fiftieth day inside the box as on its first,\n",
	"so the reading sits flat at a level the market stopped justifying fifty\n",
	"observations ago. Then, one window after the shock, they fall out together\n",
	"and the figure collapses in a few steps — a step down caused by nothing that\n",
	"happened in the market, only by the box's edge.\n\n",
	"The EWMA column has no edge. It spikes when the shock arrives and then\n",
	"decays geometrically, halving every 11 observations, so it is already most\n",
	"of the way back to the calm level while the realised figure has still not\n",
	"begun to move at all. That is the whole reason RiskMetrics chose 0.94.\n\n",
	"Neither is a good variance estimate: squared returns are extremely noisy,\n",
	"which is why the range-based estimators in OhlcVolatility exist and why\n",
	"StochasticVolatility treats volatility as a hidden state to be filtered.\n";
