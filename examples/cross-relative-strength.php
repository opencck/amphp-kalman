<?php declare(strict_types=1);

/**
 * Relative strength (M-25, the reference DD) — an asset's return in excess of
 * the market.
 *
 * Run: php examples/cross-relative-strength.php
 *
 * The point of the example: the plain difference the reference computes is not
 * market-neutral, and a high-beta instrument shows spurious "strength" in a
 * rising market purely because of its leverage.
 */

require __DIR__ . '/bootstrap.php';

use OpenCCK\Kalman\Domain\Metric\Cross\RelativeStrength;
use OpenCCK\Kalman\Examples\Support\Synthetic;

Synthetic::heading(
	'Relative strength — plain versus beta-adjusted',
	'LEVER is a pure 2x tracker of the basket. It has no skill and no alpha.',
);

$steps = 600;
$marketA = Synthetic::prices($steps, 100.0, 0.15, 0.03, 201);
$marketB = Synthetic::prices($steps, 80.0, 0.12, 0.024, 202);

// A constituent-weighted market path, then an asset that is exactly twice it.
$basket = ['MKT_A' => $marketA, 'MKT_B' => $marketB];
$lever = [100.0];
for ($i = 1; $i < $steps; $i++) {
	$marketReturn = 0.5 * (\log($marketA[$i] / $marketA[$i - 1]) + \log($marketB[$i] / $marketB[$i - 1]));
	$lever[] = $lever[$i - 1] * \exp(2.0 * $marketReturn);
}

$excess = RelativeStrength::excess($lever, $basket);
$adjusted = RelativeStrength::betaAdjusted($lever, $basket, 60);

$assetReturns = [];
$basketReturns = [];
for ($i = 0; $i < $steps; $i++) {
	$assetReturns[] = $i === 0 ? \NAN : \log($lever[$i] / $lever[$i - 1]);
	$basketReturns[] = $i === 0
		? \NAN
		: 0.5 * (\log($marketA[$i] / $marketA[$i - 1]) + \log($marketB[$i] / $marketB[$i - 1]));
}
$beta = RelativeStrength::rollingBeta($assetReturns, $basketReturns, 60);

echo "step | market ret | plain excess | beta-adjusted |   beta\n";
echo "-----+------------+--------------+---------------+-------\n";
for ($i = 540; $i < 600; $i += 6) {
	\printf(
		"%4d | %10s | %12s | %13s | %6s\n",
		$i,
		Synthetic::fmt($basketReturns[$i], 5),
		Synthetic::fmt($excess[$i], 5),
		Synthetic::fmt($adjusted[$i], 5),
		Synthetic::fmt($beta[$i], 3),
	);
}

/**
 * @param list<float> $values
 * @return array{0: float, 1: float} mean and standard deviation of the finite entries
 */
function excessSummary(array $values): array
{
	/** @var list<float> $clean */
	$clean = [];
	foreach ($values as $value) {
		if (!\is_nan($value)) {
			$clean[] = $value;
		}
	}
	$n = \count($clean);
	$mean = \array_sum($clean) / $n;
	$sum = 0.0;
	foreach ($clean as $value) {
		$sum += ($value - $mean) ** 2;
	}
	return [$mean, \sqrt($sum / \max($n - 1, 1))];
}

[$excessMean, $excessSd] = excessSummary($excess);
[$adjustedMean, $adjustedSd] = excessSummary($adjusted);
/**
 * @param list<float> $a
 * @param list<float> $b
 */
function correlationOf(array $a, array $b): float
{
	/** @var list<array{0: float, 1: float}> $pairs */
	$pairs = [];
	foreach ($a as $i => $x) {
		if (!\is_nan($x) && isset($b[$i]) && !\is_nan($b[$i])) {
			$pairs[] = [$x, $b[$i]];
		}
	}
	$n = \count($pairs);
	$meanA = 0.0;
	$meanB = 0.0;
	foreach ($pairs as [$x, $y]) {
		$meanA += $x / $n;
		$meanB += $y / $n;
	}
	$cov = 0.0;
	$varA = 0.0;
	$varB = 0.0;
	foreach ($pairs as [$x, $y]) {
		$cov += ($x - $meanA) * ($y - $meanB);
		$varA += ($x - $meanA) ** 2;
		$varB += ($y - $meanB) ** 2;
	}
	return $varA > 0.0 && $varB > 0.0 ? $cov / \sqrt($varA * $varB) : \NAN;
}

echo "\n                       mean       sd    correlation with the market\n";
\printf("  plain excess     %9.6f %8.6f   %+.3f\n", $excessMean, $excessSd, correlationOf($excess, $basketReturns));
\printf("  beta-adjusted    %9.6f %8.6f   %+.3f\n", $adjustedMean, $adjustedSd, correlationOf($adjusted, $basketReturns));
\printf("\n  estimated beta at the end of the sample: %.4f (true 2.0)\n", $beta[$steps - 1]);

echo <<<TEXT

LEVER holds no view. It is the market, twice, and nothing else. Yet the plain
difference r_asset − r_basket reports a positive average and correlates almost
perfectly with the market, because the leverage leaves one market return behind
in the residual: 2r − r = r. Rank instruments on that number in a bull market
and you will rank them by beta, then be surprised when the "strong" ones fall
fastest.

Subtracting beta times the market removes it. The residual has essentially zero
mean, essentially zero correlation with the market, and a standard deviation an
order of magnitude smaller — that is what market-neutral means, measured rather
than asserted.

The rolling regression used here estimates one beta per window and knows
nothing about how fast beta itself moves. The library's TimeVaryingBeta model
treats it as a filter state instead, giving a beta per tick with a confidence
band around it, which is both faster to react and honest about its own
uncertainty. See docs/models/time-varying-beta.md.

TEXT;
