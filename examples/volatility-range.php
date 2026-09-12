<?php declare(strict_types=1);

/**
 * Relative price range (M-21, the reference RPR): the raw range, its Parkinson
 * conversion into a standard deviation, and what the reference's 5-minute
 * pre-average does to the very spikes the range is meant to catch.
 *
 *   php examples/volatility-range.php
 */

use OpenCCK\Kalman\Domain\Metric\Volatility\RangeVolatility;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/volatility-range.php\n";
	exit(0);
}

const WINDOW = 120;
const REFERENCE_SMOOTHING = 5;   // the reference averages the price over 5 minutes first

$prices = Synthetic::prices(700, 100.0, 0.2, 0.0, 7);

$raw = RangeVolatility::compute($prices, WINDOW, 1);
$rawSigma = RangeVolatility::sigmaSeries($prices, WINDOW, 1);
$smoothed = RangeVolatility::compute($prices, WINDOW, REFERENCE_SMOOTHING);
$smoothedSigma = RangeVolatility::sigmaSeries($prices, WINDOW, REFERENCE_SMOOTHING);

Synthetic::heading(
	'RPR over a 120-observation window, raw and pre-smoothed over 5',
	'"sigma" is the same figure after the Parkinson conversion.',
);

\printf("%6s  %9s  %10s  %10s  %10s  %10s\n", 'i', 'price', 'RPR', 'sigma', 'RPR(sm 5)', 'sigma(sm 5)');
for ($i = 160; $i < 700; $i += 45) {
	\printf(
		"%6d  %9s  %10s  %10s  %10s  %10s\n",
		$i,
		Synthetic::fmt($prices[$i], 3),
		Synthetic::fmt($raw[$i], 6),
		Synthetic::fmt($rawSigma[$i], 6),
		Synthetic::fmt($smoothed[$i], 6),
		Synthetic::fmt($smoothedSigma[$i], 6),
	);
}

\printf(
	"\nParkinson factor: %.16f, and 2*sqrt(ln 2) recomputed here: %.16f\n",
	RangeVolatility::PARKINSON_FACTOR,
	2.0 * \sqrt(\M_LN2),
);
\printf(
	"so a range of 1.0 %% is a sigma of %s %% — reading RPR as a sigma overstates it by %s %%.\n",
	Synthetic::fmt(100.0 * RangeVolatility::toSigma(0.01), 4),
	Synthetic::fmt(100.0 * (RangeVolatility::PARKINSON_FACTOR - 1.0), 1),
);

/**
 * Mean of the finite entries of a series.
 *
 * @param list<float> $values
 */
function meanOf(array $values): float
{
	$count = 0;
	$sum = 0.0;
	foreach ($values as $value) {
		if (!\is_nan($value)) {
			$count++;
			$sum += $value;
		}
	}
	return $count > 0 ? $sum / $count : \NAN;
}

$baseline = meanOf($raw);

Synthetic::heading('What pre-smoothing the price costs the measurement');

\printf("%12s  %12s  %12s  %14s\n", 'smoothing', 'mean RPR', 'mean sigma', 'vs smoothing 1');
foreach ([1, 3, 5, 15] as $smoothing) {
	$series = RangeVolatility::compute($prices, WINDOW, $smoothing);
	$mean = meanOf($series);
	\printf(
		"%12d  %12s  %12s  %13s %%\n",
		$smoothing,
		Synthetic::fmt($mean, 6),
		Synthetic::fmt(RangeVolatility::toSigma($mean), 6),
		Synthetic::fmt(100.0 * ($mean / $baseline - 1.0), 1),
	);
}

echo "\n",
	"What to look at: the last table. The reference computes its range on a\n",
	"price that has already been averaged over five minutes, and that alone\n",
	"removes 14 % of the measured range — the same series, the same window, a\n",
	"volatility reading a seventh smaller. Averaging is a low-pass filter and a\n",
	"maximum is exactly what it attenuates, so the pre-average clips the spikes\n",
	"the range exists to catch. Push the smoothing to 15 and 30 % of the reading\n",
	"is gone. Whatever threshold sits on top of that figure is calibrated on the\n",
	"smoothing, not on the market.\n\n",
	"The factor line is the second thing worth keeping. RPR is a range, not a\n",
	"standard deviation; Parkinson's estimator relates the two through\n",
	"2*sqrt(ln 2) = 1.6651, so a range read as a sigma is 66.5 % too large. That\n",
	"costs nothing while RPR is only ever compared against itself, and it costs a\n",
	"great deal the moment it meets a filter's Q, an implied volatility or a risk\n",
	"limit expressed in sigma. sigma() applies the conversion; value() keeps the\n",
	"raw figure so the reference number stays reproducible.\n";
