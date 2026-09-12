<?php declare(strict_types=1);

/**
 * Order-flow imbalance: every way the top of book can change, signed (M-29).
 *
 *   php examples/micro-ofi.php
 *
 * `Domain\Metric\Microstructure\OrderFlowImbalance` counts a new order, a
 * cancellation and an execution at the best quotes with the right sign, in one
 * number. Cont, Kukanov and Stoikov's finding is that its relationship to the
 * price change is linear with a slope inversely proportional to depth, which
 * is why the metric reports `impliedMove = OFI / (2·depth)` and not just the
 * raw flow. OFI is a flow, so it also depends on the feed's update rate:
 * comparing it across venues without matching windows is meaningless.
 */

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Metric\Microstructure\OrderFlowImbalance;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

Synthetic::heading(
	'The four ways the top of book can change (M-29)',
	'from bid 99.99 x 10, ask 100.01 x 10 — one change at a time',
);

$cases = [
	'bid improves to 100.00 x 8' => [100.00, 8.0, 100.01, 10.0],
	'bid pulled back to 99.98 x 10' => [99.98, 10.0, 100.01, 10.0],
	'ask improves to 100.00 x 8' => [99.99, 10.0, 100.00, 8.0],
	'ask pulled back to 100.02 x 10' => [99.99, 10.0, 100.02, 10.0],
	'bid size grows 10 -> 15, same price' => [99.99, 15.0, 100.01, 10.0],
	'ask size grows 10 -> 15, same price' => [99.99, 10.0, 100.01, 15.0],
	'10 lots lifted from the ask' => [99.99, 10.0, 100.01, 0.5],
];

echo "  event                                  new bid      new ask       e     reads as\n";
echo "  " . \str_repeat('-', 82) . "\n";
foreach ($cases as $label => [$bid, $bidSize, $ask, $askSize]) {
	$e = OrderFlowImbalance::event(99.99, 10.0, 100.01, 10.0, $bid, $bidSize, $ask, $askSize);
	echo \sprintf(
		"  %-36s %6s x%-4s %6s x%-4s %7s   %s\n",
		$label,
		Synthetic::fmt($bid, 2),
		Synthetic::fmt($bidSize, 1),
		Synthetic::fmt($ask, 2),
		Synthetic::fmt($askSize, 1),
		Synthetic::fmt($e, 1),
		$e > 0.0 ? 'buying pressure' : ($e < 0.0 ? 'selling pressure' : 'neutral'),
	);
}

echo "\n  A bid that improves adds its whole size; a bid that is pulled removes the size\n";
echo "  that was standing there; the ask side enters with the opposite sign. A size\n";
echo "  change at an unchanged price nets to the difference, which is the case a\n";
echo "  trade-only measure cannot see at all.\n";

// --- a book stream whose mid is actually driven by the flow ----------------
$steps = 900;
$window = 50;
$depth = 10.0;
$tick = 0.01;
$walk = Synthetic::prices($steps + 1, 0.0, 1.0, 0.0, 29);

$bid = 99.99;
$ask = 100.01;
$bidSize = $depth;
$askSize = $depth;
$ns = 1_700_000_000_000_000_000;
/** @var list<OrderBook> $stream */
$stream = [];
/** @var list<float> $mids */
$mids = [];
for ($i = 0; $i < $steps; $i++) {
	$flow = ($walk[$i + 1] - $walk[$i]) * 4.0;
	$askSize -= $flow;          // buying eats the offer and queues on the bid
	$bidSize += $flow * 0.5;
	while ($askSize <= 0.5) {   // the offer is cleared: the market ticks up
		$bid = \round($bid + $tick, 6);
		$ask = \round($ask + $tick, 6);
		$askSize += $depth;
		$bidSize = $depth;
	}
	while ($bidSize <= 0.5) {
		$bid = \round($bid - $tick, 6);
		$ask = \round($ask - $tick, 6);
		$bidSize += $depth;
		$askSize = $depth;
	}
	$stream[] = OrderBook::of($ns, [[$bid, $bidSize]], [[$ask, $askSize]]);
	$mids[] = ($bid + $ask) / 2.0;
	$ns += 100_000_000;
}

$metric = new OrderFlowImbalance($window);
/** @var list<float> $implied */
$implied = [];
/** @var list<float> $actual */
$actual = [];
/** @var list<array{0: int, 1: float, 2: float, 3: float, 4: float}> $rows */
$rows = [];
foreach ($stream as $i => $book) {
	$metric->updateBook($book);
	$v = $metric->values();
	if (\is_nan($v['ofi']) || $i < $window) {
		continue;
	}
	$move = $mids[$i] - $mids[$i - $window];
	$implied[] = $v['impliedMove'];
	$actual[] = $move / $tick;
	if ($i % 90 === 0) {
		$rows[] = [$i, $v['ofi'], $v['depth'], $v['impliedMove'], $move];
	}
}

Synthetic::heading(
	'Implied move against the move that happened',
	\sprintf('%d book updates, OFI over a %d-update window, mid change over the same window', $steps, $window),
);

echo "  update       OFI    depth   implied move   actual move   actual in ticks\n";
echo "  " . \str_repeat('-', 74) . "\n";
foreach ($rows as [$i, $ofi, $bookDepth, $impliedMove, $move]) {
	echo \sprintf(
		"  %6d %9s %8s %14s %13s %17s\n",
		$i,
		Synthetic::fmt($ofi, 1),
		Synthetic::fmt($bookDepth, 2),
		Synthetic::fmt($impliedMove, 4),
		Synthetic::fmt($move, 4),
		Synthetic::fmt($move / $tick, 2),
	);
}

$n = \count($implied);
$meanX = \array_sum($implied) / $n;
$meanY = \array_sum($actual) / $n;
$sxy = 0.0;
$sxx = 0.0;
$syy = 0.0;
foreach ($implied as $k => $x) {
	$dx = $x - $meanX;
	$dy = $actual[$k] - $meanY;
	$sxy += $dx * $dy;
	$sxx += $dx * $dx;
	$syy += $dy * $dy;
}
$correlation = $sxx > 0.0 && $syy > 0.0 ? $sxy / \sqrt($sxx * $syy) : \NAN;
$slope = $sxx > 0.0 ? $sxy / $sxx : \NAN;
$moved = 0;
$agreed = 0;
foreach ($implied as $k => $x) {
	if ($actual[$k] === 0.0) {
		continue;
	}
	$moved++;
	if ($x * $actual[$k] > 0.0) {
		$agreed++;
	}
}

echo "\n";
echo \sprintf(
	"Over %d overlapping windows the implied move and the realised move correlate at\n",
	$n,
);
echo \sprintf(
	"%s, and where the mid actually moved the implied figure had the right sign %s of\n",
	Synthetic::fmt($correlation, 4),
	Synthetic::fmt($agreed / \max(1, $moved), 3),
);
echo "the time. The relationship is linear, which is the whole claim, and it costs one\n";
echo "subtraction per book update. Note what the depth column does: the same OFI\n";
echo "implies a smaller move when the queues are deep, so the raw flow is not\n";
echo "comparable across venues or across subscription tiers, and the implied move is.\n";
echo "\n";
echo \sprintf(
	"One caveat: regressing the realised move measured *in ticks* on `impliedMove`\ngives a slope of %s — order one. Against the move measured in price it would be\n",
	Synthetic::fmt($slope, 3),
);
echo "about 0.007. So `impliedMove` lives on a tick scale, not on the \"price units of\n";
echo "the instrument\" its docblock promises; scale it by the tick before comparing it\n";
echo "with a price.\n";
