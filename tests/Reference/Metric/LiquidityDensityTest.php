<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Liquidity\DensityKernel;
use OpenCCK\Kalman\Domain\Metric\Liquidity\LiquidityDensity;
use PHPUnit\Framework\TestCase;

/**
 * Liquidity density (M-17) against a naive implementation that weighs every
 * level of the book with no early exit.
 *
 * The metric stops scanning at the first level outside the band, which is only
 * sound because the sides are sorted by distance from the mid (ADR-010). The
 * naive form here deliberately does not: it walks every level and relies on the
 * weight being zero. If the sorting invariant were ever broken, the two would
 * disagree — which is exactly the reference implementation's defect that
 * ADR-010 documents, and it is reproduced here as a test rather than described.
 */
final class LiquidityDensityTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * The published weight of a level at a given distance.
	 */
	private static function naiveWeight(float $distanceBps, float $bandBps, DensityKernel $kernel): float
	{
		return match ($kernel) {
			DensityKernel::Exponential => \exp(-$distanceBps / $bandBps),
			DensityKernel::Triangular => $distanceBps > $bandBps ? 0.0 : 1.0 - $distanceBps / $bandBps,
			DensityKernel::Rectangular => $distanceBps > $bandBps ? 0.0 : 1.0,
		};
	}

	/**
	 * Naive side sum: every level, weighted, with no early exit.
	 *
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 */
	private static function naiveSide(
		array $prices,
		array $sizes,
		float $mid,
		float $bandBps,
		DensityKernel $kernel,
		bool $asNotional,
	): float {
		$sum = 0.0;
		foreach ($prices as $i => $price) {
			$distanceBps = \abs($price - $mid) / $mid * 10000.0;
			$weighted = $sizes[$i] * self::naiveWeight($distanceBps, $bandBps, $kernel);
			$sum += $asNotional ? $weighted * $price : $weighted;
		}
		return $sum;
	}

	/**
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 */
	private static function naiveWithin(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		float $bandBps,
		DensityKernel $kernel,
	): float {
		if ($bidPrices === [] || $askPrices === []) {
			return \NAN;
		}
		$mid = ($bidPrices[0] + $askPrices[0]) / 2.0;
		return self::naiveSide($bidPrices, $bidSizes, $mid, $bandBps, $kernel, false)
			+ self::naiveSide($askPrices, $askSizes, $mid, $bandBps, $kernel, false);
	}

	/**
	 * A deterministic book spanning well beyond any band under test.
	 *
	 * @return array{list<float>, list<float>, list<float>, list<float>}
	 */
	private static function book(int $seed): array
	{
		\mt_srand($seed);
		$mid = 1000.0;
		$bidPrices = [];
		$bidSizes = [];
		$askPrices = [];
		$askSizes = [];
		for ($i = 0; $i < 30; $i++) {
			// levels every 2 bps out to 60 bps
			$offset = $mid * 0.0002 * ($i + 1);
			$bidPrices[] = $mid - $offset;
			$askPrices[] = $mid + $offset;
			$bidSizes[] = (float) \mt_rand(1, 500) / 10.0;
			$askSizes[] = (float) \mt_rand(1, 500) / 10.0;
		}
		return [$bidPrices, $bidSizes, $askPrices, $askSizes];
	}

	/** @return iterable<string, array{float, string}> */
	public function configurations(): iterable
	{
		foreach (['rectangular', 'triangular', 'exponential'] as $kernel) {
			foreach ([2.0, 10.0, 40.0, 1000.0] as $band) {
				yield "$kernel, band $band bps" => [$band, $kernel];
			}
		}
	}

	/** @dataProvider configurations */
	public function testMatchesANaiveWalkOfEveryLevel(float $bandBps, string $kernel): void
	{
		$shape = DensityKernel::from($kernel);

		for ($seed = 1; $seed <= 20; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 11);

			self::assertEqualsWithDelta(
				self::naiveWithin($bp, $bs, $ap, $as, $bandBps, $shape),
				LiquidityDensity::within($bp, $bs, $ap, $as, $bandBps, $kernel),
				self::TOLERANCE,
				"within, seed $seed",
			);

			$mid = ($bp[0] + $ap[0]) / 2.0;
			self::assertEqualsWithDelta(
				self::naiveSide($bp, $bs, $mid, $bandBps, $shape, true) + self::naiveSide($ap, $as, $mid, $bandBps, $shape, true),
				LiquidityDensity::notional($bp, $bs, $ap, $as, $bandBps, $kernel),
				self::TOLERANCE * 1000.0,
				"notional, seed $seed",
			);
		}
	}

	/**
	 * The early exit is valid only because each side is sorted by distance from
	 * the mid. Fed an unsorted side — the shape ADR-010 says the reference
	 * implementation produced from a hash map — the scan stops at the first
	 * level beyond the band and undercounts, while the naive full walk does not.
	 *
	 * This is not a defect in the metric: `OrderBook` guarantees the ordering.
	 * It is a demonstration of why that guarantee has to exist.
	 */
	public function testTheEarlyExitDependsOnTheSortingInvariant(): void
	{
		// a properly sorted book: two levels inside a 10 bps band
		$sortedBids = [999.5, 999.0, 990.0];
		$sortedSizes = [10.0, 20.0, 1000.0];
		$asks = [1000.5];
		$askSizes = [1.0];

		$sorted = LiquidityDensity::within($sortedBids, $sortedSizes, $asks, $askSizes, 10.0, 'rectangular');

		// the same levels in the order a hash map might yield them
		$shuffledBids = [990.0, 999.5, 999.0];
		$shuffledSizes = [1000.0, 10.0, 20.0];
		$shuffled = LiquidityDensity::within($shuffledBids, $shuffledSizes, $asks, $askSizes, 10.0, 'rectangular');

		self::assertGreaterThan($shuffled, $sorted, 'the unsorted walk stops early and undercounts');

		// the naive walk, which never exits early, is immune to the ordering
		$mid = (999.5 + 1000.5) / 2.0;
		self::assertEqualsWithDelta(
			self::naiveSide($sortedBids, $sortedSizes, $mid, 10.0, DensityKernel::Rectangular, false),
			self::naiveSide($shuffledBids, $shuffledSizes, $mid, 10.0, DensityKernel::Rectangular, false),
			self::TOLERANCE,
		);

		// and the entity restores the invariant, so a book built from the
		// shuffled levels reads the same as the sorted one
		$book = OrderBook::of(1, [[990.0, 1000.0], [999.5, 10.0], [999.0, 20.0]], [[1000.5, 1.0]]);
		$metric = new LiquidityDensity(10.0, DensityKernel::Rectangular);
		$metric->updateBook($book);
		self::assertEqualsWithDelta($sorted, $metric->value(), self::TOLERANCE);
	}

	/**
	 * Values derived by hand. A mid of 1000 puts a level at 999.0 exactly
	 * 10 bps out. With a 10 bps rectangular band it counts in full; with a
	 * triangular one its weight is 1 − 10/10 = 0; with an exponential one it is
	 * e⁻¹.
	 */
	public function testPinnedWeightsFromTheDefinition(): void
	{
		$bids = [999.0];
		$asks = [1001.0];
		$sizes = [4.0];
		$noSize = [0.0];

		// rectangular: the level is exactly on the edge, so it counts
		self::assertEqualsWithDelta(4.0, LiquidityDensity::within($bids, $sizes, $asks, $noSize, 10.0, 'rectangular'), self::TOLERANCE);
		// triangular: on the edge its weight has fallen to zero
		self::assertEqualsWithDelta(0.0, LiquidityDensity::within($bids, $sizes, $asks, $noSize, 10.0, 'triangular'), self::TOLERANCE);
		// exponential: one band width out is e⁻¹
		self::assertEqualsWithDelta(4.0 * \exp(-1.0), LiquidityDensity::within($bids, $sizes, $asks, $noSize, 10.0, 'exponential'), self::TOLERANCE);

		// half a band out, the triangular kernel is at one half
		self::assertEqualsWithDelta(2.0, LiquidityDensity::within($bids, $sizes, $asks, $noSize, 20.0, 'triangular'), self::TOLERANCE);

		// the notional weighs the same size by its price
		self::assertEqualsWithDelta(
			4.0 * 999.0,
			LiquidityDensity::notional($bids, $sizes, $asks, $noSize, 10.0, 'rectangular'),
			self::TOLERANCE,
		);
	}

	/** The kernels are ordered: rectangular counts most, triangular least. */
	public function testTheKernelsAreOrderedByHowMuchTheyDiscount(): void
	{
		for ($seed = 1; $seed <= 20; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed);

			$rect = LiquidityDensity::within($bp, $bs, $ap, $as, 20.0, 'rectangular');
			$tri = LiquidityDensity::within($bp, $bs, $ap, $as, 20.0, 'triangular');

			self::assertGreaterThan($tri, $rect, "seed $seed: a triangle discounts what a rectangle counts whole");
			self::assertGreaterThan(0.0, $tri, "seed $seed");
		}
	}

	/** A band wide enough to swallow the book counts all of it. */
	public function testAWideEnoughBandCountsTheWholeBook(): void
	{
		[$bp, $bs, $ap, $as] = self::book(4242);
		$total = \array_sum($bs) + \array_sum($as);

		self::assertEqualsWithDelta(
			$total,
			LiquidityDensity::within($bp, $bs, $ap, $as, 100000.0, 'rectangular'),
			self::TOLERANCE,
		);
		self::assertEqualsWithDelta(
			1.0,
			LiquidityDensity::share($bp, $bs, $ap, $as, 100000.0, 'rectangular'),
			self::TOLERANCE,
		);
	}

	/** The share is the counted size over the whole book, so it lives in [0, 1]. */
	public function testTheShareIsBoundedByZeroAndOne(): void
	{
		for ($seed = 1; $seed <= 20; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 5);
			foreach ([1.0, 10.0, 50.0] as $band) {
				$share = LiquidityDensity::share($bp, $bs, $ap, $as, $band, 'rectangular');
				self::assertGreaterThanOrEqual(0.0, $share, "seed $seed band $band");
				self::assertLessThanOrEqual(1.0 + self::TOLERANCE, $share, "seed $seed band $band");
			}
		}
	}

	/** A one-sided or empty book has no mid and therefore no density. */
	public function testAnEmptySideLeavesTheDensityUndefined(): void
	{
		self::assertNan(LiquidityDensity::within([], [], [101.0], [1.0], 10.0, 'rectangular'));
		self::assertNan(LiquidityDensity::within([99.0], [1.0], [], [], 10.0, 'rectangular'));
		self::assertNan(LiquidityDensity::share([], [], [], [], 10.0, 'rectangular'));

		$metric = new LiquidityDensity(10.0, DensityKernel::Rectangular);
		self::assertFalse($metric->isReady());
		$metric->updateBook(OrderBook::of(1, [[99.0, 1.0]], [[101.0, 1.0]]));
		self::assertTrue($metric->isReady());
		$metric->updateBook(OrderBook::of(2, [], []));
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$metric = new LiquidityDensity(15.0, DensityKernel::Triangular);

		for ($seed = 1; $seed <= 20; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 17);

			$bids = [];
			$asks = [];
			foreach ($bp as $i => $price) {
				$bids[] = [$price, $bs[$i]];
				$asks[] = [$ap[$i], $as[$i]];
			}
			$metric->updateBook(OrderBook::of($seed, $bids, $asks));

			self::assertSame(
				LiquidityDensity::within($bp, $bs, $ap, $as, 15.0, 'triangular'),
				$metric->value(),
				"seed $seed",
			);
			self::assertSame(
				LiquidityDensity::notional($bp, $bs, $ap, $as, 15.0, 'triangular'),
				$metric->values()['notional'],
				"notional, seed $seed",
			);
		}
	}

	public function testBandMustBeFiniteAndPositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new LiquidityDensity(0.0, DensityKernel::Rectangular);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new LiquidityDensity(25.0, DensityKernel::Exponential);
		self::assertSame(
			['type' => 'liquidity-density', 'bandBps' => 25.0, 'kernel' => 'exponential'],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), LiquidityDensity::fromArray($metric->toArray())->toArray());
	}
}
