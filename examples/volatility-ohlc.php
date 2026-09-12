<?php declare(strict_types=1);

/**
 * OHLC volatility (M-22): the five estimators run on bars generated with a
 * known per-bar sigma, so both the bias and — the point of the exercise — the
 * dispersion of each estimate can be read off directly.
 *
 *   php examples/volatility-ohlc.php
 */

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Metric\Volatility\OhlcVolatility;
use OpenCCK\Kalman\Domain\Metric\Volatility\VolatilityEstimator;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/volatility-ohlc.php\n";
	exit(0);
}

const TRUE_SIGMA = 0.01;   // per bar, by construction
const WINDOW = 20;
const BARS = 500;
const STEPS_PER_BAR = 200;

/**
 * Runs every estimator over one bar series and prints a row each: the mean of
 * the rolling estimate and its dispersion across the series.
 *
 * @param list<Bar> $bars
 */
function panel(array $bars, float $baselineCv): float
{
	$opens = [];
	$highs = [];
	$lows = [];
	$closes = [];
	foreach ($bars as $bar) {
		$opens[] = $bar->open;
		$highs[] = $bar->high;
		$lows[] = $bar->low;
		$closes[] = $bar->close;
	}

	\printf("%-17s  %9s  %9s  %8s  %10s  %10s\n", 'estimator', 'mean', 'sd', 'cv', 'measured', 'declared');
	$baseline = $baselineCv;
	foreach (VolatilityEstimator::cases() as $estimator) {
		$series = OhlcVolatility::compute($opens, $highs, $lows, $closes, WINDOW, $estimator->value);
		$count = 0;
		$sum = 0.0;
		foreach ($series as $value) {
			if (!\is_nan($value)) {
				$count++;
				$sum += $value;
			}
		}
		$mean = $sum / $count;
		$squares = 0.0;
		foreach ($series as $value) {
			if (!\is_nan($value)) {
				$d = $value - $mean;
				$squares += $d * $d;
			}
		}
		$sd = \sqrt($squares / ($count - 1));
		$cv = $sd / $mean;
		if ($estimator === VolatilityEstimator::CloseToClose) {
			$baseline = $cv;
		}
		\printf(
			"%-17s  %9s  %9s  %8s  %10s  %10s\n",
			$estimator->value,
			Synthetic::fmt($mean, 5),
			Synthetic::fmt($sd, 5),
			Synthetic::fmt($cv, 3),
			Synthetic::fmt(($baseline / $cv) ** 2, 2),
			Synthetic::fmt($estimator->efficiency(), 1),
		);
	}
	return $baseline;
}

Synthetic::heading(
	'Five estimators on bars built with sigma = 0.0100 per bar, no drift',
	'"measured" is the efficiency implied by the dispersion, against the declared one.',
);
$baseline = panel(Synthetic::bars(BARS, 100.0, TRUE_SIGMA, 0.0, 13, STEPS_PER_BAR), \NAN);

Synthetic::heading(
	'The same bars with a strong drift of +0.03 log points per bar',
	'Same sigma, three times as much directional movement.',
);
panel(Synthetic::bars(BARS, 100.0, TRUE_SIGMA, 0.03, 13, STEPS_PER_BAR), $baseline);

echo "\n", 'drift-independent: ';
foreach (VolatilityEstimator::cases() as $estimator) {
	if ($estimator->isDriftIndependent()) {
		echo $estimator->value, ' ';
	}
}
echo "\n", 'handles gaps:      ';
foreach (VolatilityEstimator::cases() as $estimator) {
	if ($estimator->handlesGaps()) {
		echo $estimator->value, ' ';
	}
}
echo "\n";

echo "\n",
	"What to look at in the first panel: the sd column, not the mean. All five\n",
	"land near the true 0.0100 — the range-based ones a few percent low, because\n",
	"a bar built from 200 discrete steps never quite reaches the extremes a\n",
	"continuous path would. What separates them is how much the estimate moves\n",
	"from window to window. Close-to-close wobbles by 15 % of its own value;\n",
	"Rogers-Satchell and Yang-Zhang by under 6 %. The implied efficiency column\n",
	"turns that into the number the docblock quotes: the same accuracy from\n",
	"roughly a sixth of the history, which is a risk limit that reacts within the\n",
	"hour instead of tomorrow. Yang-Zhang measures 6.6 rather than its declared\n",
	"14 here, and correctly so: these bars are continuous, each open equal to the\n",
	"previous close, so its overnight-gap term has nothing to contribute.\n\n",
	"The second panel is why Rogers-Satchell is the default on a trending\n",
	"instrument. A drift of three sigma per bar does not change the volatility at\n",
	"all, and Rogers-Satchell moves by 5 %, from 0.00946 to 0.00900. Parkinson\n",
	"doubles to 0.0205 and Garman-Klass rises 47 % to 0.0139, because both assume\n",
	"zero drift and a directional move inflates the range they measure. Reading\n",
	"either as sigma during a trend sizes every position off a volatility that is\n",
	"not there. (Their dispersion stays small — they are precisely wrong, which is\n",
	"worse than noisily right.)\n";
