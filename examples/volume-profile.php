<?php declare(strict_types=1);

/**
 * Volume, standardised, and the buy/sell split inside it (M-14).
 *
 *   php examples/volume-profile.php
 *
 * `Domain\Metric\Volume\VolumeProfile` reads the trade tape. The reference it
 * replaces summed a *sampled volume gauge*, which measures the scrape interval
 * rather than the market. Reading executions removes the question, and it is
 * also the only way to get the buy/sell split — no sampled gauge can
 * reconstruct who was the aggressor.
 */

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Metric\Volume\VolumeProfile;
use OpenCCK\Kalman\Examples\Support\Synthetic;

require __DIR__ . '/bootstrap.php';

/**
 * Runs a tape through the metric and captures its outputs at chosen indices.
 *
 * A captured row is [index, volume, normalized, z, buyVolume, sellVolume,
 * imbalance]. The Metric contract declares `values()` as `array<string, float>`,
 * so no key is statically guaranteed and each is read with a coalesce.
 *
 * @param list<Trade> $tape
 * @param list<int> $at
 * @return list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}>
 */
function volumeProfileRows(array $tape, array $at, int $window, int $reference): array
{
	$metric = new VolumeProfile($window, $reference);
	$wanted = \array_flip($at);
	$rows = [];
	foreach ($tape as $i => $trade) {
		$metric->updateTrade($trade);
		if (!isset($wanted[$i])) {
			continue;
		}
		$v = $metric->values();
		$rows[] = [
			$i,
			$v['volume'] ?? \NAN,
			$v['normalized'] ?? \NAN,
			$v['z'] ?? \NAN,
			$v['buyVolume'] ?? \NAN,
			$v['sellVolume'] ?? \NAN,
			$v['imbalance'] ?? \NAN,
		];
	}
	return $rows;
}

/**
 * @param list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}> $rows
 */
function volumeProfileTable(array $rows): void
{
	echo "  trade    volume  normalized         z   buyVol  sellVol  imbalance\n";
	echo "  " . \str_repeat('-', 65) . "\n";
	foreach ($rows as [$i, $volume, $normalized, $z, $buyVolume, $sellVolume, $imbalance]) {
		echo \sprintf(
			"  %5d  %8s  %10s  %8s  %7s  %7s  %9s\n",
			$i,
			Synthetic::fmt($volume, 2),
			Synthetic::fmt($normalized, 3),
			Synthetic::fmt($z, 2),
			Synthetic::fmt($buyVolume, 2),
			Synthetic::fmt($sellVolume, 2),
			Synthetic::fmt($imbalance, 3),
		);
	}
}

$window = 20;
$reference = 200;
$sample = [220, 260, 300, 310, 320, 360, 399];

Synthetic::heading(
	'Volume profile (M-14)',
	\sprintf('window = %d trades, reference = %d trades', $window, $reference),
);

echo "Balanced flow — buyShare = 0.5\n\n";
$balanced = Synthetic::trades(400, 100.0, 0.5);
volumeProfileTable(volumeProfileRows($balanced, $sample, $window, $reference));

echo "\nOne-sided flow — buyShare = 0.9, same seed, same sizes\n\n";
$onesided = Synthetic::trades(400, 100.0, 0.9);
volumeProfileTable(volumeProfileRows($onesided, $sample, $window, $reference));

// A volume burst: twenty trades in the middle of the tape carry eight times
// the size. Nothing else about the tape changes.
$burst = [];
foreach ($balanced as $i => $trade) {
	$burst[] = $i >= 300 && $i < 320
		? Trade::at($trade->timestampNs, $trade->price, $trade->size * 8.0, $trade->side)
		: $trade;
}

echo "\nSame balanced tape with an 8x size burst over trades 300-319\n\n";
volumeProfileTable(volumeProfileRows($burst, $sample, $window, $reference));

$peak = 0.0;
$peakZ = 0.0;
foreach (volumeProfileRows($burst, \range(300, 340), $window, $reference) as [, , $normalized, $z]) {
	if ($normalized > $peak) {
		$peak = $normalized;
		$peakZ = $z;
	}
}

echo "\n";
echo \sprintf(
	"The burst peaks at normalized = %s (a window carrying %.1fx its long-run\n",
	Synthetic::fmt($peak, 2),
	$peak,
);
echo \sprintf(
	"expectation) and z = %s, which is the same statement standardised by the\n",
	Synthetic::fmt($peakZ, 1),
);
echo "spread of trade sizes rather than by their mean alone.\n";
echo "Imbalance separates the two tapes cleanly: the balanced tape hovers around\n";
echo "zero while the 0.9 tape sits near +0.8, and neither figure is recoverable\n";
echo "from a volume gauge — the split lives in the executions, not in the total.\n";
echo "Note also that the burst leaves imbalance untouched: size and direction are\n";
echo "independent readings, and a spike with no imbalance is a different event\n";
echo "from a spike with one.\n";
