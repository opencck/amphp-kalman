<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Liquidity\CostToTrade;
use PHPUnit\Framework\TestCase;

/**
 * Cost to trade (M-36) against a naive book walk.
 *
 * Two walks live in this metric and they are not the same: one consumes a
 * notional budget and one consumes a quantity. The distinction matters because
 * a notional walk buys `take / price` units at each level, so the average price
 * it achieves is a harmonic mean weighted by spend, while a size walk pays
 * `take · price` and averages arithmetically. Confusing the two gives an answer
 * that is right at the touch and drifts as the order eats into the book, which
 * is precisely where the number is supposed to be useful — so both are pinned
 * against hand-computed fills here.
 */
final class CostToTradeTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive notional walk: spend a budget across levels, count the units it buys.
	 *
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return array{float, float} slippage in bps, filled fraction
	 */
	private static function naiveNotionalWalk(array $prices, array $sizes, float $mid, float $notional, bool $isBuy): array
	{
		$remaining = $notional;
		$spent = 0.0;
		$bought = 0.0;
		foreach ($prices as $i => $price) {
			if ($remaining <= 0.0) {
				break;
			}
			$available = $sizes[$i] * $price;
			$take = \min($remaining, $available);
			$spent += $take;
			$bought += $take / $price;
			$remaining -= $take;
		}
		$filled = ($notional - $remaining) / $notional;
		if ($bought <= 0.0 || $remaining > 0.0 || !($mid > 0.0)) {
			return [\NAN, $filled];
		}
		$avg = $spent / $bought;
		return [($isBuy ? $avg - $mid : $mid - $avg) / $mid * 10000.0, $filled];
	}

	/**
	 * Naive size walk: consume a quantity, pay price × size at each level.
	 *
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return array{float, float, float} average price, slippage in bps, filled fraction
	 */
	private static function naiveSizeWalk(array $prices, array $sizes, float $mid, float $size, bool $isBuy): array
	{
		$remaining = $size;
		$spent = 0.0;
		foreach ($prices as $i => $price) {
			if ($remaining <= 0.0) {
				break;
			}
			$take = \min($remaining, $sizes[$i]);
			$spent += $take * $price;
			$remaining -= $take;
		}
		$filled = $size > 0.0 ? ($size - $remaining) / $size : \NAN;
		if ($remaining > 0.0 || !($mid > 0.0) || $size <= 0.0) {
			return [\NAN, \NAN, $filled];
		}
		$avg = $spent / $size;
		return [$avg, ($isBuy ? $avg - $mid : $mid - $avg) / $mid * 10000.0, $filled];
	}

	/**
	 * @return array{list<float>, list<float>, list<float>, list<float>}
	 */
	private static function book(int $seed): array
	{
		\mt_srand($seed);
		$mid = 400.0;
		$bidPrices = [];
		$bidSizes = [];
		$askPrices = [];
		$askSizes = [];
		for ($i = 0; $i < 20; $i++) {
			$offset = 0.05 * ($i + 1);
			$bidPrices[] = $mid - $offset;
			$askPrices[] = $mid + $offset;
			$bidSizes[] = (float) \mt_rand(1, 300) / 10.0;
			$askSizes[] = (float) \mt_rand(1, 300) / 10.0;
		}
		return [$bidPrices, $bidSizes, $askPrices, $askSizes];
	}

	/** @return iterable<string, array{float}> */
	public function notionals(): iterable
	{
		yield 'tiny, fills at the touch' => [100.0];
		yield 'moderate' => [5000.0];
		yield 'large, eats several levels' => [50000.0];
		yield 'larger than the book' => [100_000_000.0];
	}

	/** @dataProvider notionals */
	public function testMatchesANaiveNotionalWalk(float $notional): void
	{
		for ($seed = 1; $seed <= 20; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 7);
			$mid = ($bp[0] + $ap[0]) / 2.0;

			[$buyBps] = self::naiveNotionalWalk($ap, $as, $mid, $notional, true);
			[$sellBps] = self::naiveNotionalWalk($bp, $bs, $mid, $notional, false);

			$actualBuy = CostToTrade::forNotional($bp, $bs, $ap, $as, $notional, true);
			$actualSell = CostToTrade::forNotional($bp, $bs, $ap, $as, $notional, false);

			if (\is_nan($buyBps)) {
				self::assertNan($actualBuy, "buy, seed $seed");
			} else {
				self::assertEqualsWithDelta($buyBps, $actualBuy, self::TOLERANCE, "buy, seed $seed");
			}
			if (\is_nan($sellBps)) {
				self::assertNan($actualSell, "sell, seed $seed");
			} else {
				self::assertEqualsWithDelta($sellBps, $actualSell, self::TOLERANCE, "sell, seed $seed");
			}
		}
	}

	public function testMatchesANaiveSizeWalk(): void
	{
		foreach ([1.0, 10.0, 100.0, 1_000_000.0] as $size) {
			for ($seed = 1; $seed <= 15; $seed++) {
				[$bp, , $ap, $as] = self::book($seed * 11);
				$mid = ($bp[0] + $ap[0]) / 2.0;

				[$avg, $bps, $filled] = self::naiveSizeWalk($ap, $as, $mid, $size, true);
				$actual = CostToTrade::forSize($ap, $as, $mid, $size, true);

				self::assertEqualsWithDelta($filled, $actual['filled'], self::TOLERANCE, "filled, size $size seed $seed");
				if (\is_nan($avg)) {
					self::assertNan($actual['avgPrice'], "avg, size $size seed $seed");
					self::assertNan($actual['slippageBps'], "bps, size $size seed $seed");
					continue;
				}
				self::assertEqualsWithDelta($avg, $actual['avgPrice'], self::TOLERANCE, "avg, size $size seed $seed");
				self::assertEqualsWithDelta($bps, $actual['slippageBps'], self::TOLERANCE, "bps, size $size seed $seed");
			}
		}
	}

	/**
	 * Values derived by hand. A book offering 1 unit at 100 and 1 unit at 110,
	 * with a mid of 99, filled for a size of 2: the average price is
	 * (100 + 110) / 2 = 105, so the slippage is (105 − 99) / 99 × 10000 bps.
	 *
	 * The notional walk on the same book with a budget of 210 buys 1 unit at
	 * 100 and 1 at 110 as well, but its average is 210 / 2 = 105 too, because
	 * the two happen to coincide when the budget lands exactly on a level
	 * boundary. Shift the budget to 155 and they diverge: it buys 1 unit at 100
	 * and 0.5 at 110, for an average of 155 / 1.5 = 103.33…
	 */
	public function testPinnedFillsFromTheDefinition(): void
	{
		$asks = [100.0, 110.0];
		$askSizes = [1.0, 1.0];
		$mid = 99.0;

		$size = CostToTrade::forSize($asks, $askSizes, $mid, 2.0, true);
		self::assertEqualsWithDelta(105.0, $size['avgPrice'], self::TOLERANCE);
		self::assertEqualsWithDelta((105.0 - 99.0) / 99.0 * 10000.0, $size['slippageBps'], self::TOLERANCE);
		self::assertEqualsWithDelta(1.0, $size['filled'], self::TOLERANCE);

		// a partial fill of the second level
		$partial = CostToTrade::forSize($asks, $askSizes, $mid, 1.5, true);
		self::assertEqualsWithDelta((100.0 + 0.5 * 110.0) / 1.5, $partial['avgPrice'], self::TOLERANCE);

		// the touch alone
		$touch = CostToTrade::forSize($asks, $askSizes, $mid, 1.0, true);
		self::assertEqualsWithDelta(100.0, $touch['avgPrice'], self::TOLERANCE);
	}

	/**
	 * The notional walk averages harmonically: spending a budget buys fewer
	 * units at the dearer level, so its average price is below the arithmetic
	 * mean of the levels it touched.
	 */
	public function testTheNotionalWalkAveragesHarmonically(): void
	{
		// 1 unit at 100, 1 unit at 200; a budget of 300 clears both
		$bids = [50.0];
		$bidSizes = [1000.0];
		$asks = [100.0, 200.0];
		$askSizes = [1.0, 1.0];
		$mid = (50.0 + 100.0) / 2.0;

		// the notional walk buys 1 unit at 100 and 1 at 200 for 300 spent,
		// so its average is 300 / 2 = 150 — the same as the size walk here
		$bps = CostToTrade::forNotional($bids, $bidSizes, $asks, $askSizes, 300.0, true);
		self::assertEqualsWithDelta((150.0 - $mid) / $mid * 10000.0, $bps, self::TOLERANCE);

		// but with a budget of 200 it buys 1 unit at 100 and half a unit at 200:
		// 1.5 units for 200, an average of 133.33, below the arithmetic 150
		$smaller = CostToTrade::forNotional($bids, $bidSizes, $asks, $askSizes, 200.0, true);
		$expected = (200.0 / 1.5 - $mid) / $mid * 10000.0;
		self::assertEqualsWithDelta($expected, $smaller, self::TOLERANCE);
		self::assertLessThan($bps, $smaller);
	}

	/** A bigger order costs more: slippage is monotone in the size traded. */
	public function testSlippageGrowsWithTheOrder(): void
	{
		for ($seed = 1; $seed <= 15; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 17);

			$previous = -\INF;
			foreach ([500.0, 2000.0, 8000.0, 20000.0] as $notional) {
				$bps = CostToTrade::forNotional($bp, $bs, $ap, $as, $notional, true);
				if (\is_nan($bps)) {
					continue;
				}
				self::assertGreaterThanOrEqual($previous - self::TOLERANCE, $bps, "seed $seed, notional $notional");
				$previous = $bps;
			}
		}
	}

	/**
	 * An order larger than the book cannot be priced: the metric reports the
	 * fraction it could fill and no slippage, rather than pretending the
	 * remainder executed at the last level.
	 */
	public function testAnOrderLargerThanTheBookIsUnfilled(): void
	{
		$asks = [100.0];
		$askSizes = [1.0];
		$bids = [99.0];
		$bidSizes = [1.0];

		$result = CostToTrade::forSize($asks, $askSizes, 99.5, 10.0, true);
		self::assertNan($result['slippageBps'], 'the order cannot be filled');
		self::assertNan($result['avgPrice']);
		self::assertEqualsWithDelta(0.1, $result['filled'], self::TOLERANCE, 'one unit of the ten');

		$both = CostToTrade::bothSides($bids, $bidSizes, $asks, $askSizes, 1_000_000.0);
		self::assertNan($both['roundTripBps']);
		self::assertLessThan(1.0, $both['buyFilled']);
	}

	/** The round trip is the two sides added, and it is never negative. */
	public function testTheRoundTripIsTheTwoSidesTogether(): void
	{
		for ($seed = 1; $seed <= 20; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 5);
			$both = CostToTrade::bothSides($bp, $bs, $ap, $as, 5000.0);

			if (\is_nan($both['roundTripBps'])) {
				continue;
			}
			self::assertEqualsWithDelta(
				$both['buyBps'] + $both['sellBps'],
				$both['roundTripBps'],
				self::TOLERANCE,
				"seed $seed",
			);
			self::assertGreaterThan(0.0, $both['roundTripBps'], "seed $seed: crossing both ways costs something");
		}

		$empty = CostToTrade::bothSides([], [], [], [], 1000.0);
		foreach ($empty as $key => $value) {
			self::assertNan($value, $key);
		}
	}

	public function testWarmUpAndReset(): void
	{
		$metric = new CostToTrade(1000.0);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		$metric->updateBook(OrderBook::of(1, [[99.0, 100.0]], [[101.0, 100.0]]));
		self::assertTrue($metric->isReady());
		self::assertTrue(\is_finite($metric->value()));

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['buyBps']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$metric = new CostToTrade(5000.0);

		for ($seed = 1; $seed <= 20; $seed++) {
			[$bp, $bs, $ap, $as] = self::book($seed * 23);

			$bids = [];
			$asks = [];
			foreach ($bp as $i => $price) {
				$bids[] = [$price, $bs[$i]];
				$asks[] = [$ap[$i], $as[$i]];
			}
			$metric->updateBook(OrderBook::of($seed, $bids, $asks));

			$both = CostToTrade::bothSides($bp, $bs, $ap, $as, 5000.0);
			$values = $metric->values();

			foreach (['buyBps', 'sellBps', 'roundTripBps', 'buyFilled', 'sellFilled'] as $key) {
				if (\is_nan($both[$key])) {
					self::assertNan($values[$key], "$key, seed $seed");
				} else {
					self::assertSame($both[$key], $values[$key], "$key, seed $seed");
				}
			}
		}
	}

	public function testNotionalMustBeFiniteAndPositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new CostToTrade(0.0);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		CostToTrade::forSize([1.0, 2.0], [1.0], 1.5, 1.0, true);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new CostToTrade(25000.0);
		self::assertSame(['type' => 'cost-to-trade', 'notional' => 25000.0], $metric->toArray());
		self::assertSame($metric->toArray(), CostToTrade::fromArray($metric->toArray())->toArray());
	}
}
