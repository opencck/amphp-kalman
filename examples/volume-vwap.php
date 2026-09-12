<?php declare(strict_types=1);

/**
 * VWAP, in both of the indicators that share the name, with bands (M-15).
 *
 *   php examples/volume-vwap.php
 *
 * `Domain\Metric\Volume\Vwap` separates two things the reference conflates:
 * the *anchored* VWAP, which restarts at a session boundary and is the
 * execution benchmark institutions are measured against, and the *rolling*
 * VWAP, which never resets and is a mean-reversion reference. They are not
 * interchangeable. The bands are what turn either one from a level into a
 * signal, and the reference has none.
 */

use OpenCCK\Kalman\Domain\Metric\Volume\Vwap;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/**
 * Right-aligns a formatted number by display width. `sprintf('%9s', ...)` pads
 * by *bytes*, and the em-dash `Synthetic::fmt()` prints for NAN is three of
 * them, so a NAN cell would sit two characters left of every other row.
 */
function vwapCell(float $value, int $decimals, int $width): string
{
	$text = \ltrim(Synthetic::fmt($value, $decimals));
	$fill = $width - \mb_strlen($text);
	return ($fill > 0 ? \str_repeat(' ', $fill) : '') . $text;
}

$tape = Synthetic::trades(600, 100.0, 0.70);
$sessionBreak = 300;

$anchored = Vwap::anchored(2.0);
$rolling = Vwap::rolling(100, 2.0);
$session = Vwap::anchored(2.0);

// Each captured row is
// [index, price, anchored, rolling, rollSigma, rollLower, rollUpper, rollZ,
//  sessionVwap, sessionSigma, sessionZ] — plain floats, because `values()` is
// declared array<string, float> and guarantees no particular key.
/** @var list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float, 8: float, 9: float, 10: float}> $rows */
$rows = [];
$finalAnchored = \NAN;
$finalRolling = \NAN;
foreach ($tape as $i => $trade) {
	if ($i === $sessionBreak) {
		// The session opens: yesterday's accumulation is not today's benchmark.
		$session->anchor($trade->timestampNs);
	}
	$anchored->updateTrade($trade);
	$rolling->updateTrade($trade);
	$session->updateTrade($trade);
	if ($i % 60 !== 59 && $i !== $sessionBreak && $i !== $sessionBreak + 1 && $i !== $sessionBreak + 9) {
		continue;
	}
	$a = $anchored->values();
	$r = $rolling->values();
	$s = $session->values();
	$finalAnchored = $a['vwap'] ?? \NAN;
	$finalRolling = $r['vwap'] ?? \NAN;
	$rows[] = [
		$i,
		$trade->price,
		$finalAnchored,
		$finalRolling,
		$r['sigma'] ?? \NAN,
		$r['lower'] ?? \NAN,
		$r['upper'] ?? \NAN,
		$r['z'] ?? \NAN,
		$s['vwap'] ?? \NAN,
		$s['sigma'] ?? \NAN,
		$s['z'] ?? \NAN,
	];
}

Synthetic::heading(
	'VWAP: anchored versus rolling (M-15)',
	'600 trades, buyer-dominated so the price drifts up; rolling window = 100 trades',
);

echo "  trade     price   anchored    rolling   roll_sig  roll_low  roll_high   roll_z\n";
echo "  " . \str_repeat('-', 78) . "\n";
foreach ($rows as [$i, $price, $anchoredVwap, $rollVwap, $rollSigma, $rollLower, $rollUpper, $rollZ]) {
	echo \sprintf(
		"  %5d  %8s  %9s  %9s  %9s  %8s  %9s  %7s\n",
		$i,
		Synthetic::fmt($price, 4),
		Synthetic::fmt($anchoredVwap, 4),
		Synthetic::fmt($rollVwap, 4),
		Synthetic::fmt($rollSigma, 4),
		Synthetic::fmt($rollLower, 4),
		Synthetic::fmt($rollUpper, 4),
		vwapCell($rollZ, 2, 7),
	);
}

$gap = $finalAnchored - $finalRolling;

echo "\n";
echo \sprintf(
	"At the end of the tape anchored - rolling = %s in price — the anchored figure is\n",
	Synthetic::fmt($gap, 4),
);
echo "still dragged down by the opening trades, while the rolling one has forgotten\n";
echo "them. Quoting one and meaning the other is a real error, not a nuance.\n";

Synthetic::heading(
	'anchor() at a session boundary',
	\sprintf('the same anchored metric, re-anchored at trade %d', $sessionBreak),
);

echo "  trade     price   continuous    session      sigma          z\n";
echo "  " . \str_repeat('-', 62) . "\n";
foreach ($rows as [$i, $price, $anchoredVwap, , , , , , $sessionVwap, $sessionSigma, $sessionZ]) {
	if ($i < $sessionBreak - 61 || $i > $sessionBreak + 130) {
		continue;
	}
	echo \sprintf(
		"  %5d  %8s  %11s  %9s  %9s  %9s%s\n",
		$i,
		Synthetic::fmt($price, 4),
		Synthetic::fmt($anchoredVwap, 4),
		Synthetic::fmt($sessionVwap, 4),
		Synthetic::fmt($sessionSigma, 4),
		vwapCell($sessionZ, 2, 9),
		$i === $sessionBreak ? '   <- anchor()' : '',
	);
}

echo "\n";
echo "At the anchor the session VWAP jumps to the first trade of the new session and\n";
echo "its dispersion collapses to zero: one trade has no spread around itself. It then\n";
echo "rebuilds from the session's own flow, which is the point — VWAP acts as support\n";
echo "because everyone who bought *today* is above water only above today's average,\n";
echo "and an accumulation that never resets does not say that about anybody.\n";
echo "The z column is the tradable form: a level plus a dispersion is a signal, a\n";
echo "level on its own is a line on a chart.\n";
