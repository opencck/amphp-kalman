<?php declare(strict_types=1);

/**
 * Normalised price (M-01): the same price in three dimensionless forms, and a
 * demonstration of why the reference stack's `ratio` form does not transfer
 * between instruments while the z-score does.
 *
 *   php examples/price-normalized.php
 */

use OpenCCK\Kalman\Domain\Metric\Price\NormalizedPrice;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/price-normalized.php\n";
	exit(0);
}

const PERIOD = 120;

/**
 * Population standard deviation of the finite entries of a series.
 *
 * @param list<float> $values
 */
function spreadOf(array $values): float
{
	$finite = [];
	foreach ($values as $value) {
		if (!\is_nan($value)) {
			$finite[] = $value;
		}
	}
	$n = \count($finite);
	if ($n < 2) {
		return \NAN;
	}
	$mean = \array_sum($finite) / $n;
	$sum = 0.0;
	foreach ($finite as $value) {
		$d = $value - $mean;
		$sum += $d * $d;
	}
	return \sqrt($sum / $n);
}

// Two instruments, same shape, one five times as volatile as the other.
$calm = Synthetic::prices(400, 100.0, 0.2, 0.0, 7);
$wild = Synthetic::prices(400, 100.0, 1.0, 0.0, 7);

Synthetic::heading(
	'NormalizedPrice — z, ratio and log over a 120-observation window',
	'The calm instrument: price, then the same price in the three forms.',
);

$z = NormalizedPrice::zScore($calm, PERIOD);
$ratio = NormalizedPrice::ratio($calm, PERIOD);
$log = NormalizedPrice::logRatio($calm, PERIOD);

\printf("%6s  %9s  %9s  %9s  %9s\n", 'i', 'price', 'z', 'ratio', 'log');
for ($i = 119; $i < 400; $i += 25) {
	\printf(
		"%6d  %9s  %9s  %9s  %9s\n",
		$i,
		Synthetic::fmt($calm[$i], 3),
		Synthetic::fmt($z[$i], 3),
		Synthetic::fmt($ratio[$i], 5),
		Synthetic::fmt($log[$i], 5),
	);
}

Synthetic::heading('Spread of each form, on two instruments of different volatility');

$calmZ = spreadOf(NormalizedPrice::zScore($calm, PERIOD));
$calmRatio = spreadOf(NormalizedPrice::ratio($calm, PERIOD));
$calmLog = spreadOf(NormalizedPrice::logRatio($calm, PERIOD));
$wildZ = spreadOf(NormalizedPrice::zScore($wild, PERIOD));
$wildRatio = spreadOf(NormalizedPrice::ratio($wild, PERIOD));
$wildLog = spreadOf(NormalizedPrice::logRatio($wild, PERIOD));

\printf("%-10s  %8s  %10s  %10s  %10s\n", 'series', 'step σ', 'sd(z)', 'sd(ratio)', 'sd(log)');
\printf("%-10s  %8s  %10s  %10s  %10s\n", 'calm', '0.20', Synthetic::fmt($calmZ, 4), Synthetic::fmt($calmRatio, 6), Synthetic::fmt($calmLog, 6));
\printf("%-10s  %8s  %10s  %10s  %10s\n", 'wild', '1.00', Synthetic::fmt($wildZ, 4), Synthetic::fmt($wildRatio, 6), Synthetic::fmt($wildLog, 6));
\printf(
	"\n%-10s  %8s  %10s  %10s  %10s\n",
	'wild/calm',
	'5.00',
	Synthetic::fmt($wildZ / $calmZ, 4),
	Synthetic::fmt($wildRatio / $calmRatio, 4),
	Synthetic::fmt($wildLog / $calmLog, 4),
);

echo "\n",
	"What to look at: the last row. The two series differ only in their step\n",
	"volatility, by a factor of five. The z-score's spread is identical on both\n",
	"— it has unit scale by construction, so a threshold of \"two sigma\" means\n",
	"the same thing on either instrument. The ratio form's spread scales with\n",
	"the instrument: 0.0047 on the calm one, 0.0232 on the wild one. That is the\n",
	"reference stack's normalisation, and a threshold tuned on one asset is\n",
	"simply wrong on the other.\n\n",
	"The ratio form has a second problem visible in the first table: every value\n",
	"sits within a few thousandths of 1.0, so as a network input it is a\n",
	"constant plus a rounding error. The log form fixes the centring (it is\n",
	"symmetric around zero and additive over time) but keeps the scale problem;\n",
	"only the z-score fixes both.\n";
