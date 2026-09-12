<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Liquidity\OrderBookImbalance;
use PHPUnit\Framework\TestCase;

/**
 * Order-book imbalance (M-16) against a naive implementation that walks the
 * two sides with a plain loop.
 *
 * The three output forms are one quantity in three parameterisations, and the
 * relations between them are exact: the imbalance is (b−a)/(b+a), the ratio is
 * b/a, and they satisfy imbalance = (ratio−1)/(ratio+1) identically. Pinning
 * that relation catches a sign or a swapped side in any of the three.
 *
 * The distance weighting is the other half: with no decay every level inside
 * the depth counts alike, and with a decay the weight falls off as
 * exp(−distance_bps / decay_bps) — measured here against hand-computed
 * exponentials rather than described.
 */
final class OrderBookImbalanceTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 */
	private static function naiveSideVolume(array $prices, array $sizes, float $mid, int $levels, float $decayBps): float
	{
		$n = \count($prices);
		if ($levels > 0 && $levels < $n) {
			$n = $levels;
		}
		if ($n === 0) {
			return 0.0;
		}
		$sum = 0.0;
		for ($i = 0; $i < $n; $i++) {
			if ($decayBps > 0.0) {
				$distanceBps = \abs($prices[$i] - $mid) / $mid * 10000.0;
				$sum += $sizes[$i] * \exp(-$distanceBps / $decayBps);
			} else {
				$sum += $sizes[$i];
			}
		}
		return $sum;
	}

	/**
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 * @return array{float, float, float} imbalance, ratio, logRatio
	 */
	private static function naive(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		int $levels,
		float $decayBps,
	): array {
		if ($bidPrices === [] || $askPrices === []) {
			return [\NAN, \NAN, \NAN];
		}
		$mid = ($bidPrices[0] + $askPrices[0]) / 2.0;
		$b = self::naiveSideVolume($bidPrices, $bidSizes, $mid, $levels, $decayBps);
		$a = self::naiveSideVolume($askPrices, $askSizes, $mid, $levels, $decayBps);

		return [
			$b + $a > 0.0 ? ($b - $a) / ($b + $a) : \NAN,
			$a > 0.0 ? $b / $a : \NAN,
			$b > 0.0 && $a > 0.0 ? \log($b / $a) : \NAN,
		];
	}

	/**
	 * A deterministic book with ten levels a side.
	 *
	 * @return array{list<float>, list<float>, list<float>, list<float>}
	 */
	private static function book(int $seed): array
	{
		\mt_srand($seed);
		$mid = 100.0;
		$bidPrices = [];
		$bidSizes = [];
		$askPrices = [];
		$askSizes = [];
		for ($i = 0; $i < 10; $i++) {
			$bidPrices[] = $mid - 0.01 * ($i + 1);
			$askPrices[] = $mid + 0.01 * ($i + 1);
			$bidSizes[] = (float) \mt_rand(1, 1000) / 10.0;
			$askSizes[] = (float) \mt_rand(1, 1000) / 10.0;
		}
		return [$bidPrices, $bidSizes, $askPrices, $askSizes];
	}

	/** @return iterable<string, array{int, float}> */
	public function configurations(): iterable
	{
		yield 'top of book' => [1, 0.0];
		yield 'five levels' => [5, 0.0];
		yield 'whole book' => [0, 0.0];
		yield 'five levels, 5 bps decay' => [5, 5.0];
		yield 'whole book, 1 bp decay' => [0, 1.0];
		yield 'whole book, 100 bps decay' => [0, 100.0];
	}

	/** @dataProvider configurations */
	public function testMatchesANaiveWalkOfTheBook(int $levels, float $decayBps): void
	{
		for ($seed = 1; $seed <= 25; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 13 + $levels);
			[$imbalance, $ratio, $logRatio] = self::naive($bp, $bs, $ap, $as, $levels, $decayBps);

			self::assertEqualsWithDelta(
				$imbalance,
				OrderBookImbalance::imbalance($bp, $bs, $ap, $as, $levels, $decayBps),
				self::TOLERANCE,
				"imbalance, seed $seed",
			);
			self::assertEqualsWithDelta(
				$ratio,
				OrderBookImbalance::ratio($bp, $bs, $ap, $as, $levels, $decayBps),
				self::TOLERANCE,
				"ratio, seed $seed",
			);
			self::assertEqualsWithDelta(
				$logRatio,
				OrderBookImbalance::logRatio($bp, $bs, $ap, $as, $levels, $decayBps),
				self::TOLERANCE,
				"logRatio, seed $seed",
			);
		}
	}

	/**
	 * The three forms are one quantity: imbalance = (ratio − 1)/(ratio + 1) and
	 * logRatio = ln(ratio). A swapped side or a flipped sign in any one of them
	 * breaks this identity.
	 */
	public function testTheThreeFormsAreTheSameQuantity(): void
	{
		for ($seed = 1; $seed <= 30; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 7);

			$imbalance = OrderBookImbalance::imbalance($bp, $bs, $ap, $as, 5, 0.0);
			$ratio = OrderBookImbalance::ratio($bp, $bs, $ap, $as, 5, 0.0);
			$logRatio = OrderBookImbalance::logRatio($bp, $bs, $ap, $as, 5, 0.0);

			self::assertEqualsWithDelta(($ratio - 1.0) / ($ratio + 1.0), $imbalance, self::TOLERANCE, "seed $seed");
			self::assertEqualsWithDelta(\log($ratio), $logRatio, self::TOLERANCE, "seed $seed");
		}
	}

	/**
	 * Values derived by hand. Three units bid against one unit offered give an
	 * imbalance of (3 − 1)/(3 + 1) = 0.5, a ratio of 3 and a log ratio of ln 3.
	 * A balanced book gives exactly zero, one and zero.
	 */
	public function testPinnedValuesFromTheDefinition(): void
	{
		$bp = [99.0];
		$ap = [101.0];

		self::assertSame(0.5, OrderBookImbalance::imbalance($bp, [3.0], $ap, [1.0], 1, 0.0));
		self::assertSame(3.0, OrderBookImbalance::ratio($bp, [3.0], $ap, [1.0], 1, 0.0));
		self::assertEqualsWithDelta(\log(3.0), OrderBookImbalance::logRatio($bp, [3.0], $ap, [1.0], 1, 0.0), self::TOLERANCE);

		self::assertSame(0.0, OrderBookImbalance::imbalance($bp, [2.0], $ap, [2.0], 1, 0.0));
		self::assertSame(1.0, OrderBookImbalance::ratio($bp, [2.0], $ap, [2.0], 1, 0.0));
		self::assertSame(0.0, OrderBookImbalance::logRatio($bp, [2.0], $ap, [2.0], 1, 0.0));
	}

	/** The imbalance is bounded by ±1, reached only when one side is empty. */
	public function testTheImbalanceIsBoundedAndSaturatesOnAOneSidedBook(): void
	{
		$bp = [99.0];
		$ap = [101.0];

		self::assertSame(1.0, OrderBookImbalance::imbalance($bp, [5.0], $ap, [0.0], 1, 0.0), 'no offer at all');
		self::assertSame(-1.0, OrderBookImbalance::imbalance($bp, [0.0], $ap, [5.0], 1, 0.0), 'no bid at all');

		// with no size on either side there is nothing to compare
		self::assertNan(OrderBookImbalance::imbalance($bp, [0.0], $ap, [0.0], 1, 0.0));
		// and a ratio against an empty offer is undefined rather than infinite
		self::assertNan(OrderBookImbalance::ratio($bp, [5.0], $ap, [0.0], 1, 0.0));

		for ($seed = 1; $seed <= 40; $seed++) {
			[$bpr, $bs, $apr, $as] = self::book($seed);
			$value = OrderBookImbalance::imbalance($bpr, $bs, $apr, $as, 0, 0.0);
			self::assertGreaterThanOrEqual(-1.0, $value, "seed $seed");
			self::assertLessThanOrEqual(1.0, $value, "seed $seed");
		}
	}

	/** Swapping the two sides flips the sign and inverts the ratio. */
	public function testSwappingTheSidesFlipsTheSign(): void
	{
		[$bp, $bs, $ap, $as] = self::book(999);

		// a mirror-image book: the same sizes at the same distances, sides swapped
		$mirroredBidPrices = \array_map(static fn (float $p): float => 200.0 - $p, $ap);
		$mirroredAskPrices = \array_map(static fn (float $p): float => 200.0 - $p, $bp);

		self::assertEqualsWithDelta(
			-OrderBookImbalance::imbalance($bp, $bs, $ap, $as, 5, 0.0),
			OrderBookImbalance::imbalance($mirroredBidPrices, $as, $mirroredAskPrices, $bs, 5, 0.0),
			self::TOLERANCE,
		);
	}

	/**
	 * The depth limit counts levels, not size: asking for one level must ignore
	 * everything behind it, however large.
	 */
	public function testTheDepthLimitCountsLevels(): void
	{
		$bp = [99.0, 98.0, 97.0];
		$ap = [101.0, 102.0, 103.0];
		$bs = [1.0, 1000.0, 1000.0];
		$as = [1.0, 1.0, 1.0];

		// only the top level: perfectly balanced
		self::assertSame(0.0, OrderBookImbalance::imbalance($bp, $bs, $ap, $as, 1, 0.0));
		// the whole book: overwhelmingly bid
		self::assertGreaterThan(0.99, OrderBookImbalance::imbalance($bp, $bs, $ap, $as, 0, 0.0));
		// asking for more levels than exist is the whole book
		self::assertSame(
			OrderBookImbalance::imbalance($bp, $bs, $ap, $as, 0, 0.0),
			OrderBookImbalance::imbalance($bp, $bs, $ap, $as, 99, 0.0),
		);
	}

	/**
	 * The decay weight is exp(−distance_bps / decay_bps), computed here by hand
	 * for a book whose levels sit at round distances from the mid.
	 */
	public function testTheDecayWeightIsAnExponentialInDistance(): void
	{
		// mid 100, so these levels are 100 bps and 200 bps out
		$bp = [99.0, 98.0];
		$bs = [1.0, 1.0];

		$decay = 100.0;
		$expected = 1.0 * \exp(-100.0 / $decay) + 1.0 * \exp(-200.0 / $decay);

		self::assertEqualsWithDelta(
			$expected,
			OrderBookImbalance::sideVolume($bp, $bs, 100.0, 0, $decay),
			self::TOLERANCE,
		);

		// with no decay the same side is simply the sum of its sizes
		self::assertSame(2.0, OrderBookImbalance::sideVolume($bp, $bs, 100.0, 0, 0.0));

		// a far level counts for less than a near one
		self::assertGreaterThan(
			OrderBookImbalance::sideVolume([98.0], [1.0], 100.0, 0, $decay),
			OrderBookImbalance::sideVolume([99.0], [1.0], 100.0, 0, $decay),
		);
	}

	/**
	 * Decay changes the answer when the size sits far from the touch — that is
	 * the entire point of weighting by distance.
	 */
	public function testDecayDiscountsSizeParkedAwayFromTheTouch(): void
	{
		// a thin bid at the touch against a wall of asks far away
		$bp = [99.99];
		$bs = [10.0];
		$ap = [100.01, 105.0];
		$as = [10.0, 1000.0];

		$undecayed = OrderBookImbalance::imbalance($bp, $bs, $ap, $as, 0, 0.0);
		$decayed = OrderBookImbalance::imbalance($bp, $bs, $ap, $as, 0, 10.0);

		self::assertLessThan(-0.9, $undecayed, 'unweighted, the distant wall dominates');
		self::assertGreaterThan($undecayed, $decayed, 'weighted, it barely counts');
		self::assertEqualsWithDelta(0.0, $decayed, 0.05, 'leaving the touch roughly balanced');
	}

	/** An empty or one-sided book has no imbalance to report. */
	public function testAnEmptyBookIsUndefined(): void
	{
		self::assertNan(OrderBookImbalance::imbalance([], [], [101.0], [1.0], 5, 0.0));
		self::assertNan(OrderBookImbalance::imbalance([99.0], [1.0], [], [], 5, 0.0));

		$metric = new OrderBookImbalance(5, 0.0);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		$metric->updateBook(OrderBook::of(1, [[99.0, 1.0]], [[101.0, 1.0]]));
		self::assertTrue($metric->isReady());

		$metric->updateBook(OrderBook::of(2, [], []));
		self::assertFalse($metric->isReady(), 'an empty update must invalidate the reading');
		self::assertNan($metric->value());
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$metric = new OrderBookImbalance(5, 2.0);

		for ($seed = 1; $seed <= 40; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 3);

			$bids = [];
			$asks = [];
			foreach ($bp as $i => $price) {
				$bids[] = [$price, $bs[$i]];
				$asks[] = [$ap[$i], $as[$i]];
			}
			$metric->updateBook(OrderBook::of($seed, $bids, $asks));

			self::assertSame(
				OrderBookImbalance::imbalance($bp, $bs, $ap, $as, 5, 2.0),
				$metric->value(),
				"seed $seed",
			);
			self::assertSame(
				OrderBookImbalance::ratio($bp, $bs, $ap, $as, 5, 2.0),
				$metric->values()['ratio'],
				"ratio, seed $seed",
			);
		}
	}

	public function testLevelsMustNotBeNegative(): void
	{
		$this->expectException(InvalidArgument::class);
		new OrderBookImbalance(-1, 0.0);
	}

	public function testDecayMustBeFiniteAndNonNegative(): void
	{
		$this->expectException(InvalidArgument::class);
		new OrderBookImbalance(5, -1.0);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		OrderBookImbalance::sideVolume([1.0, 2.0], [1.0], 1.5, 0, 0.0);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new OrderBookImbalance(8, 12.5);
		self::assertSame(['type' => 'order-book-imbalance', 'levels' => 8, 'decayBps' => 12.5], $metric->toArray());
		self::assertSame($metric->toArray(), OrderBookImbalance::fromArray($metric->toArray())->toArray());
	}
}
