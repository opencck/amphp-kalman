<?php declare(strict_types=1);

/**
 * Moving averages (M-10): the four weightings side by side, their centres of
 * mass, and the time-based exponential average on an irregular tick stream —
 * where a fixed alpha lets the averaging horizon breathe with the tick rate.
 *
 *   php examples/trend-moving-averages.php
 */

use OpenCCK\Kalman\Domain\Metric\Trend\MaType;
use OpenCCK\Kalman\Domain\Metric\Trend\MovingAverage;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/trend-moving-averages.php\n";
	exit(0);
}

const PERIOD = 20;
const TAU_SECONDS = 30.0;

$prices = Synthetic::prices(300, 100.0, 0.4, 0.02, 7);
$sma = MovingAverage::sma($prices, PERIOD);
$ema = MovingAverage::ema($prices, PERIOD);
$rma = MovingAverage::rma($prices, PERIOD);
$wma = MovingAverage::wma($prices, PERIOD);

Synthetic::heading(
	'Four weightings of the same 20-observation window',
	'All four are seeded with the simple average of the first 20 values.',
);

\printf("%6s  %9s  %9s  %9s  %9s  %9s\n", 'i', 'price', 'SMA', 'EMA', 'RMA', 'WMA');
for ($i = 30; $i < 300; $i += 25) {
	\printf(
		"%6d  %9s  %9s  %9s  %9s  %9s\n",
		$i,
		Synthetic::fmt($prices[$i], 3),
		Synthetic::fmt($sma[$i], 3),
		Synthetic::fmt($ema[$i], 3),
		Synthetic::fmt($rma[$i], 3),
		Synthetic::fmt($wma[$i], 3),
	);
}

echo "\ncentre of mass at period 20 (observations):";
foreach (MaType::cases() as $type) {
	\printf('  %s=%s', \strtoupper($type->value), Synthetic::fmt($type->centreOfMass(PERIOD), 2));
}
echo "\n";

// ---------------------------------------------------------------- irregular

// One stream, two tick-rate regimes: a burst around 10 ticks a second, then a
// lull around one tick every five seconds. Gaps alternate inside each regime
// so the stream is genuinely irregular, not two evenly spaced halves.
$tickPrices = Synthetic::prices(1800, 100.0, 0.2, 0.0, 7);
$timestamps = [];
$ns = 1_700_000_000_000_000_000;
for ($i = 0; $i < 1800; $i++) {
	$timestamps[] = $ns;
	$gap = $i < 1500 ? ($i % 2 === 0 ? 0.08 : 0.12) : ($i % 2 === 0 ? 4.0 : 6.0);
	$ns += (int) ($gap * 1e9);
}

/**
 * Time for each average to absorb 63.2 % of a unit step injected at `$at`.
 *
 * Both averages are affine in the input, so filtering a shifted copy and
 * subtracting the filtered original isolates the step response exactly — the
 * price noise cancels and what is left is the impulse memory of the filter.
 *
 * @param list<int> $timestampsNs
 * @param list<float> $prices
 * @return array{fixedSeconds: float, fixedTicks: int, timedSeconds: float, timedTicks: int}
 */
function stepResponse(array $timestampsNs, array $prices, int $at): array
{
	$shifted = [];
	foreach ($prices as $i => $price) {
		$shifted[] = $i >= $at ? $price + 1.0 : $price;
	}
	$n = \count($prices);
	$baseFixed = MovingAverage::ema($prices, PERIOD);
	$stepFixed = MovingAverage::ema($shifted, PERIOD);
	$baseTimed = MovingAverage::emaTimed($timestampsNs, $prices, TAU_SECONDS);
	$stepTimed = MovingAverage::emaTimed($timestampsNs, $shifted, TAU_SECONDS);

	$out = ['fixedSeconds' => \NAN, 'fixedTicks' => 0, 'timedSeconds' => \NAN, 'timedTicks' => 0];
	for ($i = $at; $i < $n; $i++) {
		$elapsed = ($timestampsNs[$i] - $timestampsNs[$at - 1]) / 1e9;
		if (\is_nan($out['fixedSeconds']) && $stepFixed[$i] - $baseFixed[$i] >= 0.632120558) {
			$out['fixedSeconds'] = $elapsed;
			$out['fixedTicks'] = $i - $at + 1;
		}
		if (\is_nan($out['timedSeconds']) && $stepTimed[$i] - $baseTimed[$i] >= 0.632120558) {
			$out['timedSeconds'] = $elapsed;
			$out['timedTicks'] = $i - $at + 1;
		}
	}
	return $out;
}

$burst = stepResponse($timestamps, $tickPrices, 400);
$lull = stepResponse($timestamps, $tickPrices, 1600);

Synthetic::heading(
	'Effective horizon on an irregular stream, measured from the step response',
	'Time to absorb 63.2 % of a price step: EMA(20) by tick count vs EMA(tau = 30 s).',
);

\printf("%-24s  %12s  %10s  %12s  %10s\n", 'regime', 'EMA(20) s', 'ticks', 'timed 30s', 'ticks');
\printf(
	"%-24s  %12s  %10d  %12s  %10d\n",
	'burst, ~0.1 s per tick',
	Synthetic::fmt($burst['fixedSeconds'], 2),
	$burst['fixedTicks'],
	Synthetic::fmt($burst['timedSeconds'], 2),
	$burst['timedTicks'],
);
\printf(
	"%-24s  %12s  %10d  %12s  %10d\n",
	'lull, ~5 s per tick',
	Synthetic::fmt($lull['fixedSeconds'], 2),
	$lull['fixedTicks'],
	Synthetic::fmt($lull['timedSeconds'], 2),
	$lull['timedTicks'],
);
\printf(
	"%-24s  %12s  %10s  %12s  %10s\n",
	'lull / burst',
	Synthetic::fmt($lull['fixedSeconds'] / $burst['fixedSeconds'], 2),
	'',
	Synthetic::fmt($lull['timedSeconds'] / $burst['timedSeconds'], 2),
	'',
);

echo "\n",
	"What to look at: the last row. The same EMA(20) object spans one second of\n",
	"market time in the burst and fifty seconds in the lull — a fiftyfold change\n",
	"in what the indicator actually measures, caused by nothing but the arrival\n",
	"rate of ticks. Its tick count is constant at ten because that is all a fixed\n",
	"alpha can hold constant. The time-based average takes 30 seconds in both\n",
	"regimes, which is what tau asked for; its tick count moves instead (from\n",
	"about 300 ticks down to 6), which is the right thing to let vary.\n\n",
	"The centre-of-mass line above is the honest way to compare the four\n",
	"weightings: RMA(20) is not a 20-observation average at all, it sits at 19\n",
	"observations, the same place as an EMA(39). Substituting one family for\n",
	"another at the same period silently doubles or halves the horizon.\n";
