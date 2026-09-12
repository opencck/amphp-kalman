<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Liquidity\BookSlope;
use PHPUnit\Framework\TestCase;

/**
 * Order-book slope (M-35) against a naive least-squares fit.
 *
 * The slope regresses cumulative depth on relative distance from the mid,
 * through the origin: at zero distance there is zero depth by construction, so
 * the fit has no intercept. That makes it the closed-form Στ·V / Στ², which the
 * naive implementation here recomputes term by term.
 *
 * The property that makes the number meaningful is its units: depth per unit of
 * *relative* distance, so it is invariant to the price level and comparable
 * between a 30-dollar instrument and a 30-thousand-dollar one. That is tested
 * directly, because a slope defined on absolute distances would fail it.
 */
final class BookSlopeTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive slope: accumulate depth, regress it on relative distance through
	 * the origin.
	 *
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 */
	private static function naiveSide(array $prices, array $sizes, float $mid, int $levels): float
	{
		$n = \count($prices);
		if ($levels > 0 && $levels < $n) {
			$n = $levels;
		}
		if ($n === 0 || !($mid > 0.0)) {
			return \NAN;
		}

		$taus = [];
		$cumulative = [];
		$running = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$running += $sizes[$i];
			$cumulative[] = $running;
			$taus[] = \abs($prices[$i] - $mid) / $mid;
		}

		$numerator = 0.0;
		$denominator = 0.0;
		foreach ($taus as $i => $tau) {
			$numerator += $tau * $cumulative[$i];
			$denominator += $tau * $tau;
		}
		return $denominator > 0.0 ? $numerator / $denominator : \NAN;
	}

	/**
	 * @return array{list<float>, list<float>, list<float>, list<float>}
	 */
	private static function book(int $seed, float $mid = 100.0): array
	{
		\mt_srand($seed);
		$bidPrices = [];
		$bidSizes = [];
		$askPrices = [];
		$askSizes = [];
		for ($i = 0; $i < 25; $i++) {
			$offset = $mid * 0.0001 * ($i + 1);
			$bidPrices[] = $mid - $offset;
			$askPrices[] = $mid + $offset;
			$bidSizes[] = (float) \mt_rand(1, 600) / 10.0;
			$askSizes[] = (float) \mt_rand(1, 600) / 10.0;
		}
		return [$bidPrices, $bidSizes, $askPrices, $askSizes];
	}

	/** @return iterable<string, array{int}> */
	public function depths(): iterable
	{
		yield 'one level' => [1];
		yield 'five levels' => [5];
		yield 'twenty levels' => [20];
		yield 'whole side' => [0];
	}

	/** @dataProvider depths */
	public function testMatchesANaiveLeastSquaresFit(int $levels): void
	{
		for ($seed = 1; $seed <= 25; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 29 + $levels);
			$mid = ($bp[0] + $ap[0]) / 2.0;

			self::assertEqualsWithDelta(
				self::naiveSide($bp, $bs, $mid, $levels),
				BookSlope::side($bp, $bs, $mid, $levels),
				self::TOLERANCE,
				"bid, seed $seed",
			);
			self::assertEqualsWithDelta(
				self::naiveSide($ap, $as, $mid, $levels),
				BookSlope::side($ap, $as, $mid, $levels),
				self::TOLERANCE,
				"ask, seed $seed",
			);
		}
	}

	/**
	 * A value derived by hand. One level of size 10 at 1% from the mid gives
	 * τ = 0.01 and cumulative depth 10, so the fit through the origin is
	 * 0.01·10 / 0.01² = 10 / 0.01 = 1000.
	 */
	public function testPinnedValueFromTheDefinition(): void
	{
		self::assertEqualsWithDelta(1000.0, BookSlope::side([99.0], [10.0], 100.0, 0), self::TOLERANCE);

		// two levels, both 10 deep, at 1% and 2%: cumulative depth 10 then 20
		// numerator = 0.01·10 + 0.02·20 = 0.5, denominator = 0.0001 + 0.0004 = 0.0005
		self::assertEqualsWithDelta(
			0.5 / 0.0005,
			BookSlope::side([99.0, 98.0], [10.0, 10.0], 100.0, 0),
			self::TOLERANCE,
		);
	}

	/**
	 * The slope is measured against *relative* distance, so the same book shape
	 * on a thirty-dollar instrument and a thirty-thousand-dollar one reads the
	 * same. A slope defined on absolute distances would differ by a factor of a
	 * thousand.
	 */
	public function testTheSlopeIsInvariantToThePriceLevel(): void
	{
		for ($seed = 1; $seed <= 20; $seed++) {
			[$bpLow, $bsLow] = self::book($seed, 30.0);
			[$bpHigh, $bsHigh] = self::book($seed, 30000.0);

			self::assertEqualsWithDelta(
				BookSlope::side($bpLow, $bsLow, 30.0, 0),
				BookSlope::side($bpHigh, $bsHigh, 30000.0, 0),
				1e-6,
				"seed $seed",
			);
		}
	}

	/** Twice the size at every level is twice the slope — depth scales linearly. */
	public function testTheSlopeScalesWithDepth(): void
	{
		[$bp, $bs] = self::book(4711);
		$doubled = \array_map(static fn (float $s): float => 2.0 * $s, $bs);

		self::assertEqualsWithDelta(
			2.0 * BookSlope::side($bp, $bs, 100.0, 0),
			BookSlope::side($bp, $doubled, 100.0, 0),
			self::TOLERANCE,
		);
	}

	/** A steeper book — the same depth reached closer in — reads higher. */
	public function testDepthCloserToTheTouchIsASteeperBook(): void
	{
		// the same 10 units, once at 1% out and once at 5%
		$near = BookSlope::side([99.0], [10.0], 100.0, 0);
		$far = BookSlope::side([95.0], [10.0], 100.0, 0);

		self::assertGreaterThan($far, $near, 'liquidity closer to the mid is a steeper book');
	}

	/** The asymmetry compares the two sides and is bounded by ±1. */
	public function testTheAsymmetryComparesTheTwoSides(): void
	{
		// a deep bid against a thin offer
		$both = BookSlope::both([99.0], [100.0], [101.0], [1.0], 0);
		self::assertGreaterThan(0.0, $both['asymmetry']);
		self::assertEqualsWithDelta(
			($both['bid'] - $both['ask']) / ($both['bid'] + $both['ask']),
			$both['asymmetry'],
			self::TOLERANCE,
		);
		self::assertEqualsWithDelta(($both['bid'] + $both['ask']) / 2.0, $both['slope'], self::TOLERANCE);

		// a mirror-image book is perfectly symmetric
		$symmetric = BookSlope::both([99.0], [50.0], [101.0], [50.0], 0);
		self::assertEqualsWithDelta(0.0, $symmetric['asymmetry'], self::TOLERANCE);

		for ($seed = 1; $seed <= 25; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 3);
			$asymmetry = BookSlope::both($bp, $bs, $ap, $as, 0)['asymmetry'];
			self::assertGreaterThanOrEqual(-1.0, $asymmetry, "seed $seed");
			self::assertLessThanOrEqual(1.0, $asymmetry, "seed $seed");
		}
	}

	/**
	 * A book whose every level sits at the mid leaves no distance to regress
	 * against, so the slope is undefined rather than infinite.
	 */
	public function testABookWithNoDistanceHasNoSlope(): void
	{
		self::assertNan(BookSlope::side([100.0, 100.0], [5.0, 5.0], 100.0, 0));
		self::assertNan(BookSlope::side([], [], 100.0, 0), 'an empty side has no slope');
		self::assertNan(BookSlope::side([99.0], [1.0], 0.0, 0), 'nor does a book with no mid');

		$empty = BookSlope::both([], [], [], [], 0);
		foreach ($empty as $key => $value) {
			self::assertNan($value, $key);
		}
	}

	/** The depth limit counts levels and ignores everything behind them. */
	public function testTheDepthLimitCountsLevels(): void
	{
		$prices = [99.0, 90.0];
		$sizes = [10.0, 10000.0];

		self::assertEqualsWithDelta(1000.0, BookSlope::side($prices, $sizes, 100.0, 1), self::TOLERANCE);
		self::assertGreaterThan(
			BookSlope::side($prices, $sizes, 100.0, 1),
			BookSlope::side($prices, $sizes, 100.0, 0),
			'the wall behind counts once it is in scope',
		);
	}

	public function testWarmUpAndReset(): void
	{
		$metric = new BookSlope(20);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		$metric->updateBook(OrderBook::of(1, [[99.0, 5.0], [98.0, 5.0]], [[101.0, 5.0], [102.0, 5.0]]));
		self::assertTrue($metric->isReady());
		self::assertTrue(\is_finite($metric->value()));

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['asymmetry']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$metric = new BookSlope(10);

		for ($seed = 1; $seed <= 25; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 13);

			$bids = [];
			$asks = [];
			foreach ($bp as $i => $price) {
				$bids[] = [$price, $bs[$i]];
				$asks[] = [$ap[$i], $as[$i]];
			}
			$metric->updateBook(OrderBook::of($seed, $bids, $asks));

			$both = BookSlope::both($bp, $bs, $ap, $as, 10);
			self::assertSame($both['slope'], $metric->value(), "slope, seed $seed");
			self::assertSame($both['bid'], $metric->values()['bidSlope'], "bid, seed $seed");
			self::assertSame($both['ask'], $metric->values()['askSlope'], "ask, seed $seed");
		}
	}

	public function testLevelsMustNotBeNegative(): void
	{
		$this->expectException(InvalidArgument::class);
		new BookSlope(-1);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		BookSlope::side([1.0, 2.0], [1.0], 1.5, 0);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new BookSlope(15);
		self::assertSame(['type' => 'book-slope', 'levels' => 15], $metric->toArray());
		self::assertSame($metric->toArray(), BookSlope::fromArray($metric->toArray())->toArray());
	}
}
