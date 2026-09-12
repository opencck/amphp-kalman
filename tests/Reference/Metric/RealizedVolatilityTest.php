<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Volatility\RealizedVolatility;
use PHPUnit\Framework\TestCase;

/**
 * Realised and RiskMetrics EWMA volatility (M-37) against naive
 * implementations built from the definitions.
 *
 * Realised volatility is the root mean square of the log returns over a window
 * — note the *mean square*, not the variance: the mean return is not subtracted,
 * because over short horizons it is noise rather than signal. That distinction
 * is the easiest thing to get wrong here, so it is tested directly against a
 * variance on a series with a deliberate drift.
 *
 * The EWMA form is the RiskMetrics recursion σ²_t = λ·σ²_{t−1} + (1−λ)·r²_t,
 * whose weights are a geometric series summing to one — checked here by
 * expanding the recursion into that series.
 */
final class RealizedVolatilityTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * @param list<float> $prices
	 * @return list<float> log returns, NAN on the first observation
	 */
	private static function naiveReturns(array $prices): array
	{
		$out = [];
		$previous = \NAN;
		foreach ($prices as $price) {
			$out[] = \is_nan($previous) ? \NAN : \log($price) - \log($previous);
			$previous = $price;
		}
		return $out;
	}

	/**
	 * Root mean square of the trailing `window` returns, rescanned each step.
	 *
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveRealized(array $prices, int $window): array
	{
		$returns = self::naiveReturns($prices);
		$out = [];

		foreach ($prices as $i => $_) {
			// the first price yields no return, so `i` returns exist after index i
			$available = $i;
			if ($available < $window) {
				$out[] = \NAN;
				continue;
			}
			$slice = \array_slice($returns, $available - $window + 1, $window);
			$sum = 0.0;
			foreach ($slice as $r) {
				$sum += $r * $r;
			}
			$mean = $sum / $window;
			$out[] = $mean > 0.0 ? \sqrt($mean) : 0.0;
		}
		return $out;
	}

	/**
	 * The RiskMetrics recursion, seeded with the first squared return.
	 *
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveEwma(array $prices, float $lambda): array
	{
		$returns = self::naiveReturns($prices);
		$variance = \NAN;
		$out = [];
		foreach ($returns as $r) {
			if (\is_nan($r)) {
				$out[] = \NAN;
				continue;
			}
			$square = $r * $r;
			$variance = \is_nan($variance) ? $square : $lambda * $variance + (1.0 - $lambda) * $square;
			$out[] = \sqrt($variance);
		}
		return $out;
	}

	/** @return list<float> */
	private static function prices(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 1500.0;
		for ($i = 0; $i < $n; $i++) {
			$price *= \exp((float) \mt_rand(-300, 300) / 100000.0);
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
	public function testRealizedMatchesANaiveRootMeanSquare(int $window): void
	{
		$prices = self::prices(500, 1000 + $window);
		self::assertSeriesMatches(
			self::naiveRealized($prices, $window),
			RealizedVolatility::realized($prices, $window),
			"realized($window)",
		);
	}

	/** @return iterable<string, array{float}> */
	public function lambdas(): iterable
	{
		yield 'RiskMetrics 0.94' => [0.94];
		yield 'fast 0.5' => [0.5];
		yield 'slow 0.99' => [0.99];
	}

	/** @dataProvider lambdas */
	public function testEwmaMatchesTheRiskMetricsRecursion(float $lambda): void
	{
		$prices = self::prices(500, (int) ($lambda * 1000));
		self::assertSeriesMatches(
			self::naiveEwma($prices, $lambda),
			RealizedVolatility::ewma($prices, $lambda),
			"ewma($lambda)",
		);
	}

	public function testLogReturnsMatchANaiveImplementation(): void
	{
		$prices = self::prices(400, 2929);
		self::assertSeriesMatches(
			self::naiveReturns($prices),
			RealizedVolatility::logReturns($prices),
			'logReturns',
		);
	}

	/**
	 * Values derived by hand. A series that doubles then halves gives returns
	 * ln2 and −ln2, whose mean square is (ln2)², so the realised volatility over
	 * that pair is exactly ln 2.
	 */
	public function testPinnedValuesFromTheDefinition(): void
	{
		$prices = [1.0, 2.0, 1.0];

		$returns = RealizedVolatility::logReturns($prices);
		self::assertNan($returns[0]);
		self::assertEqualsWithDelta(\M_LN2, $returns[1], self::TOLERANCE);
		self::assertEqualsWithDelta(-\M_LN2, $returns[2], self::TOLERANCE);

		self::assertEqualsWithDelta(\M_LN2, RealizedVolatility::realized($prices, 2)[2], self::TOLERANCE);

		// the EWMA after the same two returns: seeded at (ln2)², then updated
		// with an identical squared return, so it stays at (ln2)² exactly
		self::assertEqualsWithDelta(\M_LN2, RealizedVolatility::ewma($prices, 0.94)[2], self::TOLERANCE);
	}

	/**
	 * The definition uses the mean *square*, not the variance: the mean return
	 * is not subtracted. On a series with a steady drift the two differ by
	 * exactly the square of that drift, and conflating them would understate
	 * the risk.
	 */
	public function testItIsARootMeanSquareAndNotAStandardDeviation(): void
	{
		// a pure drift: every log return is exactly the same
		$drift = 0.002;
		$prices = [];
		$price = 100.0;
		for ($i = 0; $i < 100; $i++) {
			$prices[] = $price;
			$price *= \exp($drift);
		}

		// every return equals the drift, so the mean square is drift² and the
		// realised volatility is the drift itself — while the variance is zero
		self::assertEqualsWithDelta($drift, RealizedVolatility::realized($prices, 20)[99], self::TOLERANCE);

		$returns = \array_slice(RealizedVolatility::logReturns($prices), 80, 20);
		$mean = \array_sum($returns) / 20;
		$variance = 0.0;
		foreach ($returns as $r) {
			$variance += ($r - $mean) ** 2;
		}
		self::assertEqualsWithDelta(0.0, $variance, 1e-20, 'the sample variance of a constant return is zero');
	}

	/**
	 * The EWMA weights are a geometric series in λ summing to one. Expanding
	 * the recursion from its seed gives the closed form, which is what this
	 * checks — a wrong λ or a swapped (1−λ) would not survive it.
	 */
	public function testTheEwmaWeightsAreAGeometricSeries(): void
	{
		$prices = self::prices(60, 3131);
		$lambda = 0.9;
		$returns = RealizedVolatility::logReturns($prices);

		// r[1] is the first return; the seed is r[1]², after which each step
		// multiplies the accumulated variance by λ and adds (1−λ)·r².
		$squares = [];
		for ($i = 1; $i < \count($returns); $i++) {
			$squares[] = $returns[$i] ** 2;
		}

		$m = \count($squares);
		$expected = $lambda ** ($m - 1) * ($squares[0] ?? \NAN);
		for ($j = 1; $j < $m; $j++) {
			$expected += (1.0 - $lambda) * $lambda ** ($m - 1 - $j) * $squares[$j];
		}

		self::assertEqualsWithDelta(
			\sqrt($expected),
			RealizedVolatility::ewma($prices, $lambda)[\count($prices) - 1] ?? \NAN,
			self::TOLERANCE,
			'the recursion must expand into the geometric series it claims to be',
		);
	}

	/** A smaller λ reacts faster: after a volatility shock it is further along. */
	public function testASmallerLambdaReactsFaster(): void
	{
		// quiet, then a sudden burst
		$prices = [100.0];
		for ($i = 0; $i < 100; $i++) {
			$prices[] = \end($prices) * \exp($i % 2 === 0 ? 0.0001 : -0.0001);
		}
		for ($i = 0; $i < 10; $i++) {
			$prices[] = \end($prices) * \exp($i % 2 === 0 ? 0.05 : -0.05);
		}

		$fast = RealizedVolatility::ewma($prices, 0.5);
		$slow = RealizedVolatility::ewma($prices, 0.99);
		$last = \count($prices) - 1;

		self::assertGreaterThan($slow[$last], $fast[$last], 'a lower lambda must have absorbed more of the burst');
	}

	/** A flat series has no returns and therefore exactly zero volatility. */
	public function testAFlatSeriesGivesExactlyZero(): void
	{
		$flat = \array_fill(0, 50, 20.0);
		self::assertSame(0.0, RealizedVolatility::realized($flat, 20)[49]);
		self::assertSame(0.0, RealizedVolatility::ewma($flat, 0.94)[49]);
	}

	/** Returns are dimensionless, so the reading survives a change of units. */
	public function testTheReadingIsInvariantUnderScaling(): void
	{
		$prices = self::prices(300, 7171);
		$scaled = \array_map(static fn (float $p): float => 0.001 * $p, $prices);

		self::assertSeriesMatches(
			RealizedVolatility::realized($prices, 30),
			RealizedVolatility::realized($scaled, 30),
			'realized under scaling',
		);
		self::assertSeriesMatches(
			RealizedVolatility::ewma($prices, 0.94),
			RealizedVolatility::ewma($scaled, 0.94),
			'ewma under scaling',
		);
	}

	/** Annualising is a √T scaling of the per-observation standard deviation. */
	public function testAnnualisedScalesByTheRootOfTheObservationCount(): void
	{
		$prices = self::prices(200, 6262);
		$metric = new RealizedVolatility(60);
		self::assertNan($metric->annualised(252.0), 'undefined during warm-up');

		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
		}
		self::assertEqualsWithDelta($metric->value() * \sqrt(252.0), $metric->annualised(252.0), self::TOLERANCE);
	}

	public function testWarmUpAndReset(): void
	{
		$prices = self::prices(40, 8484);
		$metric = new RealizedVolatility(20);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->ewmaValue());
		self::assertNan($metric->values()['lastReturn']);

		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			// the first price yields no return, so the window fills one step late
			self::assertSame($i >= 20, $metric->isReady(), "tick $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->ewmaValue());
		self::assertNan($metric->values()['lastReturn']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$prices = self::prices(300, 9393);
		$realized = RealizedVolatility::realized($prices, 30);
		$ewma = RealizedVolatility::ewma($prices, 0.94);

		$metric = new RealizedVolatility(30, 0.94);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			if (\is_nan($realized[$i])) {
				self::assertNan($metric->value(), "realized at $i");
			} else {
				self::assertSame($realized[$i], $metric->value(), "realized at $i");
			}
			if (\is_nan($ewma[$i])) {
				self::assertNan($metric->ewmaValue(), "ewma at $i");
			} else {
				self::assertSame($ewma[$i], $metric->ewmaValue(), "ewma at $i");
			}
		}
	}

	public function testWindowMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new RealizedVolatility(1);
	}

	/** @return iterable<string, array{float}> */
	public function invalidLambdas(): iterable
	{
		yield 'zero' => [0.0];
		yield 'one' => [1.0];
		yield 'above one' => [1.5];
		yield 'negative' => [-0.5];
	}

	/** @dataProvider invalidLambdas */
	public function testLambdaMustBeStrictlyInsideTheUnitInterval(float $lambda): void
	{
		$this->expectException(InvalidArgument::class);
		new RealizedVolatility(60, $lambda);
	}

	public function testPricesMustBeStrictlyPositive(): void
	{
		$this->expectException(InvalidArgument::class);
		RealizedVolatility::realized([1.0, 0.0], 2);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new RealizedVolatility(45, 0.97);
		self::assertSame(['type' => 'realized-volatility', 'window' => 45, 'lambda' => 0.97], $metric->toArray());
		self::assertSame($metric->toArray(), RealizedVolatility::fromArray($metric->toArray())->toArray());
	}
}
