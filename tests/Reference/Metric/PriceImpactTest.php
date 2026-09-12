<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Microstructure\PriceImpact;
use PHPUnit\Framework\TestCase;

/**
 * Price impact (M-31) — Kyle's λ and Amihud's illiquidity — against naive
 * implementations.
 *
 * Kyle's λ is the slope of a regression of price change on signed order flow,
 * through the origin: no flow, no move. That makes it Σ(q·Δp) / Σq², which is
 * exactly recoverable on a synthetic market built to a known λ — feed it
 * Δp = λ·q and the estimate must come back as λ, whatever the flow looks like.
 * That is the strongest test available for this metric and it is the one used
 * here.
 *
 * Amihud is a different quantity with different units: the average of
 * |return| / notional, which is per currency traded rather than per unit of
 * signed flow. The two are checked separately so a confusion between them
 * cannot pass.
 */
final class PriceImpactTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * @param list<float> $prices
	 * @param list<float> $signedSizes
	 * @return array{lambda: list<float>, amihud: list<float>}
	 */
	private static function naive(array $prices, array $signedSizes, int $window): array
	{
		/** @var list<float> $cross */
		$cross = [];
		/** @var list<float> $square */
		$square = [];
		/** @var list<float> $illiq */
		$illiq = [];

		$lambda = [];
		$amihud = [];
		$previous = \NAN;

		foreach ($prices as $i => $price) {
			if (\is_nan($previous)) {
				$previous = $price;
				$lambda[] = \NAN;
				$amihud[] = \NAN;
				continue;
			}

			$q = $signedSizes[$i];
			$change = $price - $previous;
			$notional = \abs($q) * $price;
			$previous = $price;

			$cross[] = $q * $change;
			$square[] = $q * $q;
			if ($notional > 0.0 && $price > 0.0) {
				$illiq[] = \abs($change / $price) / $notional;
			}

			if (\count($cross) < $window) {
				$lambda[] = \NAN;
			} else {
				$c = \array_slice($cross, -$window);
				$s = \array_slice($square, -$window);
				$denominator = \array_sum($s) / $window;
				$lambda[] = $denominator > 0.0 ? (\array_sum($c) / $window) / $denominator : \NAN;
			}

			$illiqWindow = \array_slice($illiq, -$window);
			$amihud[] = $illiqWindow === [] ? \NAN : \array_sum($illiqWindow) / \count($illiqWindow);
		}

		return ['lambda' => $lambda, 'amihud' => $amihud];
	}

	/**
	 * @return array{list<float>, list<float>}
	 */
	private static function tape(int $n, int $seed): array
	{
		\mt_srand($seed);
		$prices = [];
		$signed = [];
		$price = 20.0;
		for ($i = 0; $i < $n; $i++) {
			$q = (float) \mt_rand(-200, 200) / 10.0;
			$price += $q * 0.002 + (float) \mt_rand(-20, 20) / 1000.0;
			$prices[] = $price;
			$signed[] = $q;
		}
		return [$prices, $signed];
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
			self::assertEqualsWithDelta($want, $actual[$i], \max(self::TOLERANCE, \abs($want) * 1e-9), "$label: index $i");
		}
	}

	/** @return iterable<string, array{int}> */
	public function windows(): iterable
	{
		yield 'window 2' => [2];
		yield 'window 10' => [10];
		yield 'window 100' => [100];
	}

	/** @dataProvider windows */
	public function testMatchesANaiveImplementation(int $window): void
	{
		for ($seed = 1; $seed <= 10; $seed++) {
			[$prices, $signed] = self::tape(400, $seed * 37 + $window);
			$naive = self::naive($prices, $signed, $window);
			$actual = PriceImpact::compute($prices, $signed, $window);

			self::assertSeriesMatches($naive['lambda'], $actual['lambda'], "lambda, seed $seed window $window");
			self::assertSeriesMatches($naive['amihud'], $actual['amihud'], "amihud, seed $seed window $window");
		}
	}

	/**
	 * The recovery test: build a market in which every price change is exactly
	 * λ times the signed flow, and the estimator must return λ. It is a
	 * regression through the origin, so this holds for any flow whatever, not
	 * just a balanced one.
	 */
	public function testKyleLambdaIsRecoveredExactlyFromASyntheticMarket(): void
	{
		foreach ([0.001, 0.05, 2.5] as $lambda) {
			for ($seed = 1; $seed <= 10; $seed++) {
				\mt_srand($seed * 101);

				$prices = [100.0];
				$signed = [0.0];
				for ($i = 0; $i < 200; $i++) {
					$q = (float) \mt_rand(-500, 500) / 10.0;
					$signed[] = $q;
					$prices[] = \end($prices) + $lambda * $q;
				}

				$out = PriceImpact::kyleLambda($prices, $signed, 100);
				self::assertEqualsWithDelta(
					$lambda,
					$out[\count($prices) - 1],
					\abs($lambda) * 1e-9,
					"lambda $lambda, seed $seed",
				);
			}
		}
	}

	/**
	 * A market where flow moves the price against the trade — a mean-reverting
	 * tape — has a negative λ, and the metric reports it rather than clamping.
	 * Depth, being a reciprocal of a positive slope, is then undefined.
	 */
	public function testANegativeSlopeIsReportedAndLeavesDepthUndefined(): void
	{
		$prices = [100.0];
		$signed = [0.0];
		for ($i = 0; $i < 60; $i++) {
			$q = $i % 2 === 0 ? 10.0 : -10.0;
			$signed[] = $q;
			$prices[] = \end($prices) - 0.01 * $q;
		}

		$out = PriceImpact::compute($prices, $signed, 20);
		$last = \count($prices) - 1;

		self::assertEqualsWithDelta(-0.01, $out['lambda'][$last], 1e-9);
		self::assertNan($out['depth'][$last], 'a negative slope is not a depth');
	}

	/** Depth is the reciprocal of λ, which is what makes it a size. */
	public function testDepthIsTheReciprocalOfLambda(): void
	{
		$prices = [100.0];
		$signed = [0.0];
		for ($i = 0; $i < 60; $i++) {
			$q = $i % 2 === 0 ? 4.0 : -6.0;
			$signed[] = $q;
			$prices[] = \end($prices) + 0.02 * $q;
		}

		$out = PriceImpact::compute($prices, $signed, 20);
		$last = \count($prices) - 1;

		self::assertEqualsWithDelta(0.02, $out['lambda'][$last], 1e-9);
		self::assertEqualsWithDelta(1.0 / 0.02, $out['depth'][$last], 1e-6);
	}

	/**
	 * With no flow at all there is no slope to identify. Reporting zero would
	 * claim a market of infinite depth, so the metric returns NAN.
	 */
	public function testNoFlowLeavesTheSlopeUnidentified(): void
	{
		$prices = [];
		for ($i = 0; $i < 40; $i++) {
			$prices[] = 100.0 + 0.1 * $i;
		}
		$out = PriceImpact::compute($prices, \array_fill(0, 40, 0.0), 10);

		self::assertNan($out['lambda'][39], 'a moving price with no flow identifies nothing');
		self::assertNan($out['depth'][39]);
		self::assertNan($out['amihud'][39], 'and there is no notional to divide by either');
	}

	/**
	 * Amihud's units: |return| per unit of notional. Doubling every trade's
	 * size at the same returns halves it, which distinguishes it from λ — a
	 * quantity per unit of *signed size*, not per currency.
	 */
	public function testAmihudScalesWithTheInverseOfNotional(): void
	{
		$prices = [100.0];
		$signed = [0.0];
		for ($i = 0; $i < 40; $i++) {
			$signed[] = $i % 2 === 0 ? 5.0 : -5.0;
			$prices[] = \end($prices) * ($i % 2 === 0 ? 1.001 : 1.0 / 1.001);
		}

		$small = PriceImpact::amihud($prices, $signed, 20);
		$large = PriceImpact::amihud($prices, \array_map(static fn (float $q): float => 2.0 * $q, $signed), 20);
		$last = \count($prices) - 1;

		self::assertEqualsWithDelta($small[$last] / 2.0, $large[$last], self::TOLERANCE);
		self::assertGreaterThan(0.0, $small[$last], 'illiquidity is a magnitude');
	}

	/**
	 * A hand-computed Amihud reading. Two trades of 10 units at a price that
	 * moves by 1 from 100 to 101 and back: each contributes
	 * |Δp/p| / (|q|·p), which can be written out term by term.
	 */
	public function testPinnedAmihudFromTheDefinition(): void
	{
		$prices = [100.0, 101.0, 100.0];
		$signed = [10.0, 10.0, -10.0];

		$expected = (
			\abs(1.0 / 101.0) / (10.0 * 101.0)
			+ \abs(-1.0 / 100.0) / (10.0 * 100.0)
		) / 2.0;

		self::assertEqualsWithDelta($expected, PriceImpact::amihud($prices, $signed, 2)[2], self::TOLERANCE);
	}

	public function testWarmUpAndReset(): void
	{
		[$prices, $signed] = self::tape(40, 4242);
		$metric = new PriceImpact(10);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['amihud']);

		foreach ($prices as $i => $price) {
			$side = $signed[$i] >= 0.0 ? TradeSide::Buy : TradeSide::Sell;
			$metric->updateTrade(Trade::at(($i + 1) * 1_000_000_000, $price, \abs($signed[$i]), $side));
			// the first trade sets a baseline and yields no change
			self::assertSame($i >= 10, $metric->isReady(), "trade $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['amihud']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		[$prices, $signed] = self::tape(300, 9999);
		$kernel = PriceImpact::compute($prices, $signed, 50);

		$metric = new PriceImpact(50);
		foreach ($prices as $i => $price) {
			$side = $signed[$i] >= 0.0 ? TradeSide::Buy : TradeSide::Sell;
			$metric->updateTrade(Trade::at(($i + 1) * 1_000_000_000, $price, \abs($signed[$i]), $side));

			if (\is_nan($kernel['lambda'][$i])) {
				self::assertNan($metric->value(), "lambda at $i");
			} else {
				self::assertSame($kernel['lambda'][$i], $metric->value(), "lambda at $i");
			}
		}
	}

	public function testWindowMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new PriceImpact(1);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		PriceImpact::compute([1.0, 2.0], [1.0], 2);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new PriceImpact(250);
		self::assertSame(['type' => 'price-impact', 'window' => 250], $metric->toArray());
		self::assertSame($metric->toArray(), PriceImpact::fromArray($metric->toArray())->toArray());
	}
}
