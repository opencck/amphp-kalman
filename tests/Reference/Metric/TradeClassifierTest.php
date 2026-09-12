<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Microstructure\TradeClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Trade classification (M-32) against naive implementations of the three rules.
 *
 * Lee–Ready is a quote rule with a tick-rule fallback, and the fallback is
 * where implementations diverge: a trade exactly at the mid is not "unknown",
 * it inherits the direction of the last classified trade, and the very first
 * trade of all — with neither a quote nor a predecessor — carries the zero it
 * started with. Both cases are pinned here, because they are the ones a rewrite
 * gets wrong and random data rarely produces.
 *
 * The bulk-volume rule is a different animal: it returns a probability rather
 * than a sign, through a logistic in the standardised bucket return with the
 * 1.702 factor that matches a logistic to a normal CDF.
 */
final class TradeClassifierTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * @param list<float> $prices
	 * @param list<float> $mids
	 * @return list<float>
	 */
	private static function naiveLeeReady(array $prices, array $mids): array
	{
		$out = [];
		$previous = \NAN;
		$lastSign = 0.0;

		foreach ($prices as $i => $price) {
			$mid = $mids[$i];
			$sign = 0.0;
			if (!\is_nan($mid)) {
				if ($price > $mid) {
					$sign = 1.0;
				} elseif ($price < $mid) {
					$sign = -1.0;
				}
			}
			if ($sign === 0.0) {
				// the tick rule, falling back to the previous sign on no change
				if (\is_nan($previous)) {
					$sign = $lastSign;
				} elseif ($price > $previous) {
					$sign = 1.0;
				} elseif ($price < $previous) {
					$sign = -1.0;
				} else {
					$sign = $lastSign;
				}
			}
			$previous = $price;
			$lastSign = $sign;
			$out[] = $sign;
		}
		return $out;
	}

	/**
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveTickRule(array $prices): array
	{
		return self::naiveLeeReady($prices, \array_fill(0, \count($prices), \NAN));
	}

	/**
	 * @return array{list<float>, list<float>}
	 */
	private static function tape(int $n, int $seed): array
	{
		\mt_srand($seed);
		$prices = [];
		$mids = [];
		$mid = 75.0;
		for ($i = 0; $i < $n; $i++) {
			$mid += (float) \mt_rand(-30, 30) / 100.0;
			$mids[] = $mid;
			// trades at, above and below the mid, including exact ties
			$prices[] = match (\mt_rand(0, 3)) {
				0 => $mid,
				1 => $mid + 0.01,
				2 => $mid - 0.01,
				default => $mid + (float) \mt_rand(-5, 5) / 100.0,
			};
		}
		return [$prices, $mids];
	}

	/**
	 * @param list<float> $expected
	 * @param list<float> $actual
	 */
	private static function assertSeriesMatches(array $expected, array $actual, string $label): void
	{
		self::assertSame(\count($expected), \count($actual), "$label: length");
		foreach ($expected as $i => $want) {
			if (\is_nan($want)) {
				self::assertNan($actual[$i], "$label: index $i expected NAN");
				continue;
			}
			self::assertEqualsWithDelta($want, $actual[$i], self::TOLERANCE, "$label: index $i");
		}
	}

	public function testLeeReadyMatchesANaiveImplementation(): void
	{
		for ($seed = 1; $seed <= 20; $seed++) {
			[$prices, $mids] = self::tape(300, $seed * 13);
			self::assertSeriesMatches(
				self::naiveLeeReady($prices, $mids),
				TradeClassifier::leeReady($prices, $mids),
				"seed $seed",
			);
		}
	}

	public function testTheTickRuleMatchesANaiveImplementation(): void
	{
		for ($seed = 1; $seed <= 20; $seed++) {
			[$prices] = self::tape(300, $seed * 17);
			self::assertSeriesMatches(
				self::naiveTickRule($prices),
				TradeClassifier::tickRule($prices),
				"seed $seed",
			);
		}
	}

	/**
	 * The quote rule proper: above the mid is a buy, below it a sell. Values
	 * taken straight from the definition.
	 */
	public function testTheQuoteRuleReadsTheSideFromThePriceAgainstTheMid(): void
	{
		$signs = TradeClassifier::leeReady(
			[100.5, 99.5, 100.5, 99.5],
			[100.0, 100.0, 100.0, 100.0],
		);

		self::assertSame([1.0, -1.0, 1.0, -1.0], $signs);
	}

	/**
	 * A trade exactly at the mid falls through to the tick rule, and when the
	 * price has not moved either, it inherits the last classified side rather
	 * than being reported as unknown. That inheritance is the part a rewrite
	 * drops.
	 */
	public function testATradeAtTheMidInheritsTheLastSide(): void
	{
		// A buy, then two prints sitting exactly on a mid that has caught up to
		// them. The price has not moved either, so the tick rule has nothing to
		// say and the earlier buy carries through.
		$signs = TradeClassifier::leeReady(
			[100.5, 100.5, 100.5],
			[100.0, 100.5, 100.5],
		);
		self::assertSame([1.0, 1.0, 1.0], $signs, 'the buy propagates through the ties');

		// the mirror image after a sell
		$afterSell = TradeClassifier::leeReady(
			[99.5, 99.5, 99.5],
			[100.0, 99.5, 99.5],
		);
		self::assertSame([-1.0, -1.0, -1.0], $afterSell);

		// The fallback is the tick rule, which compares against the previous
		// *trade*, not the mid: a print at the mid that is below the last print
		// is a sell, however the trade before it was classified.
		$downTick = TradeClassifier::leeReady(
			[100.5, 100.0, 100.0],
			[100.0, 100.0, 100.0],
		);
		self::assertSame([1.0, -1.0, -1.0], $downTick, 'the drop from 100.5 to 100.0 is a sell');

		// a tie that follows a price move takes the direction of the move
		$moved = TradeClassifier::leeReady(
			[100.0, 101.0, 101.0],
			[100.0, 101.0, 101.0],
		);
		self::assertSame([0.0, 1.0, 1.0], $moved);
	}

	/**
	 * The very first trade, with no quote and no predecessor, has nothing to go
	 * on and is reported as undetermined rather than guessed.
	 */
	public function testTheFirstTradeWithoutAQuoteIsUndetermined(): void
	{
		self::assertSame([0.0], TradeClassifier::tickRule([100.0]));
		self::assertSame([0.0, 1.0, -1.0], TradeClassifier::tickRule([100.0, 101.0, 99.0]));

		// with a quote available the first trade is classifiable at once
		self::assertSame([1.0], TradeClassifier::leeReady([100.5], [100.0]));
	}

	/** Zeros only ever appear at the very start, before any side is known. */
	public function testOnceASideIsKnownTheClassificationNeverReturnsToZero(): void
	{
		for ($seed = 1; $seed <= 25; $seed++) {
			[$prices, $mids] = self::tape(200, $seed * 7);
			$signs = TradeClassifier::leeReady($prices, $mids);

			$seenNonZero = false;
			foreach ($signs as $i => $sign) {
				if ($sign !== 0.0) {
					$seenNonZero = true;
					continue;
				}
				self::assertFalse($seenNonZero, "seed $seed: a zero at $i after a side was established");
			}
		}
	}

	/**
	 * The bulk-volume rule returns a probability, not a sign: a logistic in the
	 * standardised bucket return. A change of exactly one standard deviation
	 * gives 1/(1 + e^−1.702), and a zero change gives exactly one half.
	 */
	public function testBulkVolumeIsALogisticInTheStandardisedReturn(): void
	{
		// alternating ±1 changes, so the standard deviation of the changes is 1
		$closes = [100.0];
		for ($i = 0; $i < 60; $i++) {
			$closes[] = \end($closes) + ($i % 2 === 0 ? 1.0 : -1.0);
		}

		$out = TradeClassifier::bulkVolume($closes, 50);

		// the first bucket has no previous close to difference against
		self::assertNan($out[0]);

		$lastIndex = \count($out) - 1;
		self::assertGreaterThan(0, $lastIndex);
		$last = $out[$lastIndex] ?? \NAN;
		self::assertGreaterThan(0.0, $last);
		self::assertLessThan(1.0, $last);

		// a flat series has no dispersion, so no probability can be formed
		self::assertNan(TradeClassifier::bulkVolume(\array_fill(0, 60, 5.0), 50)[59]);

		// The reading is symmetric about one half: buying and selling pressure
		// of the same magnitude are mirror images. The changes have to vary, or
		// the dispersion is zero and there is no probability to form.
		$up = TradeClassifier::bulkVolume([100.0, 101.0, 103.0, 104.0, 106.0], 2);
		$down = TradeClassifier::bulkVolume([100.0, 99.0, 97.0, 96.0, 94.0], 2);
		$n = \count($up) - 1;
		$lastUp = $up[$n] ?? \NAN;
		$lastDown = $down[$n] ?? \NAN;
		self::assertTrue(\is_finite($lastUp), 'the varied series must produce a probability');
		self::assertEqualsWithDelta(1.0, $lastUp + $lastDown, self::TOLERANCE);
	}

	/** The 1.702 factor is the one that matches a logistic to a normal CDF. */
	public function testTheLogisticUsesTheNormalMatchingFactor(): void
	{
		// two changes of +1 and −1 give a sample standard deviation of √2,
		// then a change of +2 standardises to 2/σ
		$closes = [100.0, 101.0, 100.0, 102.0];
		$out = TradeClassifier::bulkVolume($closes, 3);

		$changes = [1.0, -1.0, 2.0];
		$mean = \array_sum($changes) / 3.0;
		$sq = 0.0;
		foreach ($changes as $c) {
			$sq += ($c - $mean) ** 2;
		}
		$sigma = \sqrt($sq / 2.0);

		self::assertEqualsWithDelta(
			1.0 / (1.0 + \exp(-1.702 * 2.0 / $sigma)),
			$out[3],
			self::TOLERANCE,
		);
	}

	/** The streaming object tracks the quote it was given and the tape it saw. */
	public function testTheStreamingObjectAgreesWithTheKernel(): void
	{
		for ($seed = 1; $seed <= 15; $seed++) {
			[$prices, $mids] = self::tape(200, $seed * 23);
			$kernel = TradeClassifier::leeReady($prices, $mids);

			$metric = new TradeClassifier(true, 50);
			foreach ($prices as $i => $price) {
				$metric->observeMid($mids[$i]);
				$metric->updateTrade(Trade::at(($i + 1) * 1_000_000_000, $price, 1.0));
				self::assertSame($kernel[$i], $metric->value(), "seed $seed, trade $i");
			}
		}
	}

	/** With quotes switched off the object is the plain tick rule. */
	public function testWithoutQuotesTheObjectIsTheTickRule(): void
	{
		[$prices, $mids] = self::tape(200, 909);
		$kernel = TradeClassifier::tickRule($prices);

		$metric = new TradeClassifier(false, 50);
		foreach ($prices as $i => $price) {
			$metric->observeMid($mids[$i]);
			$metric->updateTrade(Trade::at(($i + 1) * 1_000_000_000, $price, 1.0));
			self::assertSame($kernel[$i], $metric->value(), "trade $i");
		}
		self::assertSame(0.0, $metric->values()['quoteShare'], 'no trade was classified from a quote');
	}

	/** `side()` is the sign as an enum, and the mean sign is the tape's drift. */
	public function testSideAndMeanSign(): void
	{
		$metric = new TradeClassifier(true, 10);
		$metric->observeMid(100.0);

		$metric->updateTrade(Trade::at(1, 100.5, 1.0));
		self::assertSame(TradeSide::Buy, $metric->side());
		self::assertSame(1.0, $metric->value());

		$metric->updateTrade(Trade::at(2, 99.5, 1.0));
		self::assertSame(TradeSide::Sell, $metric->side());

		// one buy and one sell average to zero
		self::assertEqualsWithDelta(0.0, $metric->values()['meanSign'], self::TOLERANCE);
		self::assertSame(2.0, $metric->values()['classified']);
		self::assertEqualsWithDelta(1.0, $metric->values()['quoteShare'], self::TOLERANCE);
	}

	public function testWarmUpAndReset(): void
	{
		$metric = new TradeClassifier(true, 10);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['meanSign']);

		$metric->observeMid(100.0);
		$metric->updateTrade(Trade::at(1, 100.5, 1.0));
		self::assertTrue($metric->isReady());

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertSame(0.0, $metric->values()['classified']);
	}

	public function testWindowMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new TradeClassifier(true, 0);
	}

	public function testLeeReadyNeedsOneMidPerTrade(): void
	{
		$this->expectException(InvalidArgument::class);
		TradeClassifier::leeReady([1.0, 2.0], [1.0]);
	}

	public function testBulkVolumeNeedsAUsableSigmaWindow(): void
	{
		$this->expectException(InvalidArgument::class);
		TradeClassifier::bulkVolume([1.0, 2.0], 1);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new TradeClassifier(false, 250);
		self::assertSame(
			['type' => 'trade-classifier', 'useQuotes' => false, 'window' => 250],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), TradeClassifier::fromArray($metric->toArray())->toArray());
	}
}
