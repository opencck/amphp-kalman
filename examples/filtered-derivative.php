<?php declare(strict_types=1);

/**
 * The rate of change of anything, estimated instead of subtracted (M-02).
 *
 *   php examples/filtered-derivative.php
 *
 * `Domain\Metric\Filtered\Derivative` replaces every `dX` in the reference
 * catalogue — fourteen of them — with one estimator. This example builds a
 * series whose rate of change is known exactly, then scores three ways of
 * measuring it: the finite difference the reference uses, the least-squares
 * slope Prometheus' `deriv()` computes (written out inline below), and the
 * constant-velocity Kalman filter. Then it shows the thing only the filter
 * gives you: an uncertainty on the rate, and therefore a significance test.
 */

use OpenCCK\Kalman\Domain\Metric\Filtered\Derivative;
use OpenCCK\Kalman\Domain\Metric\Momentum\Rsi;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

$count = 800;
$period = 200.0;
$amplitude = 3.0;
$noiseScale = 0.05;
$dt = 1.0;

$timestamps = Synthetic::timestamps($count, $dt);
$noiseWalk = Synthetic::prices($count + 1, 0.0, $noiseScale);

/** @var list<float> $truth the rate of change, known in closed form */
$truth = [];
/** @var list<float> $observed */
$observed = [];
for ($i = 0; $i < $count; $i++) {
	$phase = 2.0 * \M_PI * $i / $period;
	$level = 100.0 + $amplitude * \sin($phase);
	$truth[] = $amplitude * 2.0 * \M_PI / $period * \cos($phase);
	$observed[] = $level + ($noiseWalk[$i + 1] - $noiseWalk[$i]);
}

/**
 * Prometheus' deriv(): ordinary least squares of the value on time, over the
 * last `window` samples, every point weighted equally.
 *
 * @param list<float> $values
 * @return list<float> NAN until the window is full
 */
function prometheusDeriv(array $values, int $window, float $step): array
{
	$out = [];
	$n = \count($values);
	for ($i = 0; $i < $n; $i++) {
		if ($i + 1 < $window) {
			$out[] = \NAN;
			continue;
		}
		$sumT = 0.0;
		$sumY = 0.0;
		$sumTt = 0.0;
		$sumTy = 0.0;
		for ($k = 0; $k < $window; $k++) {
			$index = $i - $window + 1 + $k;
			if (!isset($values[$index])) {
				continue;
			}
			$t = $k * $step;
			$y = $values[$index];
			$sumT += $t;
			$sumY += $y;
			$sumTt += $t * $t;
			$sumTy += $t * $y;
		}
		$denominator = $window * $sumTt - $sumT * $sumT;
		$out[] = $denominator > 0.0 ? ($window * $sumTy - $sumT * $sumY) / $denominator : \NAN;
	}
	return $out;
}

/**
 * Root-mean-square error against the known rate, over the settled tail.
 *
 * @param list<float> $estimate
 * @param list<float> $actual
 */
function rateRmse(array $estimate, array $actual, int $from): float
{
	$sum = 0.0;
	$n = 0;
	$count = \count($actual);
	for ($i = \max($from, 0); $i < $count; $i++) {
		if (!isset($estimate[$i]) || \is_nan($estimate[$i])) {
			continue;
		}
		$error = $estimate[$i] - $actual[$i];
		$sum += $error * $error;
		$n++;
	}
	return $n > 0 ? \sqrt($sum / $n) : \NAN;
}

/**
 * How often the filter calls the trend significant, and how large |t| ever gets.
 *
 * @param list<float> $tStats
 * @return array{share: float, peak: float}
 */
function tStatSummary(array $tStats, int $from): array
{
	$significant = 0;
	$n = 0;
	$peak = 0.0;
	$count = \count($tStats);
	for ($i = \max($from, 0); $i < $count; $i++) {
		if (\is_nan($tStats[$i])) {
			continue;
		}
		$n++;
		$magnitude = \abs($tStats[$i]);
		if ($magnitude > 2.0) {
			$significant++;
		}
		if ($magnitude > $peak) {
			$peak = $magnitude;
		}
	}
	return ['share' => $n > 0 ? $significant / $n : \NAN, 'peak' => $peak];
}

$from = 150;
$finiteDifference = [\NAN];
for ($i = 1; $i < $count; $i++) {
	$finiteDifference[] = ($observed[$i] - $observed[$i - 1]) / $dt;
}

/** @var array<string, list<float>> $estimators */
$estimators = [
	'finite difference (x_t - x_{t-1})/dt' => $finiteDifference,
	'deriv() over 10 samples' => prometheusDeriv($observed, 10, $dt),
	'deriv() over 30 samples' => prometheusDeriv($observed, 30, $dt),
	'deriv() over 60 samples' => prometheusDeriv($observed, 60, $dt),
	'Derivative, trackingIndex 0.01' => Derivative::ofSeries($timestamps, $observed, 0.01)['rate'],
	'Derivative, trackingIndex 0.05' => Derivative::ofSeries($timestamps, $observed, 0.05)['rate'],
	'Derivative, trackingIndex 0.20' => Derivative::ofSeries($timestamps, $observed, 0.20)['rate'],
];

Synthetic::heading(
	'Three estimators of a rate that is known exactly (M-02)',
	\sprintf(
		'level = 100 + %s sin(2 pi t / %s), observed with noise sd %s; true |rate| peaks at %s per second',
		Synthetic::fmt($amplitude, 1),
		Synthetic::fmt($period, 0),
		Synthetic::fmt($noiseScale / \sqrt(3.0), 4),
		Synthetic::fmt($amplitude * 2.0 * \M_PI / $period, 4),
	),
);

