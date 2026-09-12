<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Volume\Vwap;
use PHPUnit\Framework\TestCase;

/**
 * VWAP and its dispersion bands (M-15) against a naive implementation that
 * re-sums the trade tape from scratch.
 *
 * The properties that carry the definition: VWAP is a weighted mean, so it
 * always lies between the cheapest and dearest trade in scope and is unchanged
 * by splitting one trade into several of the same total size; and the
 * dispersion is a volume-weighted standard deviation, which is zero exactly
 * when every trade in scope printed at the same price.
 */
final class VwapTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive VWAP and dispersion over a slice of the tape.
	 *
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return array{float, float} vwap, sigma
	 */
	private static function naiveOver(array $prices, array $sizes): array
	{
		$sumPv = 0.0;
		$sumV = 0.0;
		$sumP2v = 0.0;
		foreach ($prices as $i => $price) {
			$size = $sizes[$i];
			if ($size <= 0.0) {
				continue;
			}
			$sumPv += $price * $size;
			$sumV += $size;
			$sumP2v += $price * $price * $size;
		}
		if (!($sumV > 0.0)) {
			return [\NAN, \NAN];
		}
		$vwap = $sumPv / $sumV;
		$variance = $sumP2v / $sumV - $vwap * $vwap;
		return [$vwap, $variance > 0.0 ? \sqrt($variance) : 0.0];
	}

	/**
	 * Naive series. A zero-size print never enters the sums, and with a rolling
	 * window the window counts only the prints that did.
	 *
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return array{vwap: list<float>, sigma: list<float>}
	 */
	private static function naiveSeries(array $prices, array $sizes, int $window): array
	{
		$vwap = [];
		$sigma = [];

		$keptPrices = [];
		$keptSizes = [];

		foreach ($prices as $i => $price) {
			if ($sizes[$i] > 0.0) {
				$keptPrices[] = $price;
				$keptSizes[] = $sizes[$i];
			}

			// A rolling VWAP reports a partial window rather than waiting: only
			// isReady() insists on a full one, value() needs volume alone.
			if ($window === 0 || \count($keptPrices) <= $window) {
				[$v, $s] = self::naiveOver($keptPrices, $keptSizes);
			} else {
				[$v, $s] = self::naiveOver(
					\array_slice($keptPrices, -$window),
					\array_slice($keptSizes, -$window),
				);
			}

			$vwap[] = $v;
			$sigma[] = \is_nan($v) ? \NAN : $s;
		}
		return ['vwap' => $vwap, 'sigma' => $sigma];
	}

	/**
	 * A deterministic tape with a few zero-size prints mixed in.
	 *
	 * @return array{list<float>, list<float>}
	 */
	private static function tape(int $n, int $seed, bool $withZeroSizes = true): array
	{
		\mt_srand($seed);
		$prices = [];
		$sizes = [];
		$price = 200.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-120, 120) / 100.0;
			$prices[] = $price;
			$sizes[] = $withZeroSizes && $i % 17 === 0 ? 0.0 : (float) \mt_rand(1, 500) / 10.0;
		}
		return [$prices, $sizes];
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
		yield 'anchored' => [0];
		yield 'rolling 2' => [2];
		yield 'rolling 10' => [10];
		yield 'rolling 50' => [50];
	}

	/**
	 * The dispersion is `√(E[p²] − E[p]²)`, two large nearly equal numbers
	 * subtracted. When the window holds a single distinct price the true answer
	 * is zero and what survives is the cancellation residue — around 1e−11 in
	 * the variance, which the square root lifts to a few times 1e−6. Two
	 * implementations cannot be expected to produce the same noise, so below
	 * that floor this only requires both to agree the dispersion is nil.
	 *
	 * Above that floor the same cancellation still sets the achievable
	 * agreement. The variance carries an absolute error of order p²·ε, and the
	 * square root divides it by 2σ, so two orderings of the same sums can differ
	 * by about p²·ε/(2σ) — which for a 200-unit price and a σ of 2e−3 is a few
	 * times 1e−9, above a flat 1e−9 tolerance. The bound below is that
	 * expression with room to spare, not a number picked to make the test pass.
	 *
	 * @param list<float> $expected
	 * @param list<float> $actual
	 * @param list<float> $level the VWAP the dispersion was taken around
	 */
	private static function assertDispersionMatches(array $expected, array $actual, array $level, string $label): void
	{
		self::assertSame(\count($expected), \count($actual), "$label: length");
		foreach ($expected as $i => $want) {
			if (\is_nan($want)) {
				self::assertNan($actual[$i], "$label: index $i expected NAN");
				continue;
			}
			if ($want < 1e-4) {
				self::assertLessThan(1e-4, $actual[$i], "$label: index $i, both must read as numerically zero");
				continue;
			}
			$cancellation = 8.0 * $level[$i] ** 2 * \PHP_FLOAT_EPSILON / (2.0 * $want);
			self::assertEqualsWithDelta(
				$want,
				$actual[$i],
				\max(self::TOLERANCE, $cancellation),
				"$label: index $i",
			);
		}
	}

	/** @dataProvider windows */
	public function testMatchesANaiveResummingOfTheTape(int $window): void
	{
		[$prices, $sizes] = self::tape(400, 500 + $window);
		$naive = self::naiveSeries($prices, $sizes, $window);
		$bands = Vwap::bands($prices, $sizes, $window, 2.0);

		self::assertSeriesMatches($naive['vwap'], $bands['vwap'], "vwap($window)");
		self::assertDispersionMatches($naive['sigma'], $bands['sigma'], $bands['vwap'], "sigma($window)");
	}

	/** The two convenience kernels are the `vwap` row of `bands()`. */
	public function testTheSeriesKernelsAgreeWithBands(): void
	{
		[$prices, $sizes] = self::tape(300, 4321);

		self::assertSeriesMatches(
			Vwap::bands($prices, $sizes, 0, 2.0)['vwap'],
			Vwap::anchoredSeries($prices, $sizes),
			'anchoredSeries',
		);
		self::assertSeriesMatches(
			Vwap::bands($prices, $sizes, 25, 2.0)['vwap'],
			Vwap::rollingSeries($prices, $sizes, 25),
			'rollingSeries',
		);
	}

	/**
	 * Values derived by hand: 10 units at 100 and 30 units at 110 give a VWAP
	 * of (1000 + 3300) / 40 = 107.5. The volume-weighted variance is
	 * 0.25·(100 − 107.5)² + 0.75·(110 − 107.5)² = 14.0625 + 4.6875 = 18.75, so
	 * σ = √18.75, and the ±2σ bands follow.
	 */
	public function testPinnedValuesFromTheDefinition(): void
	{
		$prices = [100.0, 110.0];
		$sizes = [10.0, 30.0];

		$bands = Vwap::bands($prices, $sizes, 0, 2.0);
		self::assertEqualsWithDelta(107.5, $bands['vwap'][1], self::TOLERANCE);
		self::assertEqualsWithDelta(\sqrt(18.75), $bands['sigma'][1], self::TOLERANCE);
		self::assertEqualsWithDelta(107.5 + 2.0 * \sqrt(18.75), $bands['upper'][1], self::TOLERANCE);
		self::assertEqualsWithDelta(107.5 - 2.0 * \sqrt(18.75), $bands['lower'][1], self::TOLERANCE);

		// the relative deviation of the last print from the VWAP
		self::assertEqualsWithDelta(
			(110.0 - 107.5) / 110.0,
			Vwap::relativeDeviation($prices, $sizes, 0)[1],
			self::TOLERANCE,
		);
	}

	/**
	 * A weighted mean lies between its extremes — VWAP can never print outside
	 * the range of the trades it averages.
	 */
	public function testTheVwapAlwaysLiesBetweenTheCheapestAndDearestTrade(): void
	{
		[$prices, $sizes] = self::tape(400, 2211);
		$series = Vwap::anchoredSeries($prices, $sizes);

		$seen = [];
		foreach ($prices as $i => $price) {
			if ($sizes[$i] > 0.0) {
				$seen[] = $price;
			}
			if ($seen === [] || \is_nan($series[$i])) {
				continue;
			}
			self::assertGreaterThanOrEqual(\min($seen) - self::TOLERANCE, $series[$i], "index $i");
			self::assertLessThanOrEqual(\max($seen) + self::TOLERANCE, $series[$i], "index $i");
		}
	}

	/**
	 * Splitting a trade into several prints of the same price and the same
	 * total size cannot move a volume-weighted average.
	 */
	public function testSplittingATradeLeavesTheVwapUnchanged(): void
	{
		$whole = Vwap::bands([100.0, 110.0], [10.0, 30.0], 0, 2.0);
		$split = Vwap::bands(
			[100.0, 110.0, 110.0, 110.0],
			[10.0, 10.0, 10.0, 10.0],
			0,
			2.0,
		);

		self::assertEqualsWithDelta($whole['vwap'][1], $split['vwap'][3], self::TOLERANCE);
		self::assertEqualsWithDelta($whole['sigma'][1], $split['sigma'][3], self::TOLERANCE);
	}

	/** A tape that prints at one price has a VWAP of that price and no dispersion. */
	public function testASinglePriceGivesZeroDispersion(): void
	{
		$prices = \array_fill(0, 30, 64.0);
		$sizes = \array_fill(0, 30, 3.0);

		$bands = Vwap::bands($prices, $sizes, 0, 2.0);
		self::assertSame(64.0, $bands['vwap'][29]);
		self::assertSame(0.0, $bands['sigma'][29]);
		self::assertSame(64.0, $bands['upper'][29]);
		self::assertSame(64.0, $bands['lower'][29]);
	}

	/** A zero-size print moves nothing but the last price the deviation is measured from. */
	public function testZeroSizePrintsDoNotEnterTheAverage(): void
	{
		$withZero = Vwap::anchoredSeries([100.0, 500.0, 110.0], [10.0, 0.0, 30.0]);
		$without = Vwap::anchoredSeries([100.0, 110.0], [10.0, 30.0]);

		self::assertEqualsWithDelta($without[1], $withZero[2], self::TOLERANCE);
		// the 500 print left the average alone despite being five times the price
		self::assertEqualsWithDelta(100.0, $withZero[1], self::TOLERANCE);
	}

	/** Anchoring restarts the accumulation without disturbing the configuration. */
	public function testAnchoringRestartsTheAccumulation(): void
	{
		$metric = Vwap::anchored(2.0);
		foreach ([[100.0, 10.0], [120.0, 10.0]] as [$price, $size]) {
			$metric->updateTrade(Trade::at(1_000_000_000, $price, $size));
		}
		self::assertEqualsWithDelta(110.0, $metric->value(), self::TOLERANCE);

		$metric->anchor(5_000_000_000);
		self::assertSame(5_000_000_000, $metric->anchorTimestampNs());
		self::assertNan($metric->value(), 'nothing has traded since the anchor');
		self::assertFalse($metric->isReady());

		$metric->updateTrade(Trade::at(6_000_000_000, 90.0, 4.0));
		self::assertEqualsWithDelta(90.0, $metric->value(), self::TOLERANCE);
	}

	/** The z-score expresses the last print in units of the dispersion. */
	public function testTheZScoreMeasuresThePrintAgainstTheBands(): void
	{
		$metric = Vwap::anchored(2.0);
		$metric->updateTrade(Trade::at(1, 100.0, 10.0));
		$metric->updateTrade(Trade::at(2, 110.0, 30.0));

		$values = $metric->values();
		self::assertEqualsWithDelta((110.0 - 107.5) / \sqrt(18.75), $values['z'], self::TOLERANCE);
		self::assertEqualsWithDelta((110.0 - 107.5) / 110.0, $values['deviation'], self::TOLERANCE);

		// with no dispersion there is no z-score to speak of
		$flat = Vwap::anchored(2.0);
		$flat->updateTrade(Trade::at(1, 50.0, 1.0));
		self::assertNan($flat->values()['z']);
	}

	public function testWarmUpAndReset(): void
	{
		[$prices, $sizes] = self::tape(60, 1919, false);
		$metric = Vwap::rolling(20, 2.0);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->dispersion());

		foreach ($prices as $i => $price) {
			$metric->updateTrade(Trade::at(($i + 1) * 1_000_000_000, $price, $sizes[$i]));
			self::assertSame($i >= 19, $metric->isReady(), "trade $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['vwap']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		[$prices, $sizes] = self::tape(300, 8642);
		$bands = Vwap::bands($prices, $sizes, 30, 2.0);
		$deviation = Vwap::relativeDeviation($prices, $sizes, 30);

		$metric = Vwap::rolling(30, 2.0);
		foreach ($prices as $i => $price) {
			$metric->updateTrade(Trade::at(($i + 1) * 1_000_000_000, $price, $sizes[$i]));
			$values = $metric->values();

			foreach (['vwap' => $bands['vwap'][$i], 'sigma' => $bands['sigma'][$i], 'deviation' => $deviation[$i]] as $key => $want) {
				if (\is_nan($want)) {
					self::assertNan($values[$key], "$key at $i");
				} else {
					self::assertSame($want, $values[$key], "$key at $i");
				}
			}
		}
	}

	public function testWindowMustNotBeNegative(): void
	{
		$this->expectException(InvalidArgument::class);
		new Vwap(-1, 2.0);
	}

	public function testBandMultiplierMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Vwap(0, 0.0);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		Vwap::bands([1.0, 2.0], [1.0], 0, 2.0);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = Vwap::rolling(40, 1.5);
		self::assertSame(['type' => 'vwap', 'window' => 40, 'bandK' => 1.5], $metric->toArray());
		self::assertSame($metric->toArray(), Vwap::fromArray($metric->toArray())->toArray());
	}
}
