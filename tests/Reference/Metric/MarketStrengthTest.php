<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Cross\BasketWeighting;
use OpenCCK\Kalman\Domain\Metric\Cross\MarketStrength;
use PHPUnit\Framework\TestCase;

/**
 * Market strength index (M-26) against a naive implementation of its two
 * halves.
 *
 * The composite blends a breadth count with a standardised momentum. Breadth is
 * a share of constituents that rose, mapped from [0, 1] onto [−1, +1], so it is
 * bounded by construction; the momentum leg is not, which is why it passes
 * through a tanh before entering the blend. Together those two facts bound the
 * composite by ±1 — the property a caller thresholds on, and the one tested
 * here on inputs deliberately extreme enough to break an unbounded blend.
 *
 * The other thing worth pinning is that breadth counts constituents while
 * momentum weighs them: a basket where one huge constituent falls and three
 * tiny ones rise has positive breadth and negative momentum, and the blend has
 * to disagree with itself in the right direction.
 */
final class MarketStrengthTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive breadth over the trailing window: the share of constituents whose
	 * log return over `window` observations is positive, mapped to [−1, +1].
	 *
	 * @param array<string, list<float>> $series
	 */
	private static function naiveBreadthAt(array $series, int $index, int $window): float
	{
		$positive = 0;
		$total = 0;
		foreach ($series as $column) {
			$past = $index - $window;
			if ($past < 0) {
				return \NAN;
			}
			$r = \log($column[$index] / $column[$past]);
			if ($r > 0.0) {
				$positive++;
			}
			$total++;
		}
		return $total === 0 ? \NAN : 2.0 * ($positive / $total) - 1.0;
	}

	/**
	 * @param list<string> $symbols
	 * @return array<string, list<float>>
	 */
	private static function series(array $symbols, int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		foreach ($symbols as $k => $symbol) {
			$price = 20.0 * ($k + 1);
			$column = [];
			for ($i = 0; $i < $n; $i++) {
				$price *= \exp((float) \mt_rand(-250, 260) / 100000.0);
				$column[] = $price;
			}
			$out[$symbol] = $column;
		}
		return $out;
	}

	/**
	 * @param array<string, list<float>> $series
	 * @return array<string, list<float>>
	 */
	private static function sizesFor(array $series, float $size = 100.0): array
	{
		$out = [];
		foreach ($series as $symbol => $column) {
			$out[$symbol] = \array_fill(0, \count($column), $size);
		}
		return $out;
	}

	public function testBreadthMatchesANaiveCount(): void
	{
		$symbols = ['A', 'B', 'C', 'D'];
		$window = 20;

		for ($seed = 1; $seed <= 15; $seed++) {
			$series = self::series($symbols, 200, $seed * 41);
			$out = MarketStrength::compute($series, self::sizesFor($series), $window, 0.5, 'volume');

			foreach ($out['breadth'] as $i => $value) {
				$expected = self::naiveBreadthAt($series, $i, $window);
				if (\is_nan($expected)) {
					self::assertNan($value, "seed $seed index $i");
					continue;
				}
				self::assertEqualsWithDelta($expected, $value, self::TOLERANCE, "seed $seed index $i");
			}
		}
	}

	/**
	 * Breadth maps a share onto [−1, +1]: all up is +1, all down is −1, and an
	 * even split is exactly zero.
	 */
	public function testBreadthMapsTheShareOntoTheSymmetricInterval(): void
	{
		$n = 60;
		$window = 10;

		$build = static function (array $directions) use ($n): array {
			$out = [];
			foreach ($directions as $symbol => $up) {
				$price = 100.0;
				$column = [];
				for ($i = 0; $i < $n; $i++) {
					$price *= $up ? 1.001 : 0.999;
					$column[] = $price;
				}
				$out[$symbol] = $column;
			}
			return $out;
		};

		$allUp = $build(['A' => true, 'B' => true, 'C' => true, 'D' => true]);
		$allDown = $build(['A' => false, 'B' => false, 'C' => false, 'D' => false]);
		$split = $build(['A' => true, 'B' => true, 'C' => false, 'D' => false]);

		$last = $n - 1;
		self::assertEqualsWithDelta(
			1.0,
			MarketStrength::compute($allUp, self::sizesFor($allUp), $window, 0.5, 'volume')['breadth'][$last],
			self::TOLERANCE,
		);
		self::assertEqualsWithDelta(
			-1.0,
			MarketStrength::compute($allDown, self::sizesFor($allDown), $window, 0.5, 'volume')['breadth'][$last],
			self::TOLERANCE,
		);
		self::assertEqualsWithDelta(
			0.0,
			MarketStrength::compute($split, self::sizesFor($split), $window, 0.5, 'volume')['breadth'][$last],
			self::TOLERANCE,
		);
	}

	/**
	 * The composite is bounded by ±1: breadth is bounded by construction and
	 * the momentum leg is squashed through tanh before it is blended. This is
	 * checked on a violently trending basket, where an unblended momentum would
	 * run far outside the interval.
	 */
	public function testTheCompositeIsBoundedEvenOnAnExtremeTrend(): void
	{
		$n = 120;
		$window = 20;

		// a steep, almost noiseless trend: a huge return against a tiny sigma
		$series = [];
		foreach (['A', 'B', 'C'] as $symbol) {
			$price = 100.0;
			$column = [];
			for ($i = 0; $i < $n; $i++) {
				$price *= \exp(0.05 + ($i % 2 === 0 ? 1e-9 : -1e-9));
				$column[] = $price;
			}
			$series[$symbol] = $column;
		}

		$out = MarketStrength::compute($series, self::sizesFor($series), $window, 0.5, 'volume');

		$checked = 0;
		foreach ($out['msi'] as $i => $value) {
			if (\is_nan($value)) {
				continue;
			}
			self::assertGreaterThanOrEqual(-1.0, $value, "index $i");
			self::assertLessThanOrEqual(1.0, $value, "index $i");
			$checked++;
		}
		self::assertGreaterThan(0, $checked, 'the bound must actually have been exercised');

		// the raw momentum leg really is outside the interval, so the tanh is
		// doing the work rather than the data being tame
		self::assertGreaterThan(1.0, $out['momentum'][$n - 1]);
		self::assertLessThan(1.0, \tanh($out['momentum'][$n - 1]));

		// and the streaming object exposes that squashed value under its own key
		$metric = new MarketStrength(['A', 'B', 'C'], $window, 0.5, BasketWeighting::Volume);
		for ($i = 0; $i < $n; $i++) {
			$prices = [];
			$row = [];
			foreach (['A', 'B', 'C'] as $symbol) {
				$prices[$symbol] = $series[$symbol][$i];
				$row[$symbol] = 100.0;
			}
			$metric->update($i + 1, $prices, $row);
		}
		self::assertEqualsWithDelta(
			\tanh($metric->momentumValue()),
			$metric->values()['momentumScaled'],
			self::TOLERANCE,
		);
	}

	/** The blend is a convex combination of the two legs, with the stated weight. */
	public function testTheBlendIsAConvexCombinationOfTheTwoLegs(): void
	{
		$series = self::series(['A', 'B', 'C'], 150, 7777);
		$sizes = self::sizesFor($series);

		foreach ([0.0, 0.25, 0.5, 1.0] as $blend) {
			$out = MarketStrength::compute($series, $sizes, 20, $blend, 'volume');
			$last = 149;

			self::assertEqualsWithDelta(
				$blend * $out['breadth'][$last] + (1.0 - $blend) * \tanh($out['momentum'][$last]),
				$out['msi'][$last],
				self::TOLERANCE,
				"blend $blend",
			);
		}

		// at blend 1 the composite is breadth alone
		$breadthOnly = MarketStrength::compute($series, $sizes, 20, 1.0, 'volume');
		self::assertEqualsWithDelta($breadthOnly['breadth'][149], $breadthOnly['msi'][149], self::TOLERANCE);

		// and at blend 0 it is the squashed momentum alone
		$momentumOnly = MarketStrength::compute($series, $sizes, 20, 0.0, 'volume');
		self::assertEqualsWithDelta(
			\tanh($momentumOnly['momentum'][149]),
			$momentumOnly['msi'][149],
			self::TOLERANCE,
		);
	}

	/**
	 * Breadth counts constituents and momentum weighs them. A basket where the
	 * one heavyweight falls while three lightweights rise must show positive
	 * breadth and negative weighted momentum — the disagreement the composite
	 * exists to expose.
	 */
	public function testBreadthCountsWhileMomentumWeighs(): void
	{
		$n = 80;
		$window = 20;

		$series = [];
		$sizes = [];
		foreach (['HEAVY' => false, 'A' => true, 'B' => true, 'C' => true] as $symbol => $up) {
			$price = 100.0;
			$column = [];
			for ($i = 0; $i < $n; $i++) {
				$price *= $up ? \exp(0.001 + ($i % 2 === 0 ? 1e-6 : -1e-6)) : \exp(-0.004 + ($i % 2 === 0 ? 1e-6 : -1e-6));
				$column[] = $price;
			}
			$series[$symbol] = $column;
			$sizes[$symbol] = \array_fill(0, $n, $symbol === 'HEAVY' ? 100000.0 : 1.0);
		}

		$out = MarketStrength::compute($series, $sizes, $window, 0.5, 'volume');
		$last = $n - 1;

		// three of four rose
		self::assertEqualsWithDelta(2.0 * (3.0 / 4.0) - 1.0, $out['breadth'][$last], self::TOLERANCE);
		// but the weight sits with the one that fell
		self::assertLessThan(0.0, $out['momentum'][$last]);
	}

	public function testWarmUpAndReset(): void
	{
		$series = self::series(['A', 'B'], 60, 1234);
		$metric = new MarketStrength(['A', 'B'], 20, 0.5, BasketWeighting::Volume);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->breadthValue());
		self::assertNan($metric->momentumValue());

		for ($i = 0; $i < 60; $i++) {
			$metric->update(
				$i + 1,
				['A' => $series['A'][$i], 'B' => $series['B'][$i]],
				['A' => 100.0, 'B' => 100.0],
			);
		}
		self::assertTrue($metric->isReady());

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['momentumScaled']);
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		$series = self::series(['A', 'B', 'C'], 200, 5656);
		$sizes = self::sizesFor($series);
		$kernel = MarketStrength::compute($series, $sizes, 20, 0.5, 'volume');

		$metric = new MarketStrength(['A', 'B', 'C'], 20, 0.5, BasketWeighting::Volume);
		for ($i = 0; $i < 200; $i++) {
			$prices = [];
			$row = [];
			foreach (['A', 'B', 'C'] as $symbol) {
				$prices[$symbol] = $series[$symbol][$i];
				$row[$symbol] = $sizes[$symbol][$i];
			}
			$metric->update($i + 1, $prices, $row);

			if (\is_nan($kernel['msi'][$i])) {
				self::assertNan($metric->value(), "index $i");
			} else {
				self::assertSame($kernel['msi'][$i], $metric->value(), "index $i");
			}
		}
	}

	public function testWindowMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new MarketStrength(['A'], 1, 0.5, BasketWeighting::Equal);
	}

	/** @return iterable<string, array{float}> */
	public function invalidBlends(): iterable
	{
		yield 'below zero' => [-0.1];
		yield 'above one' => [1.1];
	}

	/** @dataProvider invalidBlends */
	public function testBlendMustLieInTheUnitInterval(float $blend): void
	{
		$this->expectException(InvalidArgument::class);
		new MarketStrength(['A'], 60, $blend, BasketWeighting::Equal);
	}

	public function testAtLeastOneConstituentIsNeeded(): void
	{
		$this->expectException(InvalidArgument::class);
		MarketStrength::compute([], [], 60, 0.5, 'equal');
	}

	public function testSeriesMustBeOfEqualLength(): void
	{
		$this->expectException(InvalidArgument::class);
		MarketStrength::compute(['A' => [1.0, 2.0], 'B' => [1.0]], [], 2, 0.5, 'equal');
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new MarketStrength(['X', 'Y'], 30, 0.25, BasketWeighting::Equal);
		self::assertSame(
			[
				'type' => 'market-strength',
				'constituents' => ['X', 'Y'],
				'window' => 30,
				'blend' => 0.25,
				'weighting' => 'equal',
			],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), MarketStrength::fromArray($metric->toArray())->toArray());
	}
}