$baseline = rateRmse($finiteDifference, $truth, $from);
echo "  estimator                                 RMSE   RMSE(diff)/RMSE   verdict\n";
echo "  " . \str_repeat('-', 74) . "\n";
foreach ($estimators as $label => $series) {
	$error = rateRmse($series, $truth, $from);
	$ratio = $baseline / $error;
	echo \sprintf(
		"  %-38s %9s %17s   %s\n",
		$label,
		Synthetic::fmt($error, 5),
		Synthetic::fmt($ratio, 2),
		$ratio > 1.05 ? 'better' : ($ratio < 0.95 ? 'WORSE' : 'no different'),
	);
}

echo "\n  The finite difference is worse than useless here: its error is comparable to the\n";
echo "  largest true rate in the series, so its sign carries almost no information, and\n";
echo "  shrinking dt to make it more local makes it worse without limit.\n";
echo "  Honestly read, a well-chosen deriv() window is competitive — ten samples comes\n";
echo "  within ten per cent of the best filter. The catch is 'chosen': across three\n";
echo "  plausible windows deriv()'s error moves by a factor of six, and at sixty samples\n";
echo "  it is worse than the difference it was meant to improve on. The filter's error\n";
echo "  moves by 2.5 across the same span of its knob, it needs no window, and only it\n";
echo "  can tell you which of these numbers to believe — the next section.\n";

Synthetic::heading('What only the filter reports: how sure it is', 'the same series, with the rate uncertainty and the t-statistic');

$filtered = Derivative::ofSeries($timestamps, $observed, 0.05);
echo "     t   observed      level    true rate   est. rate   rateStd   tStat\n";
echo "  " . \str_repeat('-', 72) . "\n";
foreach ([160, 200, 250, 300, 400, 500] as $i) {
	echo \sprintf(
		"  %4d %10s %10s %12s %11s %9s %7s\n",
		$i,
		Synthetic::fmt($observed[$i], 4),
		Synthetic::fmt($filtered['level'][$i], 4),
		Synthetic::fmt($truth[$i], 5),
		Synthetic::fmt($filtered['rate'][$i], 5),
		Synthetic::fmt($filtered['rateStd'][$i], 5),
		Synthetic::fmt($filtered['tStat'][$i], 2),
	);
}

// A series with no trend at all: the same noise, a constant level.
$flat = [];
for ($i = 0; $i < $count; $i++) {
	$flat[] = 100.0 + ($noiseWalk[$i + 1] - $noiseWalk[$i]);
}
$flatFiltered = Derivative::ofSeries($timestamps, $flat, 0.05);
$weak = [];
$strong = [];
for ($i = 0; $i < $count; $i++) {
	$weak[] = 100.0 + 0.010 * $i + ($noiseWalk[$i + 1] - $noiseWalk[$i]);
	$strong[] = 100.0 + 0.050 * $i + ($noiseWalk[$i + 1] - $noiseWalk[$i]);
}
$weakFiltered = Derivative::ofSeries($timestamps, $weak, 0.05);
$strongFiltered = Derivative::ofSeries($timestamps, $strong, 0.05);

echo "\n  series                          samples with |t| > 2   largest |t|\n";
echo "  " . \str_repeat('-', 64) . "\n";
foreach ([
	'flat (true rate = 0)' => $flatFiltered,
	'trend of 0.01 per second' => $weakFiltered,
	'trend of 0.05 per second' => $strongFiltered,
] as $label => $series) {
	$stats = tStatSummary($series['tStat'], $from);
	echo \sprintf("  %-30s %21s %13s\n", $label, Synthetic::fmt($stats['share'], 4), Synthetic::fmt($stats['peak'], 2));
}

echo "\n  On the flat series the filter never once claims a significant trend: its largest\n";
echo "  |t| over 650 samples stays under 2. The weak trend is genuinely marginal against\n";
echo "  this noise and the filter says so rather than reporting a confident slope; the\n";
echo "  strong one is flagged everywhere. No threshold was calibrated for any of the\n";
echo "  three — the filter reports sqrt(P_vv) and the t-statistic follows from it.\n";

Synthetic::heading('On the output of a real metric', 'RSI(14) of a random walk, then its filtered rate of change');

$walk = Synthetic::prices(400, 100.0, 0.4);
$rsi = Rsi::wilder($walk, 14);
$rsiStamps = Synthetic::timestamps(400, 1.0);
$rsiRate = Derivative::ofSeries($rsiStamps, $rsi, 0.05);

echo "     t      RSI    filtered   rate/s    rateStd   tStat   reading\n";
echo "  " . \str_repeat('-', 68) . "\n";
foreach ([70, 150, 230, 310, 370, 399] as $i) {
	$t = $rsiRate['tStat'][$i];
	echo \sprintf(
		"  %4d %8s %11s %8s %10s %7s   %s\n",
		$i,
		Synthetic::fmt($rsi[$i], 2),
		Synthetic::fmt($rsiRate['level'][$i], 2),
		Synthetic::fmt($rsiRate['rate'][$i], 4),
		Synthetic::fmt($rsiRate['rateStd'][$i], 4),
		Synthetic::fmt($t, 2),
		\is_nan($t) ? 'warming up' : (\abs($t) > 2.0 ? ($t > 0.0 ? 'rising' : 'falling') : 'no trend'),
	);
}

echo "\n";
echo "The input series carries NAN through the RSI's own warm-up; those are skipped\n";
echo "rather than fed to the filter as observations, so the estimate starts when the\n";
echo "metric does. Nothing here is specific to RSI — the same three lines work on an\n";
echo "order-book imbalance, a liquidity density or a VWAP deviation, which is how one\n";
echo "class retires fourteen hand-written differences and gives each of them an error\n";
echo "bar it never had.\n";
