<?php declare(strict_types=1);

/**
 * Trending or going nowhere, read three ways (M-27).
 *
 *   php examples/regime-flat-market.php
 *
 * `Domain\Metric\Regime\FlatMarket` gives two independent readings, because
 * they fail in different places: the efficiency ratio, which is the share of
 * the distance travelled that became displacement, and the band width, which
 * is the Bollinger squeeze. A market can be quiet and directional or violent
 * and directionless, and having both separates those cases.
 *
 * Its own docblock then says the filter does this job better, so the third
 * column here is `Filtered\Derivative`'s t-statistic on the same prices: the
 * same judgement with a significance level attached instead of a threshold
 * somebody picked.
 */

use OpenCCK\Kalman\Domain\Metric\Filtered\Derivative;
use OpenCCK\Kalman\Domain\Metric\Regime\FlatMarket;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/**
 * Mean of a series over a slice, skipping NAN.
 *
 * @param list<float> $series
 */
function regimeMean(array $series, int $from, int $to, bool $absolute = false): float
{
	$sum = 0.0;
	$n = 0;
	for ($i = \max($from, 0); $i < $to; $i++) {
		if (!isset($series[$i]) || \is_nan($series[$i])) {
			continue;
		}
		$sum += $absolute ? \abs($series[$i]) : $series[$i];
		$n++;
	}
	return $n > 0 ? $sum / $n : \NAN;
}

/**
 * Share of samples over a slice for which the filter calls a significant trend.
 *
 * @param list<float> $tStats
 */
function regimeSignificantShare(array $tStats, int $from, int $to): float
{
	$significant = 0;
	$n = 0;
	for ($i = \max($from, 0); $i < $to; $i++) {
		if (!isset($tStats[$i]) || \is_nan($tStats[$i])) {
			continue;
		}
		$n++;
		if (\abs($tStats[$i]) > 2.0) {
			$significant++;
		}
	}
	return $n > 0 ? $significant / $n : \NAN;
}

$count = 900;
$window = 60;
$third = \intdiv($count, 3);
$prices = Synthetic::regimes($count);
$timestamps = Synthetic::timestamps($count, 1.0);

$regime = FlatMarket::compute($prices, $window, 2.0);
$filtered = Derivative::ofSeries($timestamps, $prices, 0.05);
$smoothed = Derivative::ofSeries($timestamps, $prices, 0.005);

$label = static function (int $i) use ($third): string {
	return $i < $third ? 'trend up' : ($i < 2 * $third ? 'chop' : 'trend down');
};

Synthetic::heading(
	'Three readings of the same price path (M-27)',
	\sprintf('%d prices: up %d, sideways %d, down %d; efficiency-ratio window = %d', $count, $third, $third, $count - 2 * $third, $window),
);

echo "     t      price      ER   bandWidth    tStat   regime      ER says\n";
echo "  " . \str_repeat('-', 74) . "\n";
for ($i = 99; $i < $count; $i += 100) {
	$er = $regime['efficiencyRatio'][$i];
	echo \sprintf(
		"  %4d %10s %7s %11s %8s   %-11s %s\n",
		$i,
		Synthetic::fmt($prices[$i], 3),
		Synthetic::fmt($er, 3),
		Synthetic::fmt($regime['bandWidth'][$i], 5),
		Synthetic::fmt($filtered['tStat'][$i], 2),
		$label($i),
		$er > 0.3 ? 'trending' : 'flat',
	);
}

Synthetic::heading('Averaged over each regime', 'the warm-up of both estimators is skipped');

echo "  regime        mean ER   bandWidth   |t| @0.05   >2   |t| @0.005    >2\n";
echo "  " . \str_repeat('-', 72) . "\n";
foreach ([['trend up', 100, $third], ['chop', $third + $window, 2 * $third], ['trend down', 2 * $third + $window, $count]] as [$name, $from, $to]) {
	echo \sprintf(
		"  %-12s %9s %11s %11s %6s %11s %5s\n",
		$name,
		Synthetic::fmt(regimeMean($regime['efficiencyRatio'], $from, $to), 4),
		Synthetic::fmt(regimeMean($regime['bandWidth'], $from, $to), 5),
		Synthetic::fmt(regimeMean($filtered['tStat'], $from, $to, true), 2),
		Synthetic::fmt(regimeSignificantShare($filtered['tStat'], $from, $to), 2),
		Synthetic::fmt(regimeMean($smoothed['tStat'], $from, $to, true), 2),
		Synthetic::fmt(regimeSignificantShare($smoothed['tStat'], $from, $to), 2),
	);
}

echo "\n";
echo "The efficiency ratio separates the regimes without a single calibrated constant:\n";
echo "it is a ratio of two distances measured in the same units, so it is scale-free\n";
echo "and the same threshold works on any instrument. That is the whole reason to\n";
echo "prefer it to a volatility reading as a trend gate.\n";
echo "Band width is a genuinely different statement. It is a dispersion, so it rises\n";
echo "in the trending thirds because a drifting price has a wide band around its own\n";
echo "moving mean — not because the market is choppy. Reading it as a trend indicator\n";
echo "would get the sign backwards; reading it as a squeeze detector, which is what it\n";
echo "is, is what makes carrying both worthwhile.\n";
echo "The t-statistic is the same judgement stated as a test. At trackingIndex 0.05 it\n";
echo "averages above 5 in the trending thirds and calls a significant trend in nine\n";
echo "samples out of ten, against 1.98 and 45 per cent in the chop — the same ordering\n";
echo "the efficiency ratio gives, with a significance level instead of a threshold\n";
echo "somebody picked, and with the level and the rate attached.\n";
echo "The second pair of columns is the caveat. Turning the tracking index down to\n";
echo "0.005 makes the filter far more confident everywhere — |t| near 28 in the trends\n";
echo "— but it also calls a significant trend in three quarters of the chop. A heavily\n";
echo "smoothed constant-velocity model is misspecified against a random walk, which\n";
echo "has no persistent velocity to find, and a t-statistic is only ever as honest as\n";
echo "the model behind it. The efficiency ratio has no model and cannot make that\n";
echo "mistake, which is why `FlatMarket` is worth keeping even where the filter runs.\n";
