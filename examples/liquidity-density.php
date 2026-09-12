<?php declare(strict_types=1);

/**
 * Liquidity density and book slope: three defects, fixed (M-17, M-35).
 *
 *   php examples/liquidity-density.php
 *
 * `Domain\Metric\Liquidity\LiquidityDensity` replaces a reference that got
 * three things wrong, and this example reproduces each of them inline:
 *
 *   1. the best quote was read as `array_slice($map, 0, 1)` from a price-keyed
 *      map mutated in place — first by *insertion order*, not by price;
 *   2. the band was an indicator function, so a level at the boundary switched
 *      the whole reading on and off;
 *   3. the result was a bare sum of sizes, which cannot be compared between
 *      two instruments or two days of one instrument.
 *
 * `Liquidity\BookSlope` then answers the question density does not: not how
 * much size is there, but how close to the touch it sits.
 */

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Metric\Liquidity\BookSlope;
use OpenCCK\Kalman\Domain\Metric\Liquidity\DensityKernel;
use OpenCCK\Kalman\Domain\Metric\Liquidity\LiquidityDensity;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/**
 * Builds a symmetric book from a list of [distance in ticks, size] pairs.
 *
 * @param list<array{0: int, 1: float}> $shape
 */
function slopeDemoBook(array $shape): OrderBook
{
	$bids = [];
	$asks = [];
	foreach ($shape as [$step, $size]) {
		$bids[] = [\round(100.0 - 0.01 * $step, 6), $size];
		$asks[] = [\round(100.0 + 0.01 * $step, 6), $size];
	}
	return OrderBook::of(1_700_000_000_000_000_000, $bids, $asks);
}

$book = Synthetic::book(100.0, 20, 0.01);

// The same twenty levels per side, inserted in the order an incremental feed
// happened to deliver them. Price-keyed, exactly as a decoded payload arrives.
/** @var array<string, float> $bidMap */
$bidMap = [];
/** @var array<string, float> $askMap */
$askMap = [];
/** @var list<float> $shuffledBidPrices */
$shuffledBidPrices = [];
/** @var list<float> $shuffledBidSizes */
$shuffledBidSizes = [];
/** @var list<float> $shuffledAskPrices */
$shuffledAskPrices = [];
/** @var list<float> $shuffledAskSizes */
$shuffledAskSizes = [];
$levels = \count($book->bidPrices);
for ($i = 0; $i < $levels; $i++) {
	$b = ($i * 7 + 13) % $levels;
	$a = ($i * 3 + 5) % $levels;
	if (!isset($book->bidPrices[$b], $book->bidSizes[$b], $book->askPrices[$a], $book->askSizes[$a])) {
		continue;
	}
	$bidMap[(string) $book->bidPrices[$b]] = $book->bidSizes[$b];
	$askMap[(string) $book->askPrices[$a]] = $book->askSizes[$a];
	$shuffledBidPrices[] = $book->bidPrices[$b];
	$shuffledBidSizes[] = $book->bidSizes[$b];
	$shuffledAskPrices[] = $book->askPrices[$a];
	$shuffledAskSizes[] = $book->askSizes[$a];
}

Synthetic::heading('Defect 1: the first element of a map is not the best quote', 'the reference line, run verbatim');

// --- verbatim reference ---------------------------------------------------
$firstBid = \array_keys(\array_slice($bidMap, 0, 1, true));
$firstAsk = \array_keys(\array_slice($askMap, 0, 1, true));
$referenceBid = (float) ($firstBid[0] ?? 0.0);
$referenceAsk = (float) ($firstAsk[0] ?? 0.0);
$referenceMid = ($referenceBid + $referenceAsk) / 2.0;
// --------------------------------------------------------------------------

$sorted = OrderBook::fromMap($book->timestampNs, $bidMap, $askMap);

echo \sprintf("  array_slice(\$bidMap, 0, 1)  -> best bid %s   (really %s)\n", Synthetic::fmt($referenceBid, 4), Synthetic::fmt($sorted->bestBid(), 4));
echo \sprintf("  array_slice(\$askMap, 0, 1)  -> best ask %s   (really %s)\n", Synthetic::fmt($referenceAsk, 4), Synthetic::fmt($sorted->bestAsk(), 4));
echo \sprintf("  mid                         -> %s   (really %s)\n", Synthetic::fmt($referenceMid, 4), Synthetic::fmt($sorted->mid(), 4));
echo \sprintf("  spread                      -> %s   (really %s)\n", Synthetic::fmt($referenceAsk - $referenceBid, 4), Synthetic::fmt($sorted->spread(), 4));
echo "\n";
echo \sprintf(
	"  density, insertion-ordered arrays: %s\n  density, OrderBook (sorted)      : %s\n",
	Synthetic::fmt(LiquidityDensity::within($shuffledBidPrices, $shuffledBidSizes, $shuffledAskPrices, $shuffledAskSizes), 3),
	Synthetic::fmt(LiquidityDensity::within($sorted->bidPrices, $sorted->bidSizes, $sorted->askPrices, $sorted->askSizes), 3),
);
echo "\n  Every figure derived from the mid was centred on an arbitrary level, and the\n";
echo "  band scan — which stops at the first level outside the band, because a sorted\n";
echo "  side lets it — stops almost immediately on unsorted input. OrderBook sorts at\n";
echo "  construction, so this class of bug cannot be written.\n";

