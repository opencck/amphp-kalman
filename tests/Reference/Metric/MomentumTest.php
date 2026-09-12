<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Momentum\Momentum;
use PHPUnit\Framework\TestCase;

/**
 * Normalised momentum (M-09) against a naive implementation that slices the
 * window out of the input array and sums it twice.
 *
 * `Momentum::normalized()` drives the streaming object, per ADR-011, so
 * comparing the two would prove nothing. The naive implementation here shares
 * no code with either: it rebuilds the window, the block returns, the mean and
 * the sample standard deviation from the raw prices.
 *
 * The defining property is that the reading is a ratio of a return to its own
 * dispersion, so it is invariant under scaling the prices and — the point of
 * the metric — comparable between instruments of very different volatility.
 */
final class MomentumTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive block returns over the trailing window: K non-overlapping log
	 * returns, oldest block first.
	 *
	 * @param list<float> $window exactly step * count + 1 prices
	 * @return list<float>
	 */
	private static function naiveBlockReturns(array $window, int $step, int $count): array
	{
		$out = [];
		for ($k = 0; $k < $count; $k++) {
			$older = $window[$step * $k];
			$newer = $window[$step * ($k + 1)];
			$out[] = $newer > 0.0 && $older > 0.0 ? \log($newer / $older) : \NAN;
		}
		return $out;
	}

	/**
	 * Naive NAPM: mean over sample standard deviation of the block returns.
	 *
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naive(array $prices, int $step, int $count): array
	{
		$needed = $step * $count + 1;
		$out = [];
		foreach ($prices as $i => $_) {
			if ($i + 1 < $needed) {
				$out[] = \NAN;
				continue;
			}
			$window = \array_slice($prices, $i + 1 - $needed, $needed);
			$returns = self::naiveBlockReturns($window, $step, $count);

			$sum = 0.0;
			foreach ($returns as $r) {
				$sum += $r;
			}
			$mean = $sum / $count;

			$sq = 0.0;
			foreach ($returns as $r) {
				$sq += ($r - $mean) ** 2;
			}
			$sd = \sqrt($sq / ($count - 1));

			$out[] = $sd > 0.0 ? $mean / $sd : \NAN;
		}
		return $out;
	}

	/**
	 * Smallest and largest of a series, without max()/min(): they require a
	 * non-empty array, which a `list<float>` does not promise.
	 *
	 * @param list<float> $values
	 * @return array{float, float} lowest, highest
	 */
	private static function extremes(array $values): array
	{
		$lowest = \INF;
		$highest = -\INF;
		foreach ($values as $value) {
			if ($value < $lowest) {
				$lowest = $value;
			}
			if ($value > $highest) {
				$highest = $value;
			}
		}
		return [$lowest, $highest];
	}

	/**
	 * A price path from a sequence of log returns, each scaled by a factor.
	 *
	 * @param list<float> $shape
	 * @return list<float>
	 */
	private static function pathFrom(array $shape, float $factor): array
	{
		$prices = [100.0];
		$price = 100.0;
		foreach ($shape as $r) {
			$price *= \exp($r * $factor);
			$prices[] = $price;
		}
		return $prices;
	}

	/** @return list<float> */
	private static function prices(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 80.0;
		for ($i = 0; $i < $n; $i++) {
			$price *= 1.0 + (float) \mt_rand(-400, 420) / 100000.0;
			$out[] = $price;
		}
		return $out;
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

	/** @return iterable<string, array{int, int}> */
	public function configurations(): iterable
	{
		yield 'step 1, 2 blocks' => [1, 2];
		yield 'step 1, 8 blocks' => [1, 8];
		yield 'step 7, 3 blocks' => [7, 3];
		yield 'step 60, 8 blocks (defaults)' => [60, 8];
	}

	/** @dataProvider configurations */
	public function testMatchesANaiveImplementation(int $step, int $count): void
	{
		$prices = self::prices(700, $step * 13 + $count);
		self::assertSeriesMatches(
			self::naive($prices, $step, $count),
			Momentum::normalized($prices, $step, $count),
			"normalized($step, $count)",
		);
	}

	/** @dataProvider configurations */
	public function testBlockReturnsMatchANaiveImplementation(int $step, int $count): void
	{
		$prices = self::prices(700, $step * 17 + $count);
		$needed = $step * $count + 1;

		self::assertSeriesMatches(
			self::naiveBlockReturns(\array_slice($prices, \count($prices) - $needed, $needed), $step, $count),
			Momentum::blockReturns($prices, $step, $count),
			"blockReturns($step, $count)",
		);
	}

	/**
	 * A value derived by hand. With step 1 and three blocks, the prices
	 * 1 → 2 → 8 → 16 give block returns ln 2, ln 4, ln 2, whose mean is
	 * 4·ln2/3. The deviations are −ln2/3, +2·ln2/3, −ln2/3, so the sample
	 * variance is (ln2)²/3 and the standard deviation ln2/√3. The ratio is
	 * therefore (4·ln2/3) · (√3/ln2) = 4/√3, with the logarithms cancelling.
	 */
	public function testPinnedValueFromTheDefinition(): void
	{
		$prices = [1.0, 2.0, 8.0, 16.0];

		$returns = Momentum::blockReturns($prices, 1, 3);
		self::assertEqualsWithDelta(\log(2.0), $returns[0], self::TOLERANCE);
		self::assertEqualsWithDelta(\log(4.0), $returns[1], self::TOLERANCE);
		self::assertEqualsWithDelta(\log(2.0), $returns[2], self::TOLERANCE);

		self::assertEqualsWithDelta(4.0 / \sqrt(3.0), Momentum::normalized($prices, 1, 3)[3], self::TOLERANCE);
	}

	/**
	 * The block returns telescope: they are consecutive and non-overlapping, so
	 * they add up to the log return across the whole window. This is what makes
	 * the dispersion across them an honest estimate — unlike the overlapping
	 * construction in M-04.
	 */
	public function testBlockReturnsTelescopeToTheWholeWindow(): void
	{
		$prices = self::prices(400, 9182);
		$step = 9;
		$count = 5;
		$needed = $step * $count + 1;

		$returns = Momentum::blockReturns($prices, $step, $count);
		$total = 0.0;
		foreach ($returns as $r) {
			$total += $r;
		}

		$window = \array_slice($prices, \count($prices) - $needed, $needed);
		self::assertEqualsWithDelta(
			\log($window[$needed - 1] / $window[0]),
			$total,
			self::TOLERANCE,
			'the blocks must tile the window exactly',
		);
	}

	/**
	 * A flat series has zero return and exactly zero dispersion, so the ratio
	 * is undefined rather than infinite — the degenerate case the class
	 * resolves explicitly.
	 */
	public function testAFlatSeriesLeavesTheRatioUndefined(): void
	{
		$flat = \array_fill(0, 40, 7.0);

		$out = Momentum::normalized($flat, 3, 4);
		for ($i = 12; $i < 40; $i++) {
			self::assertNan($out[$i], "index $i");
		}

		$metric = new Momentum(3, 4);
		foreach ($flat as $price) {
			$metric->updatePrice(0, $price);
		}
		self::assertSame(0.0, $metric->values()['sd'], 'the dispersion must be exactly zero, not merely small');
		self::assertSame(0.0, $metric->values()['mean']);
	}

	/**
	 * Constant exponential growth is the same story in exact arithmetic — every
	 * block return is ln(1.05) × step — but not in floating point: the returns
	 * come out differing in the last couple of ULP.
	 *
	 * This test pins the part that is definitionally true, namely that the
	 * returns agree and the dispersion is numerically zero. It deliberately does
	 * not pin the ratio: with a mean of ~1.5e−1 over a dispersion of ~1.6e−16
	 * the metric reports ~9e14, because the `sd > 0` guard cannot distinguish a
	 * genuinely tiny dispersion from rounding noise. That is a real edge in the
	 * metric rather than a property worth asserting, and a caller thresholding
	 * on NAPM should know a perfectly steady trend can produce an enormous
	 * finite reading rather than a NAN.
	 */
	public function testConstantGrowthGivesEqualBlockReturnsAndANumericallyZeroDispersion(): void
	{
		$prices = [];
		$price = 10.0;
		for ($i = 0; $i < 40; $i++) {
			$prices[] = $price;
			$price *= 1.05;
		}

		$returns = Momentum::blockReturns($prices, 3, 4);
		foreach ($returns as $k => $r) {
			self::assertEqualsWithDelta(3.0 * \log(1.05), $r, self::TOLERANCE, "block $k");
		}
		[$lowest, $highest] = self::extremes($returns);
		self::assertLessThan(1e-15, $highest - $lowest, 'the returns differ only by rounding');

		$metric = new Momentum(3, 4);
		foreach ($prices as $p) {
			$metric->updatePrice(0, $p);
		}
		self::assertLessThan(1e-15, $metric->values()['sd'], 'the dispersion is zero to within rounding');
	}

	/**
	 * The ratio is dimensionless twice over: scaling the prices changes neither
	 * the block returns nor their dispersion.
	 */
	public function testTheRatioIsInvariantUnderScaling(): void
	{
		$prices = self::prices(300, 6161);
		$scaled = \array_map(static fn (float $p): float => 9999.0 * $p, $prices);

		self::assertSeriesMatches(
			Momentum::normalized($prices, 5, 6),
			Momentum::normalized($scaled, 5, 6),
			'under scaling',
		);
	}

	/**
	 * The comparability claim, tested rather than asserted: the same shape of
	 * path at two very different volatilities gives the same reading, which a
	 * raw average return would not.
	 */
	public function testTheReadingIsComparableAcrossVolatilityRegimes(): void
	{
		$step = 4;
		$count = 5;
		$n = $step * $count + 1;

		// the same sequence of log returns, scaled up by 50x in the second path
		\mt_srand(4242);
		$shape = [];
		for ($i = 0; $i < $n - 1; $i++) {
			$shape[] = (float) \mt_rand(-100, 140) / 100000.0;
		}

		$calm = Momentum::normalized(self::pathFrom($shape, 1.0), $step, $count);
		$wild = Momentum::normalized(self::pathFrom($shape, 50.0), $step, $count);

		self::assertEqualsWithDelta(
			$calm[$n - 1],
			$wild[$n - 1],
			self::TOLERANCE,
			'scaling every log return by the same factor scales mean and sd alike, so the ratio holds',
		);
	}

	public function testWarmUpAndReset(): void
	{
		$prices = self::prices(40, 5150);
		$metric = new Momentum(3, 4);
		$needed = 3 * 4 + 1;

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['sd']);

		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			self::assertSame($i + 1 >= $needed, $metric->isReady(), "tick $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['mean']);
	}

	/** `values()` reports the two halves of the ratio it returns. */
	public function testValuesReportTheMeanAndDispersionBehindTheRatio(): void
	{
		$prices = self::prices(200, 3060);
		$metric = new Momentum(5, 6);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
		}

		$values = $metric->values();
		self::assertEqualsWithDelta($values['mean'] / $values['sd'], $values['napm'], self::TOLERANCE);
		self::assertSame($metric->value(), $values['napm']);
		self::assertGreaterThan(0.0, $values['sd']);
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		$prices = self::prices(300, 1717);
		$kernel = Momentum::normalized($prices, 6, 4);

		$metric = new Momentum(6, 4);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			if (\is_nan($kernel[$i])) {
				self::assertNan($metric->value(), "index $i");
			} else {
				self::assertSame($kernel[$i], $metric->value(), "index $i");
			}
		}
	}

	public function testStepMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Momentum(0, 8);
	}

	public function testAtLeastTwoBlocksAreNeededForADispersion(): void
	{
		$this->expectException(InvalidArgument::class);
		new Momentum(60, 1);
	}

	public function testBlockReturnsRejectAnInsufficientHistory(): void
	{
		$this->expectException(InvalidArgument::class);
		Momentum::blockReturns([1.0, 2.0, 3.0], 5, 4);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new Momentum(30, 5);
		self::assertSame(['type' => 'normalized-momentum', 'step' => 30, 'count' => 5], $metric->toArray());
		self::assertSame($metric->toArray(), Momentum::fromArray($metric->toArray())->toArray());
	}
}
