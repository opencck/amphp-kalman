<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Cross\BasketReturn;
use OpenCCK\Kalman\Domain\Metric\Cross\BasketWeighting;
use PHPUnit\Framework\TestCase;

/**
 * Basket return (M-24) against naive weighted sums.
 *
 * The basket is a weighted mean of log returns, and the properties that follow
 * from that are what is pinned here: the weights sum to one, so a basket whose
 * constituents all move alike moves by the same amount whatever the weighting;
 * the dispersion is the weighted spread around the basket and vanishes exactly
 * when the constituents agree; and under equal weighting the answer does not
 * depend on the order the constituents were listed in.
 *
 * Inverse-volatility weighting is the one mode with a moving part worth
 * checking on its own: the weight is 1/σ normalised, so a constituent twice as
 * volatile as another must end up with half its weight — measured here against
 * a synthetic pair built to a known volatility ratio.
 */
final class BasketReturnTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive equal-weighted basket: the mean of the constituents' log returns.
	 *
	 * @param array<string, list<float>> $series
	 * @return list<float>
	 */
	private static function naiveEqual(array $series): array
	{
		$symbols = \array_keys($series);
		$length = \count($series[$symbols[0]]);
		$out = [];

		for ($i = 0; $i < $length; $i++) {
			if ($i === 0) {
				$out[] = \NAN;
				continue;
			}
			$sum = 0.0;
			foreach ($symbols as $symbol) {
				$sum += \log($series[$symbol][$i] / $series[$symbol][$i - 1]);
			}
			$out[] = $sum / \count($symbols);
		}
		return $out;
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
			$price = 10.0 * ($k + 1);
			$column = [];
			for ($i = 0; $i < $n; $i++) {
				$price *= \exp((float) \mt_rand(-300, 300) / 100000.0);
				$column[] = $price;
			}
			$out[$symbol] = $column;
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

	public function testEqualWeightMatchesANaiveMeanOfReturns(): void
	{
		foreach ([['A', 'B'], ['A', 'B', 'C'], ['A', 'B', 'C', 'D', 'E']] as $symbols) {
			$series = self::series($symbols, 200, \count($symbols) * 71);
			self::assertSeriesMatches(
				self::naiveEqual($series),
				BasketReturn::equalWeight($series),
				'equal weight, ' . \count($symbols) . ' constituents',
			);
		}
	}

	/**
	 * A value derived by hand: two constituents, one doubling and one halving.
	 * Their log returns are +ln2 and −ln2, so an equally weighted basket is
	 * exactly zero — and its dispersion is exactly ln 2, the common distance
	 * from the mean.
	 */
	public function testPinnedValuesFromTheDefinition(): void
	{
		$metric = new BasketReturn(['UP', 'DOWN'], BasketWeighting::Equal, 2);
		$metric->update(1, ['UP' => 100.0, 'DOWN' => 100.0]);
		$metric->update(2, ['UP' => 200.0, 'DOWN' => 50.0]);

		self::assertTrue($metric->isReady());
		self::assertEqualsWithDelta(0.0, $metric->value(), self::TOLERANCE);
		self::assertEqualsWithDelta(\M_LN2, $metric->values()['dispersion'], self::TOLERANCE);

		self::assertEqualsWithDelta(\M_LN2, $metric->returns()['UP'], self::TOLERANCE);
		self::assertEqualsWithDelta(-\M_LN2, $metric->returns()['DOWN'], self::TOLERANCE);
		self::assertEqualsWithDelta(0.5, $metric->weights()['UP'], self::TOLERANCE);
	}

	/**
	 * The weights sum to one under every mode, which is what makes the basket a
	 * mean rather than a sum. The consequence is testable directly: if every
	 * constituent moves by the same amount, the basket moves by that amount, no
	 * matter how the weights are distributed.
	 */
	public function testTheWeightsSumToOneUnderEveryMode(): void
	{
		$symbols = ['A', 'B', 'C'];
		$n = 200;

		// every constituent follows the identical path, at different levels
		\mt_srand(4242);
		$steps = [];
		for ($i = 0; $i < $n; $i++) {
			$steps[] = (float) \mt_rand(-200, 200) / 100000.0;
		}

		$series = [];
		$sizes = [];
		foreach ($symbols as $k => $symbol) {
			$price = 50.0 * ($k + 1);
			$column = [];
			foreach ($steps as $step) {
				$price *= \exp($step);
				$column[] = $price;
			}
			$series[$symbol] = $column;
			// deliberately unequal sizes, so volume weighting is lopsided
			$sizes[$symbol] = \array_fill(0, $n, (float) (($k + 1) * 1000));
		}

		$last = $n - 1;
		$expected = $steps[$last];

		self::assertEqualsWithDelta($expected, BasketReturn::equalWeight($series)[$last], self::TOLERANCE);
		self::assertEqualsWithDelta($expected, BasketReturn::volumeWeighted($series, $sizes, 20)[$last], self::TOLERANCE);
		self::assertEqualsWithDelta($expected, BasketReturn::inverseVolWeighted($series, 20)[$last], self::TOLERANCE);
	}

	/** Constituents that agree leave no dispersion at all. */
	public function testIdenticalConstituentsHaveNoDispersion(): void
	{
		$metric = new BasketReturn(['A', 'B', 'C'], BasketWeighting::Equal, 2);
		$metric->update(1, ['A' => 10.0, 'B' => 20.0, 'C' => 30.0]);
		$metric->update(2, ['A' => 11.0, 'B' => 22.0, 'C' => 33.0]);

		self::assertEqualsWithDelta(\log(1.1), $metric->value(), self::TOLERANCE);
		self::assertEqualsWithDelta(0.0, $metric->values()['dispersion'], self::TOLERANCE);
	}

	/** Under equal weighting the listing order of the constituents is irrelevant. */
	public function testEqualWeightingIsIndependentOfTheConstituentOrder(): void
	{
		$series = self::series(['A', 'B', 'C', 'D'], 150, 8080);
		$reversed = \array_reverse($series, true);

		self::assertSeriesMatches(
			BasketReturn::equalWeight($series),
			BasketReturn::equalWeight($reversed),
			'reordered constituents',
		);
	}

	/**
	 * Inverse-volatility weighting: a constituent twice as volatile as another
	 * gets half the weight. Built here from two synthetic paths whose log
	 * returns differ by an exact factor of two.
	 */
	public function testInverseVolatilityWeightingHalvesTheWeightOfTwiceTheVolatility(): void
	{
		$n = 200;
		\mt_srand(1717);
		$steps = [];
		for ($i = 0; $i < $n; $i++) {
			$steps[] = (float) \mt_rand(-200, 200) / 100000.0;
		}

		$calm = [];
		$wild = [];
		$calmPrice = 100.0;
		$wildPrice = 100.0;
		foreach ($steps as $step) {
			$calmPrice *= \exp($step);
			$wildPrice *= \exp(2.0 * $step);
			$calm[] = $calmPrice;
			$wild[] = $wildPrice;
		}

		$metric = new BasketReturn(['CALM', 'WILD'], BasketWeighting::InverseVolatility, 30);
		for ($i = 0; $i < $n; $i++) {
			$metric->update($i + 1, ['CALM' => $calm[$i], 'WILD' => $wild[$i]]);
		}

		$weights = $metric->weights();
		// σ_wild = 2·σ_calm, so the weights are 1/σ and 1/(2σ) normalised: 2/3 and 1/3
		self::assertEqualsWithDelta(2.0 / 3.0, $weights['CALM'], 1e-6);
		self::assertEqualsWithDelta(1.0 / 3.0, $weights['WILD'], 1e-6);
		self::assertEqualsWithDelta(1.0, $weights['CALM'] + $weights['WILD'], self::TOLERANCE);
	}

	/**
	 * Volume weighting: the weight is the constituent's mean traded size over
	 * the window, normalised. Hand-computed here from constant sizes.
	 */
	public function testVolumeWeightingUsesTheMeanSizeOverTheWindow(): void
	{
		$metric = new BasketReturn(['BIG', 'SMALL'], BasketWeighting::Volume, 5);

		for ($i = 0; $i < 10; $i++) {
			$metric->update(
				$i + 1,
				['BIG' => 100.0 * (1.0 + 0.01 * $i), 'SMALL' => 50.0],
				['BIG' => 300.0, 'SMALL' => 100.0],
			);
		}

		$weights = $metric->weights();
		self::assertEqualsWithDelta(0.75, $weights['BIG'], self::TOLERANCE);
		self::assertEqualsWithDelta(0.25, $weights['SMALL'], self::TOLERANCE);

		// SMALL never moves, so the basket is three quarters of BIG's return
		$expected = 0.75 * \log((1.0 + 0.01 * 9) / (1.0 + 0.01 * 8));
		self::assertEqualsWithDelta($expected, $metric->value(), self::TOLERANCE);
	}

	/** The first observation establishes a baseline and yields no return. */
	public function testTheFirstObservationYieldsNoReturn(): void
	{
		$metric = new BasketReturn(['A', 'B'], BasketWeighting::Equal, 2);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		$metric->update(1, ['A' => 10.0, 'B' => 20.0]);
		self::assertFalse($metric->isReady(), 'one price per constituent is not yet a return');
		self::assertNan($metric->value());

		$metric->update(2, ['A' => 11.0, 'B' => 21.0]);
		self::assertTrue($metric->isReady());
	}

	public function testWarmUpAndReset(): void
	{
		$series = self::series(['A', 'B'], 20, 3030);
		$metric = new BasketReturn(['A', 'B'], BasketWeighting::Equal, 2);

		for ($i = 0; $i < 20; $i++) {
			$metric->update($i + 1, ['A' => $series['A'][$i], 'B' => $series['B'][$i]]);
		}
		self::assertTrue($metric->isReady());

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['dispersion']);
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		$series = self::series(['A', 'B', 'C'], 200, 6161);
		$kernel = BasketReturn::equalWeight($series);

		$metric = new BasketReturn(['A', 'B', 'C'], BasketWeighting::Equal, 2);
		for ($i = 0; $i < 200; $i++) {
			$metric->update($i + 1, [
				'A' => $series['A'][$i],
				'B' => $series['B'][$i],
				'C' => $series['C'][$i],
			]);
			if (\is_nan($kernel[$i])) {
				self::assertNan($metric->value(), "index $i");
			} else {
				self::assertSame($kernel[$i], $metric->value(), "index $i");
			}
		}
	}

	public function testAtLeastOneConstituentIsNeeded(): void
	{
		$this->expectException(InvalidArgument::class);
		new BasketReturn([], BasketWeighting::Equal, 60);
	}

	public function testConstituentsMustBeUnique(): void
	{
		$this->expectException(InvalidArgument::class);
		new BasketReturn(['A', 'A'], BasketWeighting::Equal, 60);
	}

	public function testConstituentSymbolsMustNotBeEmpty(): void
	{
		$this->expectException(InvalidArgument::class);
		new BasketReturn(['A', ''], BasketWeighting::Equal, 60);
	}

	public function testWindowMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new BasketReturn(['A'], BasketWeighting::Equal, 1);
	}

	public function testAMissingPriceIsRejected(): void
	{
		$metric = new BasketReturn(['A', 'B'], BasketWeighting::Equal, 2);
		$this->expectException(InvalidArgument::class);
		$metric->update(1, ['A' => 10.0]);
	}

	public function testANonPositivePriceIsRejected(): void
	{
		$metric = new BasketReturn(['A'], BasketWeighting::Equal, 2);
		$this->expectException(InvalidArgument::class);
		$metric->update(1, ['A' => 0.0]);
	}

	public function testVolumeWeightingRejectsAMissingSize(): void
	{
		$metric = new BasketReturn(['A'], BasketWeighting::Volume, 2);
		$this->expectException(InvalidArgument::class);
		$metric->update(1, ['A' => 10.0]);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new BasketReturn(['X', 'Y'], BasketWeighting::InverseVolatility, 90);
		self::assertSame(
			[
				'type' => 'basket-return',
				'constituents' => ['X', 'Y'],
				'weighting' => 'inverse-volatility',
				'window' => 90,
			],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), BasketReturn::fromArray($metric->toArray())->toArray());
	}
}
