<?php declare(strict_types=1);

/**
 * Basket return (M-24, the reference ADC) — the market factor a single
 * instrument is measured against.
 *
 * Run: php examples/cross-basket-return.php
 *
 * Shows the three weightings and why the reference's equal weighting is the
 * weakest of them: it hands the same influence to the noisiest constituent as
 * to the steadiest one.
 */

require __DIR__ . '/bootstrap.php';

use OpenCCK\Kalman\Domain\Metric\Cross\BasketReturn;
use OpenCCK\Kalman\Domain\Metric\Cross\BasketWeighting;
use OpenCCK\Kalman\Examples\Support\Synthetic;

Synthetic::heading(
	'Basket return — three weightings',
	'Four constituents: two calm, one volatile, one thin. All drift +0.02 per step.',
);

$steps = 400;
$series = [
	'CALM_A' => Synthetic::prices($steps, 100.0, 0.05, 0.02, 101),
	'CALM_B' => Synthetic::prices($steps, 50.0, 0.03, 0.01, 102),
	'WILD_C' => Synthetic::prices($steps, 10.0, 0.60, 0.002, 103),
	'THIN_D' => Synthetic::prices($steps, 5.0, 0.40, 0.001, 104),
];

// Traded size: the calm names trade heavily, the wild and thin ones barely.
$sizes = [
	'CALM_A' => \array_fill(0, $steps, 1000.0),
	'CALM_B' => \array_fill(0, $steps, 800.0),
	'WILD_C' => \array_fill(0, $steps, 20.0),
	'THIN_D' => \array_fill(0, $steps, 5.0),
];

$equal = BasketReturn::equalWeight($series);
$volume = BasketReturn::volumeWeighted($series, $sizes, 60);
$inverseVol = BasketReturn::inverseVolWeighted($series, 60);

/** @param list<float> $values */
function basketDispersion(array $values): float
{
	/** @var list<float> $clean */
	$clean = [];
	foreach ($values as $value) {
		if (!\is_nan($value)) {
			$clean[] = $value;
		}
	}
	$n = \count($clean);
	if ($n < 2) {
		return \NAN;
	}
	$mean = \array_sum($clean) / $n;
	$sum = 0.0;
	foreach ($clean as $value) {
		$sum += ($value - $mean) ** 2;
	}
	return \sqrt($sum / ($n - 1));
}

echo "step |  equal-weight |  volume-weight | inverse-vol\n";
echo "-----+---------------+----------------+------------\n";
for ($i = 360; $i < 400; $i += 5) {
	\printf(
		"%4d | %13s | %14s | %11s\n",
		$i,
		Synthetic::fmt($equal[$i], 6),
		Synthetic::fmt($volume[$i], 6),
		Synthetic::fmt($inverseVol[$i], 6),
	);
}

echo "\nStandard deviation of the basket return, by weighting:\n";
\printf("  equal weight       %.6f\n", basketDispersion($equal));
\printf("  volume weight      %.6f\n", basketDispersion($volume));
\printf("  inverse volatility %.6f\n", basketDispersion($inverseVol));

// Weights actually in force at the end of the sample.
$metric = new BasketReturn(\array_keys($series), BasketWeighting::InverseVolatility, 60);
for ($i = 0; $i < $steps; $i++) {
	/** @var array<string, float> $prices */
	$prices = [];
	/** @var array<string, float> $step */
	$step = [];
	foreach ($series as $symbol => $column) {
		$prices[$symbol] = $column[$i];
		$step[$symbol] = $sizes[$symbol][$i] ?? 0.0;
	}
	$metric->update($i * 1_000_000_000, $prices, $step);
}

echo "\nInverse-volatility weights at the end of the sample:\n";
foreach ($metric->weights() as $symbol => $weight) {
	\printf("  %-7s %6.2f %%\n", $symbol, $weight * 100.0);
}

echo <<<TEXT

All four names drift upward, so every weighting reports a positive basket
return. What differs is how much noise comes with it. Equal weighting gives
WILD_C — a ten-dollar name with twelve times the volatility of CALM_A — the
same 25 % of the factor as the instrument that carries the market, so the
"market" return it produces is largely that one name's noise. Inverse-volatility
weighting pushes the volatile names down to a few per cent and produces the
steadiest estimate of the same underlying drift.

That matters because this number is the regressor in every hedge: a noisy
market factor produces a noisy beta, and a noisy beta hedges nothing. The
reference formula also selects its basket with an exclusion regex over ticker
names, which means a newly listed ticker joins the market factor the moment it
appears, without anyone deciding that it should. This class takes an explicit
list and throws when a constituent is missing from an update.

TEXT;
