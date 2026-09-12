<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Volatility\RangeVolatility;
use PHPUnit\Framework\TestCase;

/**
 * Relative price range (M-21) against a naive implementation that rescans the
 * window for its maximum, minimum and mean on every observation.
 *
 * The naive form is deliberately the O(n·w) one: the metric uses
 * `MonotonicWindow` for an O(1) sliding extremum, and that structure is exactly
 * where a defect was found once before — it overwrote the front of its deque
 * when a monotone run filled it, which random data does not reveal. A monotone
 * series of precisely the window length is therefore tested on purpose.
 */
final class RangeVolatilityTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * The prices actually fed to the window: with smoothing > 1 the metric
	 * pre-averages and drops the first `smoothing − 1` observations entirely.
	 *
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function effective(array $prices, int $smoothing): array
	{
		if ($smoothing <= 1) {
			return $prices;
		}
		$out = [];
		foreach ($prices as $i => $_) {
			if ($i + 1 < $smoothing) {
				continue;
			}
			$out[] = \array_sum(\array_slice($prices, $i + 1 - $smoothing, $smoothing)) / $smoothing;
		}
		return $out;
	}

	/**
	 * Naive relative range: (max − min) / mean over the trailing window,
	 * rescanned from scratch.
	 *
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naive(array $prices, int $window, int $smoothing): array
	{
		$effective = self::effective($prices, $smoothing);
		$out = [];

		foreach ($prices as $i => $_) {
			// how many values have reached the window after this observation
			$pushed = $smoothing <= 1 ? $i + 1 : $i - $smoothing + 2;
			if ($pushed < $window) {
				$out[] = \NAN;
				continue;
			}
			$slice = \array_slice($effective, $pushed - $window, $window);
			$mean = \array_sum($slice) / $window;
			[$lowest, $highest] = self::extremes($slice);
			$out[] = $mean > 0.0 ? ($highest - $lowest) / $mean : \NAN;
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

	/** @return list<float> */
	private static function prices(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 300.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-700, 700) / 100.0;
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
		yield 'window 2, no smoothing' => [2, 1];
		yield 'window 10, no smoothing' => [10, 1];
		yield 'window 120, no smoothing' => [120, 1];
		yield 'window 20, smoothing 5' => [20, 5];
		yield 'window 8, smoothing 3' => [8, 3];
	}

	/** @dataProvider configurations */
	public function testMatchesANaiveRescan(int $window, int $smoothing): void
	{
		$prices = self::prices(600, $window * 7 + $smoothing);
		self::assertSeriesMatches(
			self::naive($prices, $window, $smoothing),
			RangeVolatility::compute($prices, $window, $smoothing),
			"compute($window, $smoothing)",
		);
	}

	/**
	 * The sliding extremum on monotone data: a strictly rising run exactly as
	 * long as the window, then a strictly falling one. This is the shape that
	 * exposed the deque defect, and random data almost never produces it.
	 */
	public function testMonotoneRunsOfExactlyTheWindowLength(): void
	{
		foreach ([2, 3, 8, 16, 17, 64] as $window) {
			$prices = [];
			for ($i = 0; $i < $window; $i++) {
				$prices[] = 100.0 + $i;
			}
			for ($i = 0; $i < $window; $i++) {
				$prices[] = 100.0 + $window - $i;
			}
			for ($i = 0; $i < $window; $i++) {
				$prices[] = 100.0 + $i * 2;
			}

			self::assertSeriesMatches(
				self::naive($prices, $window, 1),
				RangeVolatility::compute($prices, $window, 1),
				"monotone runs, window $window",
			);
		}
	}

	/**
	 * The Parkinson factor is 2·√(ln 2) — the constant relating the expected
	 * range of a driftless random walk to its standard deviation.
	 */
	public function testTheParkinsonFactorIsTwoRootLogTwo(): void
	{
		self::assertEqualsWithDelta(
			2.0 * \sqrt(\M_LN2),
			RangeVolatility::PARKINSON_FACTOR,
			1e-15,
			'the published constant must be 2·sqrt(ln 2)',
		);

		// and the conversion is exactly a division by it
		self::assertEqualsWithDelta(0.05 / (2.0 * \sqrt(\M_LN2)), RangeVolatility::toSigma(0.05), self::TOLERANCE);
		self::assertSame(0.0, RangeVolatility::toSigma(0.0));
		self::assertNan(RangeVolatility::toSigma(\NAN), 'an undefined range has no sigma');
	}

	/**
	 * Values derived by hand. Over the window [98, 100, 102] the range is 4 and
	 * the mean 100, so the relative range is exactly 0.04 and σ is 0.04 divided
	 * by the Parkinson factor.
	 */
	public function testPinnedValuesFromTheDefinition(): void
	{
		$prices = [98.0, 100.0, 102.0];

		self::assertEqualsWithDelta(0.04, RangeVolatility::compute($prices, 3, 1)[2], self::TOLERANCE);
		self::assertEqualsWithDelta(
			0.04 / RangeVolatility::PARKINSON_FACTOR,
			RangeVolatility::sigmaSeries($prices, 3, 1)[2],
			self::TOLERANCE,
		);

		self::assertNan(RangeVolatility::compute($prices, 3, 1)[1], 'the window is not full yet');
	}

	/** A flat window has no range at all — exactly zero, and so is its sigma. */
	public function testAFlatWindowGivesExactlyZero(): void
	{
		$flat = \array_fill(0, 60, 55.0);
		$out = RangeVolatility::compute($flat, 20, 1);
		for ($i = 19; $i < 60; $i++) {
			self::assertSame(0.0, $out[$i], "index $i");
		}
		self::assertSame(0.0, RangeVolatility::sigmaSeries($flat, 20, 1)[59]);
	}

	/** Dividing by the level makes the reading dimensionless. */
	public function testTheReadingIsInvariantUnderScaling(): void
	{
		$prices = self::prices(400, 6789);
		$scaled = \array_map(static fn (float $p): float => 250.0 * $p, $prices);

		self::assertSeriesMatches(
			RangeVolatility::compute($prices, 32, 1),
			RangeVolatility::compute($scaled, 32, 1),
			'under scaling',
		);
	}

	/**
	 * What smoothing is for: one stray print must not define the range. A
	 * single tick at twice the price sets the raw range to the full 100, while
	 * averaging over five observations admits only a fifth of it.
	 *
	 * Note that smoothing is not a uniform shrink of the reading — a window of
	 * `window` smoothed values spans `window + smoothing − 1` raw ticks, so it
	 * can cover a wider stretch of the series and report a larger range than the
	 * unsmoothed form at the same index. The guarantee is about the outlier, not
	 * about the reading everywhere.
	 */
	public function testSmoothingStopsASinglePrintFromDefiningTheRange(): void
	{
		$prices = \array_fill(0, 80, 100.0);
		$prices[40] = 200.0;

		$raw = RangeVolatility::compute($prices, 30, 1);
		$smoothed = RangeVolatility::compute($prices, 30, 5);

		// the spike is inside both windows at this point
		self::assertGreaterThan(0.9, $raw[45], 'the raw range is the whole 100-point spike over a ~103 mean');
		self::assertLessThan($raw[45] / 3.0, $smoothed[45], 'averaging five observations admits a fifth of the spike');

		// and once the spike has left both windows, both are back to exactly zero
		self::assertSame(0.0, $raw[79]);
		self::assertSame(0.0, $smoothed[79]);
	}

	/** `values()` exposes the extremes the range is built from. */
	public function testValuesExposeTheExtremesBehindTheRange(): void
	{
		$prices = self::prices(200, 9753);
		$metric = new RangeVolatility(24, 1);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
		}

		$values = $metric->values();
		$window = \array_slice($prices, \count($prices) - 24, 24);
		[$lowest, $highest] = self::extremes($window);
		self::assertEqualsWithDelta($highest, $values['high'], self::TOLERANCE);
		self::assertEqualsWithDelta($lowest, $values['low'], self::TOLERANCE);
		self::assertEqualsWithDelta(
			($values['high'] - $values['low']) / (\array_sum($window) / 24),
			$values['rpr'],
			self::TOLERANCE,
		);
		self::assertEqualsWithDelta($values['rpr'] / RangeVolatility::PARKINSON_FACTOR, $values['sigma'], self::TOLERANCE);
	}

	public function testWarmUpAndReset(): void
	{
		$prices = self::prices(60, 1357);
		$metric = new RangeVolatility(20, 1);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->sigma());

		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			self::assertSame($i >= 19, $metric->isReady(), "tick $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['sigma']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$prices = self::prices(400, 2468);
		$kernel = RangeVolatility::compute($prices, 25, 1);
		$sigma = RangeVolatility::sigmaSeries($prices, 25, 1);

		$metric = new RangeVolatility(25, 1);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			if (\is_nan($kernel[$i])) {
				self::assertNan($metric->value(), "index $i");
				continue;
			}
			self::assertSame($kernel[$i], $metric->value(), "value at $i");
			self::assertSame($sigma[$i], $metric->sigma(), "sigma at $i");
		}
	}

	public function testWindowMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new RangeVolatility(1, 1);
	}

	public function testSmoothingMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new RangeVolatility(20, 0);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new RangeVolatility(48, 4);
		self::assertSame(['type' => 'range-volatility', 'window' => 48, 'smoothing' => 4], $metric->toArray());
		self::assertSame($metric->toArray(), RangeVolatility::fromArray($metric->toArray())->toArray());
	}
}
