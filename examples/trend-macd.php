<?php declare(strict_types=1);

/**
 * MACD (M-11): Appel's 12/26/9 exponential construction against the reference
 * PromQL expression, which uses flat 8- and 17-sample averages and publishes
 * the histogram alone. Their zero crossings do not happen at the same time.
 *
 *   php examples/trend-macd.php
 */

use OpenCCK\Kalman\Domain\Metric\Trend\Macd;
use OpenCCK\Kalman\Domain\Metric\Trend\MaType;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */
if (\in_array('--help', \array_slice($argv, 1), true)) {
	echo "usage: php examples/trend-macd.php\n";
	exit(0);
}

$prices = Synthetic::regimes(600, 100.0, 0.3, 11);

$canonical = Macd::compute($prices, 12, 26, 9);

$referenceMetric = Macd::referenceEquivalent();
$reference = ['macd' => [], 'signal' => [], 'histogram' => []];
foreach ($prices as $price) {
	$referenceMetric->updatePrice(0, $price);
	$values = $referenceMetric->values();
	$reference['macd'][] = $values['macd'];
	$reference['signal'][] = $values['signal'];
	$reference['histogram'][] = $values['histogram'];
}

/**
 * Indices where a series changes sign.
 *
 * @param list<float> $series
 * @return list<int>
 */
function zeroCrossings(array $series): array
{
	$out = [];
	$previous = \NAN;
	foreach ($series as $i => $value) {
		if (!\is_nan($previous) && !\is_nan($value) && (($previous <= 0.0 && $value > 0.0) || ($previous >= 0.0 && $value < 0.0))) {
			$out[] = $i;
		}
		$previous = $value;
	}
	return $out;
}

Synthetic::heading(
	'MACD 12/26/9 on EMAs against the reference 8/17/9 on flat averages',
	'All three outputs of each; the reference expression publishes only "hist".',
);

\printf("%5s  %8s  %8s %8s %8s  %8s %8s %8s\n", 'i', 'price', 'macd', 'signal', 'hist', 'ref', 'refSig', 'refHist');
for ($i = 200; $i < 560; $i += 30) {
	\printf(
		"%5d  %8s  %8s %8s %8s  %8s %8s %8s\n",
		$i,
		Synthetic::fmt($prices[$i], 2),
		Synthetic::fmt($canonical['macd'][$i], 3),
		Synthetic::fmt($canonical['signal'][$i], 3),
		Synthetic::fmt($canonical['histogram'][$i], 3),
		Synthetic::fmt($reference['macd'][$i], 3),
		Synthetic::fmt($reference['signal'][$i], 3),
		Synthetic::fmt($reference['histogram'][$i], 3),
	);
}

$canonicalZeros = zeroCrossings($canonical['macd']);
$referenceZeros = zeroCrossings($reference['macd']);

\printf(
	"\nMACD line crosses zero at: %s  (%d times)\n",
	\implode(' ', \array_map(static fn (int $i): string => (string) $i, \array_slice($canonicalZeros, 0, 12))),
	\count($canonicalZeros),
);
\printf(
	"reference crosses zero at: %s  (%d times)\n",
	\implode(' ', \array_map(static fn (int $i): string => (string) $i, \array_slice($referenceZeros, 0, 12))),
	\count($referenceZeros),
);
$shared = \array_values(\array_intersect($canonicalZeros, $referenceZeros));
\printf("indices shared by both: %d\n", \count($shared));

\printf(
	"\ncentres of mass (observations): EMA(12) = %s, SMA(8) = %s | EMA(26) = %s, SMA(17) = %s\n",
	Synthetic::fmt(MaType::Ema->centreOfMass(12), 2),
	Synthetic::fmt(MaType::Sma->centreOfMass(8), 2),
	Synthetic::fmt(MaType::Ema->centreOfMass(26), 2),
	Synthetic::fmt(MaType::Sma->centreOfMass(17), 2),
);

echo "\n",
	"What to look at: the two crossing lists. The MACD line crossing zero is the\n",
	"moment the fast average overtakes the slow one, and it is one of the three\n",
	"rules the indicator exists for. The two constructions place that moment at\n",
	"different indices and disagree even on how many times it happened — not one\n",
	"index is shared. A rule calibrated on a chart and a rule fired from the\n",
	"reference dashboard are not the same rule.\n\n",
	"The centre-of-mass line says why. An SMA over 8 samples sits where an EMA of\n",
	"period 8 sits, at 3.5 observations, against 5.5 for the EMA(12) the\n",
	"indicator is defined on; the slow leg is 8.0 against 12.5. The reference is\n",
	"roughly two thirds as long on both legs, so it turns earlier and more often.\n\n",
	"And the columns: the reference expression collapses the algebra down to the\n",
	"histogram, which is the rightmost column only. Neither the MACD line nor the\n",
	"signal line survives it, so two of the three trading rules — the zero\n",
	"crossing and the signal crossing — cannot be evaluated at all downstream.\n";
