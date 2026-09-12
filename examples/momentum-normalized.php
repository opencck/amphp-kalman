<?php declare(strict_types=1);

/**
 * Normalised average momentum (M-09, the reference NAPM): the average log
 * return across eight non-overlapping blocks, divided by the dispersion across
 * those blocks. A Sharpe ratio computed on the instrument's own recent path.
 *
 *   php examples/momentum-normalized.php
 */

use OpenCCK\Kalman\Domain\Metric\Momentum\Momentum;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/momentum-normalized.php\n";
	exit(0);
}

const STEP = 60;
const BLOCKS = 8;

$trending = Synthetic::prices(1000, 100.0, 0.2, 0.05, 7);
$choppy = Synthetic::prices(1000, 100.0, 0.2, 0.0, 23);
// The same trend and the same noise, both multiplied by five.
$loud = Synthetic::prices(1000, 100.0, 1.0, 0.25, 7);

$trendNapm = Momentum::normalized($trending, STEP, BLOCKS);
$chopNapm = Momentum::normalized($choppy, STEP, BLOCKS);

Synthetic::heading(
	'NAPM over eight non-overlapping 60-observation blocks',
	'A trending instrument and a choppy one, measured the same way.',
);

\printf("%6s  %10s  %9s  %10s  %9s\n", 'i', 'trend P', 'NAPM', 'chop P', 'NAPM');
for ($i = 490; $i < 1000; $i += 45) {
	\printf(
		"%6d  %10s  %9s  %10s  %9s\n",
		$i,
		Synthetic::fmt($trending[$i], 3),
		Synthetic::fmt($trendNapm[$i], 3),
		Synthetic::fmt($choppy[$i], 3),
		Synthetic::fmt($chopNapm[$i], 3),
	);
}

/**
 * Final mean, dispersion and ratio of the block returns for a series.
 *
 * The ratio comes from the streaming metric and the two parts from the
 * `blockReturns()` kernel, so the two paths cross-check each other.
 *
 * @param list<float> $prices
 * @return array{napm: float, mean: float, sd: float}
 */
function decompose(array $prices): array
{
	$metric = new Momentum(STEP, BLOCKS);
	foreach ($prices as $price) {
		$metric->updatePrice(0, $price);
	}
	$returns = Momentum::blockReturns($prices, STEP, BLOCKS);
	$count = \count($returns);
	$mean = \array_sum($returns) / $count;
	$squares = 0.0;
	foreach ($returns as $return) {
		$d = $return - $mean;
		$squares += $d * $d;
	}
	return ['napm' => $metric->value(), 'mean' => $mean, 'sd' => \sqrt($squares / ($count - 1))];
}

$trendParts = decompose($trending);
$chopParts = decompose($choppy);
$loudParts = decompose($loud);

Synthetic::heading(
	'The decomposition at the last observation',
	'mean(r) is the average block log return, sd(r) the spread across blocks.',
);

\printf("%-28s  %12s  %12s  %10s\n", 'series', 'mean(r) %', 'sd(r) %', 'NAPM');
\printf("%-28s  %12s  %12s  %10s\n", 'trending, step sigma 0.2', Synthetic::fmt(100.0 * $trendParts['mean'], 4), Synthetic::fmt(100.0 * $trendParts['sd'], 4), Synthetic::fmt($trendParts['napm'], 3));
\printf("%-28s  %12s  %12s  %10s\n", 'choppy, step sigma 0.2', Synthetic::fmt(100.0 * $chopParts['mean'], 4), Synthetic::fmt(100.0 * $chopParts['sd'], 4), Synthetic::fmt($chopParts['napm'], 3));
\printf("%-28s  %12s  %12s  %10s\n", 'trending x5, step sigma 1.0', Synthetic::fmt(100.0 * $loudParts['mean'], 4), Synthetic::fmt(100.0 * $loudParts['sd'], 4), Synthetic::fmt($loudParts['napm'], 3));

$blocks = Momentum::blockReturns($trending, STEP, BLOCKS);
echo "\nthe eight block returns behind the trending row, oldest first (%):";
foreach ($blocks as $r) {
	\printf(' %s', Synthetic::fmt(100.0 * $r, 2));
}
echo "\n";

echo "\n",
	"How to read it. NAPM is return per unit of risk over the lookback: eight\n",
	"independent measurements of where the price went, divided by how much they\n",
	"disagreed. Above about 2 the eight blocks nearly all point the same way and\n",
	"a trend-following rule has something to work with; near zero they cancel,\n",
	"and the choppy column stays inside +-0.3 for the whole run precisely because\n",
	"its blocks are as often negative as positive.\n\n",
	"The third row of the second table is why the division matters. That series\n",
	"has five times the drift and five times the noise of the first. Its average\n",
	"block return is 2.4 times larger (4.90 % against 2.01 %), which a rule with\n",
	"a fixed threshold on raw return would read as a far stronger signal — but\n",
	"its dispersion grew by 2.8 times at the same time, so NAPM lands at 2.61\n",
	"against 3.016: the same trend, correctly reported as no better. That is what\n",
	"lets one threshold cover a stablecoin pair and a small-cap altcoin at once.\n\n",
	"The blocks do not overlap, unlike the reference NdT construction, so the\n",
	"denominator is an honest dispersion rather than one deflated by shared data.\n";
