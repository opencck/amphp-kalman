<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Regime\FlatMarket;
use PHPUnit\Framework\TestCase;

/**
 * Flat-market indicator (M-27) against a naive implementation that re-walks the
 * window.
 *
 * Kaufman's efficiency ratio is displacement over path length, and its two
 * extremes are exact rather than approximate: a monotone move travels only as
 * far as it ends up, giving exactly 1, while a series that returns to where it
 * started has zero displacement and gives exactly 0. Both are pinned, along
 * with the fact that the ratio can never leave [0, 1] — the triangle inequality,
 * which is what makes the number a regime reading rather than a scale.
 */
final class FlatMarketTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * @param list<float> $prices
	 * @return array{efficiencyRatio: list<float>, bandWidth: list<float>}
	 */
	private static function naive(array $prices, int $window, float $bandK): array
	{
		$ratio = [];
		$width = [];

		foreach ($prices as $i => $_) {
			// the ratio needs window + 1 prices: window steps between them
			if ($i + 1 < $window + 1) {
				$ratio[] = \NAN;
			} else {
				$slice = \array_slice($prices, $i - $window, $window + 1);
				$displacement = \abs($slice[$window] - $slice[0]);

				$path = 0.0;
				for ($k = 1; $k <= $window; $k++) {
					$path += \abs($slice[$k] - $slice[$k - 1]);
				}
				$ratio[] = $path > 0.0 ? $displacement / $path : 0.0;
			}

			// the band width needs only `window` prices
			if ($i + 1 < $window) {
				$width[] = \NAN;
				continue;
			}
			$levels = \array_slice($prices, $i + 1 - $window, $window);
			$mean = \array_sum($levels) / $window;
			$sq = 0.0;
			foreach ($levels as $v) {
				$sq += ($v - $mean) ** 2;
			}
			$sd = \sqrt($sq / $window);
			$width[] = $mean > 0.0 ? 2.0 * $bandK * $sd / $mean : \NAN;
		}

		return ['efficiencyRatio' => $ratio, 'bandWidth' => $width];
	}

	/** @return list<float> */
	private static function prices(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 150.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-100, 100) / 100.0;
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

	/** @return iterable<string, array{int}> */
	public function windows(): iterable
	{
		yield 'window 2' => [2];
		yield 'window 10' => [10];
		yield 'window 16' => [16];
		yield 'window 17' => [17];
		yield 'window 60' => [60];
	}

	/** @dataProvider windows */
	public function testMatchesANaiveWalkOfTheWindow(int $window): void
	{
		$prices = self::prices(500, 800 + $window);
		$naive = self::naive($prices, $window, 2.0);
		$actual = FlatMarket::compute($prices, $window, 2.0);

		self::assertSeriesMatches($naive['efficiencyRatio'], $actual['efficiencyRatio'], "ratio($window)");
		self::assertSeriesMatches($naive['bandWidth'], $actual['bandWidth'], "bandWidth($window)");
	}

	/** The two convenience kernels are rows of `compute()`. */
	public function testTheConvenienceKernelsAgreeWithCompute(): void
	{
		$prices = self::prices(300, 3690);

		self::assertSeriesMatches(
			FlatMarket::compute($prices, 30, 2.0)['efficiencyRatio'],
			FlatMarket::efficiencyRatio($prices, 30),
			'efficiencyRatio',
		);
		self::assertSeriesMatches(
			FlatMarket::compute($prices, 30, 1.5)['bandWidth'],
			FlatMarket::bandWidth($prices, 30, 1.5),
			'bandWidth',
		);
	}

	/**
	 * A monotone move travels exactly as far as it ends up, so the ratio is
	 * exactly 1 — both rising and falling, since displacement is a magnitude.
	 */
	public function testAMonotoneMoveHasAnEfficiencyOfExactlyOne(): void
	{
		$up = [];
		$down = [];
		for ($i = 0; $i < 100; $i++) {
			$up[] = 100.0 + 0.5 * $i;
			$down[] = 200.0 - 0.5 * $i;
		}

		$upRatio = FlatMarket::efficiencyRatio($up, 20);
		$downRatio = FlatMarket::efficiencyRatio($down, 20);

		for ($i = 20; $i < 100; $i++) {
			self::assertEqualsWithDelta(1.0, $upRatio[$i], self::TOLERANCE, "rising at $i");
			self::assertEqualsWithDelta(1.0, $downRatio[$i], self::TOLERANCE, "falling at $i");
		}

		// even an accelerating move is perfectly efficient: it never turns back
		$accelerating = [];
		$price = 100.0;
		for ($i = 0; $i < 60; $i++) {
			$price += 0.01 * ($i + 1);
			$accelerating[] = $price;
		}
		self::assertEqualsWithDelta(1.0, FlatMarket::efficiencyRatio($accelerating, 20)[59], self::TOLERANCE);
	}

	/**
	 * A price that returns to where it started has no displacement at all, so
	 * the ratio is exactly zero however far it travelled getting there.
	 */
	public function testAClosedPathHasAnEfficiencyOfExactlyZero(): void
	{
		// a sawtooth over an even window returns to its starting level
		$prices = [];
		for ($i = 0; $i < 100; $i++) {
			$prices[] = 100.0 + ($i % 2 === 0 ? 0.0 : 1.0);
		}

		$ratio = FlatMarket::efficiencyRatio($prices, 20);
		for ($i = 20; $i < 100; $i++) {
			self::assertSame(0.0, $ratio[$i], "index $i");
		}
	}

	/**
	 * The triangle inequality bounds the ratio by [0, 1]: a displacement can
	 * never exceed the path taken to achieve it.
	 */
	public function testTheRatioIsBoundedByZeroAndOne(): void
	{
		for ($seed = 1; $seed <= 25; $seed++) {
			foreach ([5, 20, 60] as $window) {
				foreach (FlatMarket::efficiencyRatio(self::prices(300, $seed * 7 + $window), $window) as $i => $value) {
					if (\is_nan($value)) {
						continue;
					}
					self::assertGreaterThanOrEqual(0.0, $value, "seed $seed window $window index $i");
					self::assertLessThanOrEqual(1.0, $value, "seed $seed window $window index $i");
				}
			}
		}
	}

	/**
	 * A window where the price never moved has no path to compare against.
	 * Dividing zero by zero would be undefined, but such a market is flat by
	 * any reading, so the class reports 0 — a decision pinned here because it
	 * is a choice rather than a consequence.
	 */
	public function testAMotionlessWindowReportsFlatRatherThanUndefined(): void
	{
		$flat = \array_fill(0, 60, 42.0);
		$ratio = FlatMarket::efficiencyRatio($flat, 20);

		for ($i = 20; $i < 60; $i++) {
			self::assertSame(0.0, $ratio[$i], "index $i");
		}

		// and its band width is exactly zero, the price never leaving its mean
		self::assertSame(0.0, FlatMarket::bandWidth($flat, 20, 2.0)[59]);
	}

	/**
	 * The band width is 2·k·σ / µ — a relative width, so it survives a change
	 * of units, unlike the raw dispersion.
	 */
	public function testTheBandWidthIsRelativeAndScaleFree(): void
	{
		$prices = self::prices(300, 2580);
		$scaled = \array_map(static fn (float $p): float => 88.0 * $p, $prices);

		self::assertSeriesMatches(
			FlatMarket::bandWidth($prices, 30, 2.0),
			FlatMarket::bandWidth($scaled, 30, 2.0),
			'under scaling',
		);

		// and it scales linearly in k
		$single = FlatMarket::bandWidth($prices, 30, 1.0);
		$double = FlatMarket::bandWidth($prices, 30, 2.0);
		for ($i = 29; $i < 300; $i++) {
			self::assertEqualsWithDelta(2.0 * $single[$i], $double[$i], self::TOLERANCE, "index $i");
		}
	}

	/** `values()` exposes the two quantities the ratio is built from. */
	public function testValuesExposeTheDisplacementAndPathBehindTheRatio(): void
	{
		$metric = new FlatMarket(4, 2.0);
		foreach ([100.0, 101.0, 100.0, 101.0, 102.0] as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
		}

		$values = $metric->values();
		// the window holds 100, 101, 100, 101, 102: displacement 2, path 1+1+1+1 = 4
		self::assertEqualsWithDelta(2.0, $values['displacement'], self::TOLERANCE);
		self::assertEqualsWithDelta(4.0, $values['pathLength'], self::TOLERANCE);
		self::assertEqualsWithDelta(0.5, $values['efficiencyRatio'], self::TOLERANCE);
		self::assertSame($metric->value(), $values['efficiencyRatio']);
	}

	public function testWarmUpAndReset(): void
	{
		$prices = self::prices(60, 1470);
		$metric = new FlatMarket(20, 2.0);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['pathLength']);

		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			// window + 1 prices are needed for `window` steps
			self::assertSame($i >= 20, $metric->isReady(), "tick $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['bandWidth']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$prices = self::prices(300, 9630);
		$kernel = FlatMarket::compute($prices, 25, 2.0);

		$metric = new FlatMarket(25, 2.0);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);

			if (\is_nan($kernel['efficiencyRatio'][$i])) {
				self::assertNan($metric->value(), "ratio at $i");
			} else {
				self::assertSame($kernel['efficiencyRatio'][$i], $metric->value(), "ratio at $i");
			}
			if (\is_nan($kernel['bandWidth'][$i])) {
				self::assertNan($metric->values()['bandWidth'], "width at $i");
			} else {
				self::assertSame($kernel['bandWidth'][$i], $metric->values()['bandWidth'], "width at $i");
			}
		}
	}

	public function testWindowMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new FlatMarket(1, 2.0);
	}

	public function testBandMultiplierMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new FlatMarket(60, 0.0);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new FlatMarket(45, 1.5);
		self::assertSame(['type' => 'flat-market', 'window' => 45, 'bandK' => 1.5], $metric->toArray());
		self::assertSame($metric->toArray(), FlatMarket::fromArray($metric->toArray())->toArray());
	}
}
