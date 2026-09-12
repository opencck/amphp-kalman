<?php declare(strict_types=1);

/**
 * Market strength index (M-26, the reference MSI) — breadth and momentum
 * combined into one reading of how strong a move actually is.
 *
 * Run: php examples/cross-market-strength.php
 *
 * The reference supplies no formula for this indicator, so the definition here
 * is this library's own and the example says so. What it demonstrates is why
 * breadth is worth measuring separately: a market where one name carries the
 * index is a different market from one where everything rises together, and
 * the index level alone cannot tell them apart.
 */

require __DIR__ . '/bootstrap.php';

use OpenCCK\Kalman\Domain\Metric\Cross\MarketStrength;
use OpenCCK\Kalman\Examples\Support\Synthetic;

Synthetic::heading(
	'Market strength — breadth and momentum',
	'Two markets with the SAME average return, reached in two different ways.',
);

$steps = 400;
$window = 60;

// Broad advance: every name drifts up a little.
$broad = [
	'A' => Synthetic::prices($steps, 100.0, 0.05, 0.02, 301),
	'B' => Synthetic::prices($steps, 100.0, 0.05, 0.02, 302),
	'C' => Synthetic::prices($steps, 100.0, 0.05, 0.02, 303),
	'D' => Synthetic::prices($steps, 100.0, 0.05, 0.02, 304),
];

// Narrow advance: one name carries the whole move, the rest drift down.
$narrow = [
	'A' => Synthetic::prices($steps, 100.0, 0.05, 0.08, 301),
	'B' => Synthetic::prices($steps, 100.0, 0.05, -0.0067, 302),
	'C' => Synthetic::prices($steps, 100.0, 0.05, -0.0067, 303),
	'D' => Synthetic::prices($steps, 100.0, 0.05, -0.0067, 304),
];

$sizes = [];
foreach (['A', 'B', 'C', 'D'] as $symbol) {
	$sizes[$symbol] = \array_fill(0, $steps, 100.0);
}

$broadResult = MarketStrength::compute($broad, $sizes, $window, 0.5, 'equal');
$narrowResult = MarketStrength::compute($narrow, $sizes, $window, 0.5, 'equal');

/** @param array<string, list<float>> $series */
function averageReturn(array $series, int $symbols, int $steps, int $window): float
{
	$sum = 0.0;
	foreach ($series as $column) {
		$sum += \log($column[$steps - 1] / $column[$steps - 1 - $window]);
	}
	return $sum / $symbols;
}

\printf(
	"Average constituent return over the last %d steps:  broad %+.4f   narrow %+.4f\n\n",
	$window,
	averageReturn($broad, 4, $steps, $window),
	averageReturn($narrow, 4, $steps, $window),
);

echo "step |        broad market         |        narrow market\n";
echo "     |   MSI  breadth  momentum    |   MSI  breadth  momentum\n";
echo "-----+-----------------------------+-----------------------------\n";
for ($i = 340; $i < 400; $i += 6) {
	\printf(
		"%4d | %6s %8s %9s   | %6s %8s %9s\n",
		$i,
		Synthetic::fmt($broadResult['msi'][$i], 3),
		Synthetic::fmt($broadResult['breadth'][$i], 3),
		Synthetic::fmt($broadResult['momentum'][$i], 3),
		Synthetic::fmt($narrowResult['msi'][$i], 3),
		Synthetic::fmt($narrowResult['breadth'][$i], 3),
		Synthetic::fmt($narrowResult['momentum'][$i], 3),
	);
}

/** @param array<string, list<float>> $series */
function risingCount(array $series, int $steps, int $window): int
{
	$count = 0;
	foreach ($series as $column) {
		if ($column[$steps - 1] > $column[$steps - 1 - $window]) {
			$count++;
		}
	}
	return $count;
}

\printf(
	"\nConstituents up over the window:  broad %d of 4,  narrow %d of 4\n",
	risingCount($broad, $steps, $window),
	risingCount($narrow, $steps, $window),
);
\printf(
	"Breadth at the last step:         broad %+.3f,  narrow %+.3f\n",
	MarketStrength::breadth($broad, $window)[$steps - 1],
	MarketStrength::breadth($narrow, $window)[$steps - 1],
);

echo <<<TEXT

Both markets are up over the window, and by a comparable amount, so an index
built on the average return alone reports much the same thing twice. Breadth
separates them: in the broad market all four constituents are higher than they
were and breadth pins at +1, while in the narrow market it oscillates between
−0.5 and 0 — only one or two names are participating at any moment, and the
advance is one instrument dragging the average.

That distinction is the whole reason to compute this. A rally carried by one
name is fragile: there is nothing to rotate into when it stalls. A rally where
everything participates is not. The composite blends breadth with a
volatility-standardised momentum term, passed through tanh first, so that a
single large print cannot swamp a term that is bounded by construction.

Definition note, stated because the alternative is misleading the reader: the
reference catalogue lists MSI with a name and no formula. Everything here —
the breadth mapping, the standardised momentum, the tanh scaling and the blend
weight — is this library's construction, documented in the class. If your
organisation already has an MSI, check it against this one before assuming the
numbers are comparable.

TEXT;
