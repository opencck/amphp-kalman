<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Liquidity\Spread;
use PHPUnit\Framework\TestCase;

/**
 * The spread in its four measured forms plus Roll's estimator (M-20), against
 * naive implementations.
 *
 * Roll's estimator is the one with real content: it recovers the spread from
 * the trade tape alone, with no book at all, by reading the bid-ask bounce out
 * of the first autocovariance of price changes. A pure bounce of half-spread s
 * around a constant value has autocovariance exactly −s², so the estimator
 * returns exactly the spread — which makes it testable against a hand-built
 * series rather than only against another implementation.
 */
final class SpreadTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive Roll: mean-adjusted first autocovariance of the price changes.
	 *
	 * @param list<float> $prices
	 */
	private static function naiveRoll(array $prices): float
	{
		$n = \count($prices);
		if ($n < 3) {
			return \NAN;
		}
		$changes = [];
		for ($i = 1; $i < $n; $i++) {
			$changes[] = $prices[$i] - $prices[$i - 1];
		}
		$m = \count($changes);
		$mean = \array_sum($changes) / $m;

		$covariance = 0.0;
		for ($i = 1; $i < $m; $i++) {
			$covariance += ($changes[$i] - $mean) * ($changes[$i - 1] - $mean);
		}
		$covariance /= $m - 1;

		return $covariance < 0.0 ? 2.0 * \sqrt(-$covariance) : \NAN;
	}

	/** @return list<float> */
	private static function prices(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 30.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-50, 50) / 1000.0;
			$out[] = $price;
		}
		return $out;
	}

	/**
	 * A pure bid-ask bounce: a constant efficient price with the trade
	 * alternating between bid and ask by a fixed half-spread.
	 *
	 * @return list<float>
	 */
	private static function bounce(float $efficient, float $halfSpread, int $n): array
	{
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$out[] = $efficient + ($i % 2 === 0 ? $halfSpread : -$halfSpread);
		}
		return $out;
	}

	public function testQuotedAndRelativeMatchTheDefinition(): void
	{
		self::assertSame(0.5, Spread::quoted(99.75, 100.25));
		self::assertSame(0.0, Spread::quoted(100.0, 100.0));
		self::assertSame(-1.0, Spread::quoted(100.5, 99.5), 'a crossed book has a negative spread');

		// mid 100, spread 0.5 → 50 bps
		self::assertEqualsWithDelta(50.0, Spread::relativeBps(99.75, 100.25), self::TOLERANCE);
		// mid 1, spread 0.0001 → 1 bp
		self::assertEqualsWithDelta(1.0, Spread::relativeBps(0.99995, 1.00005), self::TOLERANCE);

		self::assertNan(Spread::relativeBps(-1.0, 1.0), 'a non-positive mid has no relative spread');
	}

	/** The relative spread is dimensionless: scaling the book leaves it alone. */
	public function testTheRelativeSpreadIsScaleFree(): void
	{
		for ($seed = 1; $seed <= 30; $seed++) {
			\mt_srand($seed);
			$mid = (float) \mt_rand(1, 100000) / 100.0;
			$half = $mid * (float) \mt_rand(1, 100) / 100000.0;

			self::assertEqualsWithDelta(
				Spread::relativeBps($mid - $half, $mid + $half),
				Spread::relativeBps(1000.0 * ($mid - $half), 1000.0 * ($mid + $half)),
				self::TOLERANCE,
				"seed $seed",
			);
		}
	}

	/**
	 * The effective spread is twice the distance from the mid: a trade that
	 * crosses a 0.5-wide spread pays 0.25 against the mid, so the round trip is
	 * the full 0.5.
	 */
	public function testTheEffectiveSpreadIsTwiceTheDistanceFromTheMid(): void
	{
		$out = Spread::effective([100.25, 99.75, 100.0], [100.0, 100.0, 100.0]);

		self::assertEqualsWithDelta(0.5, $out[0], self::TOLERANCE, 'a buy at the offer');
		self::assertEqualsWithDelta(0.5, $out[1], self::TOLERANCE, 'a sell at the bid costs the same');
		self::assertSame(0.0, $out[2], 'a trade at the mid pays nothing');

		// it is a magnitude: the side does not change it
		$naive = [];
		$prices = self::prices(200, 5150);
		$mids = self::prices(200, 5151);
		foreach ($prices as $i => $price) {
			$naive[] = 2.0 * \abs($price - $mids[$i]);
		}
		foreach (Spread::effective($prices, $mids) as $i => $value) {
			self::assertEqualsWithDelta($naive[$i], $value, self::TOLERANCE, "index $i");
			self::assertGreaterThanOrEqual(0.0, $value, "index $i");
		}
	}

	/**
	 * The realised spread is what the liquidity provider keeps once the price
	 * has moved: signed, so it goes negative when the trade was informed.
	 */
	public function testTheRealisedSpreadIsSignedAndGoesNegativeOnAnInformedTrade(): void
	{
		// bought at 100.25, and five minutes later the mid is still 100
		self::assertEqualsWithDelta(0.5, Spread::realized([100.25], [1.0], [100.0])[0], self::TOLERANCE);

		// bought at 100.25 and the mid ran to 101: the maker lost
		self::assertEqualsWithDelta(2.0 * (100.25 - 101.0), Spread::realized([100.25], [1.0], [101.0])[0], self::TOLERANCE);
		self::assertLessThan(0.0, Spread::realized([100.25], [1.0], [101.0])[0]);

		// a sell is the mirror image
		self::assertEqualsWithDelta(0.5, Spread::realized([99.75], [-1.0], [100.0])[0], self::TOLERANCE);
	}

	/**
	 * Roll's estimator on a pure bounce, where it has a closed form.
	 *
	 * With the efficient price constant and the trade alternating ±s around it,
	 * consecutive price changes are +2s, −2s, +2s … Every product of neighbours
	 * is then −4s², so the autocovariance is −4s² and the estimator returns
	 * 2·√(4s²) = 4s.
	 *
	 * The series length must be odd for that to be exact: the changes telescope
	 * to the difference between the last price and the first, which is zero only
	 * when both land on the same side of the bounce. With an even length the
	 * mean change is −2s/m instead of zero, and the mean adjustment shrinks the
	 * reading by a factor √(1 − 1/m²) — about 13 parts per million over 200
	 * prints, small but not zero, and worth knowing before reading a Roll
	 * estimate off a short sample.
	 */
	public function testRollHasAClosedFormOnAPureBounce(): void
	{
		foreach ([0.01, 0.05, 0.25] as $half) {
			$prices = self::bounce(100.0, $half, 201);

			self::assertEqualsWithDelta(
				self::naiveRoll($prices),
				Spread::roll($prices),
				self::TOLERANCE,
				"half-spread $half",
			);
			self::assertEqualsWithDelta(4.0 * $half, Spread::roll($prices), self::TOLERANCE, "half-spread $half");
		}

		// the even-length sample, and the finite-sample factor it comes with
		$even = self::bounce(100.0, 0.01, 200);
		$m = 199;
		self::assertEqualsWithDelta(
			4.0 * 0.01 * \sqrt(1.0 - 1.0 / ($m * $m)),
			Spread::roll($even),
			self::TOLERANCE,
			'an even sample carries a mean-adjustment factor',
		);
	}

	/** Roll agrees with a naive implementation on ordinary noisy data too. */
	public function testRollMatchesANaiveImplementationOnNoisyData(): void
	{
		for ($seed = 1; $seed <= 40; $seed++) {
			$prices = self::prices(300, $seed * 13);

			$naive = self::naiveRoll($prices);
			$actual = Spread::roll($prices);

			if (\is_nan($naive)) {
				self::assertNan($actual, "seed $seed");
				continue;
			}
			self::assertEqualsWithDelta($naive, $actual, self::TOLERANCE, "seed $seed");
		}
	}

	/**
	 * A trending market has a positive autocovariance, where the estimator has
	 * no real root. Reporting zero there would claim a market with no spread;
	 * the class returns NAN instead, and that choice is pinned here.
	 */
	public function testATrendingSeriesLeavesRollUndefined(): void
	{
		// Price changes in runs of three: +,+,+,−,−,−. Over one period the
		// products of neighbours are 1, 1, −1, 1, 1, −1, which sum to +2, so the
		// autocovariance is genuinely positive and the estimator has no root.
		$trending = [50.0];
		for ($i = 0; $i < 180; $i++) {
			$trending[] = \end($trending) + (\intdiv($i, 3) % 2 === 0 ? 0.1 : -0.1);
		}
		self::assertNan(Spread::roll($trending), 'momentum in the changes leaves no bounce to measure');

		// a flat series has no changes at all, hence no covariance either
		self::assertNan(Spread::roll(\array_fill(0, 50, 7.0)));

		// and too short a series cannot be estimated
		self::assertNan(Spread::roll([1.0, 2.0]));
		self::assertNan(Spread::roll([]));
	}

	/**
	 * An exact linear ramp is the boundary case: every change is identical, so
	 * every mean-adjusted deviation is zero and the true autocovariance is zero
	 * rather than positive. Which side of zero the rounding lands on decides
	 * between NAN and a reading at the 1e−15 scale, so neither is asserted —
	 * only that the estimator cannot report a spread of any consequence.
	 */
	public function testAnExactRampSitsOnTheBoundaryOfTheEstimator(): void
	{
		$ramp = [];
		for ($i = 0; $i < 100; $i++) {
			$ramp[] = 50.0 + 0.1 * $i;
		}

		$roll = Spread::roll($ramp);
		self::assertTrue(
			\is_nan($roll) || $roll < 1e-12,
			'a ramp must yield either no estimate or a numerically zero one, got ' . $roll,
		);
	}

	/** The rolling average of the relative spread is a mean of what it saw. */
	public function testTheAverageIsTheMeanOfTheRelativeSpreadsObserved(): void
	{
		$metric = new Spread(3);

		$observed = [];
		foreach ([[99.75, 100.25], [99.5, 100.5], [99.9, 100.1], [99.0, 101.0]] as $k => [$bid, $ask]) {
			$metric->updateBook(OrderBook::of($k + 1, [[$bid, 1.0]], [[$ask, 1.0]]));
			$observed[] = Spread::relativeBps($bid, $ask);
		}

		$lastThree = \array_slice($observed, -3);
		self::assertEqualsWithDelta(
			\array_sum($lastThree) / 3.0,
			$metric->values()['averageBps'],
			self::TOLERANCE,
		);
		self::assertEqualsWithDelta($observed[3], $metric->value(), self::TOLERANCE);
		self::assertEqualsWithDelta(2.0, $metric->values()['quoted'], self::TOLERANCE);
	}

	/** An empty book has no spread to quote. */
	public function testAnEmptyBookIsUndefined(): void
	{
		$metric = new Spread(10);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		$metric->updateBook(OrderBook::of(1, [[99.0, 1.0]], [[101.0, 1.0]]));
		self::assertTrue($metric->isReady());

		$metric->updateBook(OrderBook::of(2, [], []));
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
	}

	public function testWarmUpAndReset(): void
	{
		$metric = new Spread(5);
		self::assertNan($metric->values()['averageBps'], 'nothing observed yet');

		for ($i = 0; $i < 10; $i++) {
			$metric->updateBook(OrderBook::of($i + 1, [[99.9, 1.0]], [[100.1, 1.0]]));
		}
		self::assertTrue($metric->isReady());
		self::assertTrue(\is_finite($metric->values()['averageBps']));

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['averageBps']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$metric = new Spread(20);

		for ($i = 0; $i < 50; $i++) {
			\mt_srand($i + 700);
			$mid = 200.0 + (float) \mt_rand(-100, 100) / 100.0;
			$half = (float) \mt_rand(1, 50) / 1000.0;
			$bid = $mid - $half;
			$ask = $mid + $half;

			$metric->updateBook(OrderBook::of($i + 1, [[$bid, 1.0]], [[$ask, 1.0]]));

			self::assertEqualsWithDelta(Spread::quoted($bid, $ask), $metric->values()['quoted'], self::TOLERANCE, "quoted at $i");
			self::assertEqualsWithDelta(Spread::relativeBps($bid, $ask), $metric->value(), self::TOLERANCE, "relative at $i");
		}
	}

	public function testWindowMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Spread(0);
	}

	public function testEffectiveNeedsOneMidPerTrade(): void
	{
		$this->expectException(InvalidArgument::class);
		Spread::effective([1.0, 2.0], [1.0]);
	}

	public function testRealizedNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		Spread::realized([1.0, 2.0], [1.0], [1.0, 2.0]);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new Spread(250);
		self::assertSame(['type' => 'spread', 'window' => 250], $metric->toArray());
		self::assertSame($metric->toArray(), Spread::fromArray($metric->toArray())->toArray());
	}
}
