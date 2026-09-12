<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Liquidity\WeightedPrices;
use PHPUnit\Framework\TestCase;

/**
 * Side-weighted prices and the weighted mid (M-19) against naive sums.
 *
 * The weighted mid is the one formula here that is easy to write backwards:
 * the bid size weights the *ask* price, not the bid. The reason is that size
 * resting on the bid is pressure towards the offer, so a heavy bid pulls the
 * fair price up. This test pins that orientation with a book so lopsided that
 * a swapped pair would be obvious, and then checks the mid is bracketed by the
 * touch, which a swap would still satisfy but the pinned values would not.
 */
final class WeightedPricesTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 */
	private static function naiveSide(array $prices, array $sizes, int $levels): float
	{
		$n = \count($prices);
		if ($levels > 0 && $levels < $n) {
			$n = $levels;
		}
		$weighted = 0.0;
		$volume = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$weighted += $prices[$i] * $sizes[$i];
			$volume += $sizes[$i];
		}
		return $volume > 0.0 ? $weighted / $volume : \NAN;
	}

	private static function naiveWeightedMid(float $bidPrice, float $bidSize, float $askPrice, float $askSize): float
	{
		$total = $bidSize + $askSize;
		if (!($total > 0.0)) {
			return ($bidPrice + $askPrice) / 2.0;
		}
		return ($askPrice * $bidSize + $bidPrice * $askSize) / $total;
	}

	/**
	 * @return array{list<float>, list<float>, list<float>, list<float>}
	 */
	private static function book(int $seed): array
	{
		\mt_srand($seed);
		$mid = 500.0;
		$bidPrices = [];
		$bidSizes = [];
		$askPrices = [];
		$askSizes = [];
		for ($i = 0; $i < 12; $i++) {
			$bidPrices[] = $mid - 0.05 * ($i + 1);
			$askPrices[] = $mid + 0.05 * ($i + 1);
			$bidSizes[] = (float) \mt_rand(1, 800) / 10.0;
			$askSizes[] = (float) \mt_rand(1, 800) / 10.0;
		}
		return [$bidPrices, $bidSizes, $askPrices, $askSizes];
	}

	/** @return iterable<string, array{int}> */
	public function depths(): iterable
	{
		yield 'top of book' => [1];
		yield 'three levels' => [3];
		yield 'five levels' => [5];
		yield 'whole side' => [0];
	}

	/** @dataProvider depths */
	public function testSideMatchesANaiveWeightedAverage(int $levels): void
	{
		for ($seed = 1; $seed <= 25; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 19 + $levels);

			self::assertEqualsWithDelta(
				self::naiveSide($bp, $bs, $levels),
				WeightedPrices::side($bp, $bs, $levels),
				self::TOLERANCE,
				"bid side, seed $seed",
			);
			self::assertEqualsWithDelta(
				self::naiveSide($ap, $as, $levels),
				WeightedPrices::side($ap, $as, $levels),
				self::TOLERANCE,
				"ask side, seed $seed",
			);
		}
	}

	public function testWeightedMidMatchesANaiveImplementation(): void
	{
		for ($seed = 1; $seed <= 40; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed);

			self::assertEqualsWithDelta(
				self::naiveWeightedMid($bp[0], $bs[0], $ap[0], $as[0]),
				WeightedPrices::weightedMid($bp[0], $bs[0], $ap[0], $as[0]),
				self::TOLERANCE,
				"seed $seed",
			);
		}
	}

	/**
	 * Values derived by hand, and the orientation the formula turns on.
	 *
	 * With 9 units bid at 100 and 1 unit offered at 101, the weighted mid is
	 * (101·9 + 100·1) / 10 = 100.9 — pulled towards the offer, because the
	 * resting bid size is buying pressure. Swapping the two sizes in the
	 * numerator would give 100.1 instead, and the test would fail.
	 */
	public function testTheWeightedMidIsPulledTowardsTheHeavySide(): void
	{
		self::assertEqualsWithDelta(100.9, WeightedPrices::weightedMid(100.0, 9.0, 101.0, 1.0), self::TOLERANCE);
		self::assertEqualsWithDelta(100.1, WeightedPrices::weightedMid(100.0, 1.0, 101.0, 9.0), self::TOLERANCE);

		// balanced sizes put it exactly at the arithmetic mid
		self::assertEqualsWithDelta(100.5, WeightedPrices::weightedMid(100.0, 5.0, 101.0, 5.0), self::TOLERANCE);

		// and with no size at all there is nothing to weigh, so the plain mid stands
		self::assertEqualsWithDelta(100.5, WeightedPrices::weightedMid(100.0, 0.0, 101.0, 0.0), self::TOLERANCE);
	}

	/** A weighted mid always lies inside the touch, whatever the sizes. */
	public function testTheWeightedMidStaysInsideTheSpread(): void
	{
		for ($seed = 1; $seed <= 50; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 3);

			$weightedMid = WeightedPrices::weightedMid($bp[0], $bs[0], $ap[0], $as[0]);
			self::assertGreaterThanOrEqual($bp[0], $weightedMid, "seed $seed");
			self::assertLessThanOrEqual($ap[0], $weightedMid, "seed $seed");
		}
	}

	/**
	 * A side average is a weighted mean of its levels, so it always sits
	 * between the best and the worst price counted — and on the bid, below the
	 * touch, because every level behind it is cheaper.
	 */
	public function testASideAverageLiesBetweenTheLevelsItCounts(): void
	{
		for ($seed = 1; $seed <= 25; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 23);

			$bid = WeightedPrices::side($bp, $bs, 5);
			self::assertLessThanOrEqual($bp[0], $bid, "the weighted bid cannot beat the touch, seed $seed");
			self::assertGreaterThanOrEqual($bp[4], $bid, "nor fall behind the deepest level counted, seed $seed");

			$ask = WeightedPrices::side($ap, $as, 5);
			self::assertGreaterThanOrEqual($ap[0], $ask, "seed $seed");
			self::assertLessThanOrEqual($ap[4], $ask, "seed $seed");
		}
	}

	/** Size concentrated on one level makes the average that level's price. */
	public function testAllTheSizeOnOneLevelGivesThatPrice(): void
	{
		self::assertSame(98.0, WeightedPrices::side([100.0, 99.0, 98.0], [0.0, 0.0, 7.0], 0));
		self::assertSame(100.0, WeightedPrices::side([100.0, 99.0, 98.0], [7.0, 0.0, 0.0], 0));

		// an empty side has no average at all
		self::assertNan(WeightedPrices::side([100.0], [0.0], 0));
		self::assertNan(WeightedPrices::side([], [], 0));
	}

	/** The depth limit counts levels and ignores everything behind them. */
	public function testTheDepthLimitCountsLevels(): void
	{
		$prices = [100.0, 50.0];
		$sizes = [1.0, 1000.0];

		self::assertSame(100.0, WeightedPrices::side($prices, $sizes, 1), 'the wall behind is out of scope');
		self::assertEqualsWithDelta(
			(100.0 * 1.0 + 50.0 * 1000.0) / 1001.0,
			WeightedPrices::side($prices, $sizes, 0),
			self::TOLERANCE,
		);
		self::assertSame(
			WeightedPrices::side($prices, $sizes, 0),
			WeightedPrices::side($prices, $sizes, 99),
			'asking past the end of the side is the whole side',
		);
	}

	/** The tilt is the weighted mid's distance from the plain mid, in bps. */
	public function testTheTiltIsTheDistanceFromThePlainMidInBasisPoints(): void
	{
		$metric = new WeightedPrices(5);
		$metric->updateBook(OrderBook::of(1, [[100.0, 9.0]], [[101.0, 1.0]]));

		$values = $metric->values();
		self::assertEqualsWithDelta(100.9, $values['weightedMid'], self::TOLERANCE);
		self::assertEqualsWithDelta(100.5, $values['mid'], self::TOLERANCE);
		self::assertEqualsWithDelta((100.9 - 100.5) / 100.5 * 10000.0, $values['tilt'], self::TOLERANCE);
		self::assertGreaterThan(0.0, $values['tilt'], 'a heavy bid tilts the fair price up');

		$metric->updateBook(OrderBook::of(2, [[100.0, 1.0]], [[101.0, 9.0]]));
		self::assertLessThan(0.0, $metric->values()['tilt'], 'a heavy offer tilts it down');
	}

	/** `all()` gathers the same numbers the kernels produce separately. */
	public function testAllGathersTheSameNumbers(): void
	{
		for ($seed = 1; $seed <= 20; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 31);
			$all = WeightedPrices::all($bp, $bs, $ap, $as, 5);

			self::assertEqualsWithDelta(WeightedPrices::side($bp, $bs, 5), $all['bid'], self::TOLERANCE);
			self::assertEqualsWithDelta(WeightedPrices::side($ap, $as, 5), $all['ask'], self::TOLERANCE);
			self::assertEqualsWithDelta(
				WeightedPrices::weightedMid($bp[0], $bs[0], $ap[0], $as[0]),
				$all['weightedMid'],
				self::TOLERANCE,
			);
			self::assertEqualsWithDelta(($bp[0] + $ap[0]) / 2.0, $all['mid'], self::TOLERANCE);
		}

		$empty = WeightedPrices::all([], [], [], [], 5);
		foreach ($empty as $key => $value) {
			self::assertNan($value, $key);
		}
	}

	/** An empty book leaves every reading undefined. */
	public function testAnEmptyBookIsUndefined(): void
	{
		$metric = new WeightedPrices(5);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		$metric->updateBook(OrderBook::of(1, [[99.0, 1.0]], [[101.0, 1.0]]));
		self::assertTrue($metric->isReady());

		$metric->updateBook(OrderBook::of(2, [], []));
		self::assertFalse($metric->isReady());
		self::assertNan($metric->values()['tilt']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$metric = new WeightedPrices(5);

		for ($seed = 1; $seed <= 25; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 7);

			$bids = [];
			$asks = [];
			foreach ($bp as $i => $price) {
				$bids[] = [$price, $bs[$i]];
				$asks[] = [$ap[$i], $as[$i]];
			}
			$metric->updateBook(OrderBook::of($seed, $bids, $asks));
			$values = $metric->values();

			self::assertSame(WeightedPrices::side($bp, $bs, 5), $values['bid'], "bid, seed $seed");
			self::assertSame(WeightedPrices::side($ap, $as, 5), $values['ask'], "ask, seed $seed");
			self::assertSame(
				WeightedPrices::weightedMid($bp[0], $bs[0], $ap[0], $as[0]),
				$metric->value(),
				"weighted mid, seed $seed",
			);
		}
	}

	public function testLevelsMustNotBeNegative(): void
	{
		$this->expectException(InvalidArgument::class);
		new WeightedPrices(-1);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		WeightedPrices::side([1.0, 2.0], [1.0], 0);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new WeightedPrices(10);
		self::assertSame(['type' => 'weighted-prices', 'levels' => 10], $metric->toArray());
		self::assertSame($metric->toArray(), WeightedPrices::fromArray($metric->toArray())->toArray());
	}
}
