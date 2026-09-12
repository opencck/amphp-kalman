<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Price\PriceDelta;
use PHPUnit\Framework\TestCase;

/**
 * Price delta (M-03) against a naive implementation that indexes the input
 * directly instead of keeping a ring buffer.
 *
 * The property worth testing is the one the docblock recommends the log form
 * for: log returns add up across horizons, simple returns do not. A test that
 * only compared two implementations of the same subtraction would not catch a
 * confusion between the two forms; this one does.
 */
final class PriceDeltaTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveLog(array $prices, int $lag): array
	{
		$out = [];
		$n = \count($prices);
		for ($i = 0; $i < $n; $i++) {
			$back = $i - $lag;
			if ($back < 0) {
				$out[] = \NAN;
				continue;
			}
			$past = $prices[$back];
			$out[] = $prices[$i] > 0.0 && $past > 0.0 ? \log($prices[$i]) - \log($past) : \NAN;
		}
		return $out;
	}

	/**
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveSimple(array $prices, int $lag): array
	{
		$out = [];
		$n = \count($prices);
		for ($i = 0; $i < $n; $i++) {
			$back = $i - $lag;
			if ($back < 0) {
				$out[] = \NAN;
				continue;
			}
			$past = $prices[$back];
			$out[] = $past > 0.0 ? ($prices[$i] - $past) / $past : \NAN;
		}
		return $out;
	}

	/** @return list<float> */
	private static function prices(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 250.0;
		for ($i = 0; $i < $n; $i++) {
			$price *= 1.0 + (float) \mt_rand(-300, 300) / 100000.0;
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
	public function lags(): iterable
	{
		yield 'lag 1' => [1];
		yield 'lag 2' => [2];
		yield 'lag 5' => [5];
		yield 'lag 30' => [30];
	}

	/** @dataProvider lags */
	public function testLogMatchesANaiveImplementation(int $lag): void
	{
		$prices = self::prices(500, 600 + $lag);
		self::assertSeriesMatches(self::naiveLog($prices, $lag), PriceDelta::log($prices, $lag), "log($lag)");
	}

	/** @dataProvider lags */
	public function testSimpleMatchesANaiveImplementation(int $lag): void
	{
		$prices = self::prices(500, 700 + $lag);
		self::assertSeriesMatches(self::naiveSimple($prices, $lag), PriceDelta::simple($prices, $lag), "simple($lag)");
	}

	/**
	 * Values derived by hand: 100 → 110 is a simple return of exactly 0.1 and a
	 * log return of ln(1.1); 100 → 50 is −0.5 and −ln 2.
	 */
	public function testPinnedValuesFromTheDefinition(): void
	{
		self::assertEqualsWithDelta(0.1, PriceDelta::simple([100.0, 110.0], 1)[1], self::TOLERANCE);
		self::assertEqualsWithDelta(\log(1.1), PriceDelta::log([100.0, 110.0], 1)[1], self::TOLERANCE);

		self::assertEqualsWithDelta(-0.5, PriceDelta::simple([100.0, 50.0], 1)[1], self::TOLERANCE);
		self::assertEqualsWithDelta(-\M_LN2, PriceDelta::log([100.0, 50.0], 1)[1], self::TOLERANCE);

		// no return is defined before `lag` observations have gone by
		self::assertNan(PriceDelta::log([100.0, 110.0], 1)[0]);
	}

	/**
	 * The reason to prefer the log form: returns over consecutive steps add up
	 * to the return over the whole span. The simple return does not — it
	 * compounds, and the gap is the product term.
	 */
	public function testLogReturnsAddUpAcrossHorizonsAndSimpleOnesDoNot(): void
	{
		$prices = self::prices(200, 3301);

		$oneStep = PriceDelta::log($prices, 1);
		$threeStep = PriceDelta::log($prices, 3);

		for ($i = 3; $i < \count($prices); $i++) {
			self::assertEqualsWithDelta(
				$oneStep[$i] + $oneStep[$i - 1] + $oneStep[$i - 2],
				$threeStep[$i],
				self::TOLERANCE,
				"log returns must telescope at index $i",
			);
		}

		// the simple return compounds instead: (1+r1)(1+r2)(1+r3) − 1
		$simpleOne = PriceDelta::simple($prices, 1);
		$simpleThree = PriceDelta::simple($prices, 3);
		for ($i = 3; $i < \count($prices); $i++) {
			$compounded = (1.0 + $simpleOne[$i]) * (1.0 + $simpleOne[$i - 1]) * (1.0 + $simpleOne[$i - 2]) - 1.0;
			self::assertEqualsWithDelta($compounded, $simpleThree[$i], self::TOLERANCE, "compounding at $i");
		}

		// and summing them is simply wrong, which is the mistake being guarded against
		$naiveSum = $simpleOne[50] + $simpleOne[49] + $simpleOne[48];
		self::assertNotEqualsWithDelta($naiveSum, $simpleThree[50], 1e-12);
	}

	/** log = ln(1 + simple), the identity relating the two forms. */
	public function testTheTwoFormsAreRelatedByLogOfOnePlusTheReturn(): void
	{
		$prices = self::prices(200, 4402);
		$log = PriceDelta::log($prices, 4);
		$simple = PriceDelta::simple($prices, 4);

		for ($i = 4; $i < \count($prices); $i++) {
			self::assertEqualsWithDelta(\log(1.0 + $simple[$i]), $log[$i], self::TOLERANCE, "index $i");
		}
	}

	/** A return is dimensionless: scaling every price leaves it unchanged. */
	public function testReturnsAreInvariantUnderScaling(): void
	{
		$prices = self::prices(200, 5503);
		$scaled = \array_map(static fn (float $p): float => 1234.5 * $p, $prices);

		self::assertSeriesMatches(PriceDelta::log($prices, 3), PriceDelta::log($scaled, 3), 'log under scaling');
		self::assertSeriesMatches(PriceDelta::simple($prices, 3), PriceDelta::simple($scaled, 3), 'simple under scaling');
	}

	/** A flat series has no return at all, exactly zero rather than near it. */
	public function testAFlatSeriesGivesExactlyZero(): void
	{
		$flat = \array_fill(0, 20, 42.0);
		foreach (PriceDelta::log($flat, 2) as $i => $value) {
			if ($i >= 2) {
				self::assertSame(0.0, $value, "log at $i");
			}
		}
		foreach (PriceDelta::simple($flat, 2) as $i => $value) {
			if ($i >= 2) {
				self::assertSame(0.0, $value, "simple at $i");
			}
		}
	}

	/** A non-positive price has no logarithm; a non-positive base has no return. */
	public function testNonPositivePricesAreUndefined(): void
	{
		self::assertNan(PriceDelta::log([0.0, 10.0], 1)[1], 'zero base');
		self::assertNan(PriceDelta::log([10.0, -1.0], 1)[1], 'negative price');
		self::assertNan(PriceDelta::simple([0.0, 10.0], 1)[1], 'zero base has no simple return either');
	}

	public function testWarmUpAndReset(): void
	{
		$prices = self::prices(20, 6604);
		$metric = new PriceDelta(3);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			self::assertSame($i >= 3, $metric->isReady(), "tick $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['simple']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$prices = self::prices(300, 7705);
		$log = PriceDelta::log($prices, 7);
		$simple = PriceDelta::simple($prices, 7);

		$metric = new PriceDelta(7);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			$values = $metric->values();

			if (\is_nan($log[$i])) {
				self::assertNan($values['log'], "log at $i");
				self::assertNan($values['simple'], "simple at $i");
				continue;
			}
			self::assertSame($log[$i], $values['log'], "log at $i");
			self::assertSame($simple[$i], $values['simple'], "simple at $i");
			self::assertSame($log[$i], $metric->value(), "value() is the log form at $i");
		}
	}

	public function testLagMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new PriceDelta(0);
	}

	public function testKernelRejectsANonPositiveLag(): void
	{
		$this->expectException(InvalidArgument::class);
		PriceDelta::log([1.0, 2.0], 0);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new PriceDelta(12);
		self::assertSame(['type' => 'price-delta', 'lag' => 12], $metric->toArray());
		self::assertSame($metric->toArray(), PriceDelta::fromArray($metric->toArray())->toArray());
	}
}