Synthetic::heading('Defect 2: a rectangular band is a step function', 'the same book, the band boundary swept across the level at 5 bps');

echo "  bandBps   rectangular  step   triangular  step   exponential  step\n";
echo "  " . \str_repeat('-', 68) . "\n";
$density = static function (float $band, DensityKernel $kernel) use ($book): float {
	$metric = new LiquidityDensity($band, $kernel);
	$metric->updateBook($book);
	return $metric->value();
};
$previousRectangular = 0.0;
$previousTriangular = 0.0;
$previousExponential = 0.0;
for ($b = 47; $b <= 53; $b++) {
	$band = $b / 10.0;
	$rectangular = $density($band, DensityKernel::Rectangular);
	$triangular = $density($band, DensityKernel::Triangular);
	$exponential = $density($band, DensityKernel::Exponential);
	echo \sprintf(
		"  %7s   %11s %5s   %10s %5s   %11s %5s\n",
		Synthetic::fmt($band, 1),
		Synthetic::fmt($rectangular, 2),
		$b === 47 ? '-' : Synthetic::fmt($rectangular - $previousRectangular, 2),
		Synthetic::fmt($triangular, 2),
		$b === 47 ? '-' : Synthetic::fmt($triangular - $previousTriangular, 2),
		Synthetic::fmt($exponential, 2),
		$b === 47 ? '-' : Synthetic::fmt($exponential - $previousExponential, 2),
	);
	$previousRectangular = $rectangular;
	$previousTriangular = $triangular;
	$previousExponential = $exponential;
}
echo "\n  The rectangular column is flat, flat, flat, then jumps 10.00 in one 0.1 bp step\n";
echo "  as the level at 5 bps crosses the boundary, then goes flat again. The triangular\n";
echo "  and exponential columns step by a similar small amount on every row: a kink at\n";
echo "  the boundary, never a jump. On a live feed that jump repeats every time the mid\n";
echo "  ticks past a resting order, and it looks exactly like a liquidity event.\n";

Synthetic::heading('Defect 3: a bare sum of sizes has no scale', 'one book on a $100 instrument, one on a $20,000 instrument, identical shape');

$cheap = Synthetic::book(100.0, 20, 0.01);
$dear = Synthetic::book(20000.0, 20, 2.0);
echo "  instrument      raw     share       notional\n";
echo "  " . \str_repeat('-', 48) . "\n";
foreach (['$100' => $cheap, '$20,000' => $dear] as $label => $snapshot) {
	$metric = new LiquidityDensity(10.0, DensityKernel::Rectangular);
	$metric->updateBook($snapshot);
	$v = $metric->values();
	echo \sprintf(
		"  %-10s %8s  %8s  %13s\n",
		$label,
		Synthetic::fmt($v['density'] ?? \NAN, 2),
		Synthetic::fmt($v['share'] ?? \NAN, 4),
		Synthetic::fmt($v['notional'] ?? \NAN, 2),
	);
}
echo "\n  The raw figures are identical and mean nothing next to each other: 110 units of\n";
echo "  a hundred-dollar stock and 110 units of a twenty-thousand-dollar future are not\n";
echo "  the same liquidity. Share is dimensionless and comparable; notional answers the\n";
echo "  question a sizing rule asks — how much value can I lift inside ten bps.\n";

Synthetic::heading('BookSlope: where the depth sits, not how much there is', 'two books with the same 157 units per side, placed differently');

/** @var list<array{0: int, 1: float}> $near */
$near = [];
/** @var list<array{0: int, 1: float}> $far */
$far = [];
for ($i = 1; $i <= 10; $i++) {
	$near[] = [$i, $i <= 3 ? 50.0 : 1.0];
	$far[] = [$i, $i >= 8 ? 50.0 : 1.0];
}

echo "  book                    volume    slope   density(10bp)\n";
echo "  " . \str_repeat('-', 56) . "\n";
foreach (['depth at 1-3 bps' => slopeDemoBook($near), 'depth at 8-10 bps' => slopeDemoBook($far)] as $label => $snapshot) {
	$slope = new BookSlope(10);
	$slope->updateBook($snapshot);
	$density = new LiquidityDensity(10.0, DensityKernel::Rectangular);
	$density->updateBook($snapshot);
	echo \sprintf(
		"  %-20s %8s %8s %14s\n",
		$label,
		Synthetic::fmt($snapshot->bidVolume() + $snapshot->askVolume(), 1),
		Synthetic::fmt($slope->value(), 1),
		Synthetic::fmt($density->value(), 1),
	);
}
echo "\n  Total volume is the same and the ten-basis-point density is the same, because\n";
echo "  both books fit entirely inside the band. The slope is not: depth piled against\n";
echo "  the touch is depth an order actually reaches, and that is what caps its cost.\n";
