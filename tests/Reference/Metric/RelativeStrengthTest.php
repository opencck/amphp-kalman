<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Cross\BasketWeighting;
use OpenCCK\Kalman\Domain\Metric\Cross\RelativeStrength;
use PHPUnit\Framework\TestCase;

/**
 * Relative strength (M-25) against a naive OLS regression.
 *
 * The plain excess return, r_asset − r_basket, silently assumes a beta of one.
 * The beta-adjusted form estimates it instead, and the difference is the whole
 * point of the metric: a high-beta asset in a rising market looks strong on the
 * reference measure and is merely geared.
 *
 * The strongest available test is a recovery one. Build an asset that is
 * exactly β times the basket plus a known idiosyncratic part, and both the
 * estimated beta and the adjusted return must come back exactly — which also
 * pins the regression's orientation, since regressing the basket on the asset
 * instead would return 1/β.
 */
final class RelativeStrengthTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive OLS slope of y on x.
	 *
	 * @param list<float> $x
	 * @param list<float> $y
	 */
	private static function naiveBeta(array $x, array $y): float
	{
		$n = \count($x);
		if ($n < 2) {
			return \NAN;
		}
		$meanX = \array_sum($x) / $n;
		$meanY = \array_sum($y) / $n;

		$covariance = 0.0;
		$variance = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$dx = $x[$i] - $meanX;
			$covariance += $dx * ($y[$i] - $meanY);
			$variance += $dx * $dx;
		}
		return $variance > 0.0 ? $covariance / $variance : \NAN;
	}

	/**
	 * @param list<float> $assetReturns
	 * @param list<float> $basketReturns
	 * @return list<float>
	 */
	private static function naiveRollingBeta(array $assetReturns, array $basketReturns, int $window): array
	{
		$out = [];
		foreach ($assetReturns as $i => $_) {
			$out[] = $i + 1 < $window
				? \NAN
				: self::naiveBeta(
					\array_slice($basketReturns, $i + 1 - $window, $window),
					\array_slice($assetReturns, $i + 1 - $window, $window),
				);
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
			self::assertEqualsWithDelta($want, $actual[$i], \max(self::TOLERANCE, \abs($want) * 1e-9), "$label: index $i");
		}
	}

	/** @return iterable<string, array{int}> */
	public function windows(): iterable
	{
		yield 'window 2' => [2];
		yield 'window 10' => [10];
		yield 'window 60' => [60];
	}

	/** @dataProvider windows */
	public function testRollingBetaMatchesANaiveRegression(int $window): void
	{
		for ($seed = 1; $seed <= 15; $seed++) {
			\mt_srand($seed * 23 + $window);
			$asset = [];
			$basket = [];
			for ($i = 0; $i < 300; $i++) {
				$b = (float) \mt_rand(-200, 200) / 10000.0;
				$basket[] = $b;
				$asset[] = 1.3 * $b + (float) \mt_rand(-100, 100) / 10000.0;
			}

			self::assertSeriesMatches(
				self::naiveRollingBeta($asset, $basket, $window),
				RelativeStrength::rollingBeta($asset, $basket, $window),
				"seed $seed, window $window",
			);
		}
	}

	/**
	 * The recovery test. The asset is built as exactly β times the basket plus
	 * a constant idiosyncratic drift, so the regression must return β and the
	 * adjusted return must return the drift.
	 */
	public function testBetaAndTheAdjustedReturnAreRecoveredExactly(): void
	{
		foreach ([0.5, 1.0, 2.4] as $beta) {
			$alpha = 0.0007;
			$n = 200;

			\mt_srand((int) ($beta * 1000));
			$basketSteps = [];
			for ($i = 0; $i < $n; $i++) {
				$basketSteps[] = (float) \mt_rand(-300, 300) / 100000.0;
			}

			// a one-constituent basket is that constituent's own return
			$basketPrices = [100.0];
			$assetPrices = [100.0];
			foreach ($basketSteps as $step) {
				$basketPrices[] = \end($basketPrices) * \exp($step);
				$assetPrices[] = \end($assetPrices) * \exp($beta * $step + $alpha);
			}

			$betas = RelativeStrength::rollingBeta(
				\array_map(
					static fn (int $i): float => \log($assetPrices[$i + 1] / $assetPrices[$i]),
					\range(0, $n - 1),
				),
				$basketSteps,
				60,
			);
			self::assertEqualsWithDelta($beta, $betas[$n - 1], \abs($beta) * 1e-9, "beta $beta");

			$adjusted = RelativeStrength::betaAdjusted($assetPrices, ['M' => $basketPrices], 60, 'equal', 2);
			self::assertEqualsWithDelta($alpha, $adjusted[$n], 1e-9, "alpha for beta $beta");
		}
	}

	/**
	 * The regression is of the asset on the basket, not the other way round.
	 * On the same data the reversed regression returns something different, and
	 * for a noiseless pair it is exactly the reciprocal.
	 */
	public function testTheRegressionIsOfTheAssetOnTheBasket(): void
	{
		\mt_srand(5150);
		$basket = [];
		$asset = [];
		for ($i = 0; $i < 100; $i++) {
			$b = (float) \mt_rand(-200, 200) / 10000.0;
			$basket[] = $b;
			$asset[] = 2.0 * $b;
		}

		self::assertEqualsWithDelta(2.0, RelativeStrength::rollingBeta($asset, $basket, 60)[99], 1e-9);
		self::assertEqualsWithDelta(0.5, RelativeStrength::rollingBeta($basket, $asset, 60)[99], 1e-9);
	}

	/**
	 * What the beta adjustment buys: a geared asset in a rising market shows a
	 * large excess return on the reference measure and none at all once its
	 * beta is accounted for.
	 */
	public function testAGearedAssetLooksStrongOnExcessAndNeutralOnceAdjusted(): void
	{
		$n = 200;
		\mt_srand(3141);

		$basketPrices = [100.0];
		$assetPrices = [100.0];
		$steps = [];
		for ($i = 0; $i < $n; $i++) {
			// a market that rises on average
			$step = 0.001 + (float) \mt_rand(-100, 100) / 100000.0;
			$steps[] = $step;
			$basketPrices[] = \end($basketPrices) * \exp($step);
			$assetPrices[] = \end($assetPrices) * \exp(2.0 * $step);
		}

		$excess = RelativeStrength::excess($assetPrices, ['M' => $basketPrices], 'equal', 2);
		$adjusted = RelativeStrength::betaAdjusted($assetPrices, ['M' => $basketPrices], 60, 'equal', 2);

		// With the asset at twice the basket's return, the plain excess is
		// exactly one more copy of the market move — it measures gearing, not
		// skill, and inherits the market's drift step for step.
		for ($i = 1; $i <= $n; $i++) {
			self::assertEqualsWithDelta($steps[$i - 1], $excess[$i], 1e-12, "excess at $i is the market step");
		}

		// so on average it is positive, at roughly the market's own drift
		$mean = \array_sum(\array_slice($excess, 1)) / $n;
		self::assertGreaterThan(0.0005, $mean, 'the reference measure calls a geared asset strong');

		// once beta is estimated the asset has no idiosyncratic return at all
		self::assertEqualsWithDelta(0.0, $adjusted[$n], 1e-9, 'it was only geared');
	}

	/** The plain excess assumes a beta of one, which the two forms agree on. */
	public function testTheExcessFormIsTheAdjustedOneAtBetaOne(): void
	{
		$n = 120;
		\mt_srand(9182);
		$basketPrices = [100.0];
		$assetPrices = [50.0];
		for ($i = 0; $i < $n; $i++) {
			$step = (float) \mt_rand(-200, 200) / 100000.0;
			$basketPrices[] = \end($basketPrices) * \exp($step);
			$assetPrices[] = \end($assetPrices) * \exp($step);
		}

		$metric = new RelativeStrength('A', ['M'], BasketWeighting::Equal, 60, 2);
		for ($i = 0; $i <= $n; $i++) {
			$metric->update($i + 1, ['A' => $assetPrices[$i], 'M' => $basketPrices[$i]]);
		}

		self::assertEqualsWithDelta(1.0, $metric->beta(), 1e-9, 'a perfect tracker has a beta of one');
		self::assertEqualsWithDelta($metric->excessValue(), $metric->value(), 1e-9);
		self::assertEqualsWithDelta(0.0, $metric->excessValue(), 1e-9);
	}

	/**
	 * A basket that never moves offers no variation to regress against, so the
	 * beta is undefined rather than zero.
	 */
	public function testAStillBasketLeavesTheBetaUndefined(): void
	{
		$n = 80;
		$basketPrices = \array_fill(0, $n + 1, 100.0);
		$assetPrices = [50.0];
		for ($i = 0; $i < $n; $i++) {
			$assetPrices[] = \end($assetPrices) * 1.001;
		}

		$metric = new RelativeStrength('A', ['M'], BasketWeighting::Equal, 60, 2);
		for ($i = 0; $i <= $n; $i++) {
			$metric->update($i + 1, ['A' => $assetPrices[$i] ?? \NAN, 'M' => $basketPrices[$i] ?? \NAN]);
		}

		self::assertNan($metric->beta());
		self::assertNan($metric->value());
		// the plain excess is still defined: the basket simply returned nothing
		self::assertEqualsWithDelta(\log(1.001), $metric->excessValue(), self::TOLERANCE);
	}

	/**
	 * The excess is defined as soon as there is a basket return; the adjusted
	 * form has to wait for the regression window to fill.
	 */
	public function testTheExcessIsDefinedBeforeTheBetaIs(): void
	{
		$metric = new RelativeStrength('A', ['M'], BasketWeighting::Equal, 10, 2);

		$metric->update(1, ['A' => 100.0, 'M' => 200.0]);
		self::assertNan($metric->excessValue(), 'no return yet at all');

		$metric->update(2, ['A' => 101.0, 'M' => 202.0]);
		self::assertFalse($metric->isReady(), 'the beta window is not full');
		self::assertNan($metric->value());
		self::assertTrue(\is_finite($metric->excessValue()), 'but the excess is available');

		for ($i = 3; $i <= 20; $i++) {
			$metric->update($i, ['A' => 100.0 + $i, 'M' => 200.0 + $i]);
		}
		self::assertTrue($metric->isReady());
	}

	public function testWarmUpAndReset(): void
	{
		$metric = new RelativeStrength('A', ['M'], BasketWeighting::Equal, 10, 2);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		for ($i = 1; $i <= 30; $i++) {
			$metric->update($i, ['A' => 100.0 + $i, 'M' => 200.0 + 2 * $i]);
		}
		self::assertTrue($metric->isReady());

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->beta());
		self::assertNan($metric->values()['assetReturn']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$n = 200;
		\mt_srand(2024);
		$assetPrices = [80.0];
		$basketPrices = [120.0];
		for ($i = 0; $i < $n; $i++) {
			$assetPrices[] = \end($assetPrices) * \exp((float) \mt_rand(-200, 200) / 100000.0);
			$basketPrices[] = \end($basketPrices) * \exp((float) \mt_rand(-200, 200) / 100000.0);
		}

		$excess = RelativeStrength::excess($assetPrices, ['M' => $basketPrices], 'equal', 2);
		$adjusted = RelativeStrength::betaAdjusted($assetPrices, ['M' => $basketPrices], 30, 'equal', 2);

		$metric = new RelativeStrength('A', ['M'], BasketWeighting::Equal, 30, 2);
		for ($i = 0; $i <= $n; $i++) {
			$metric->update($i + 1, ['A' => $assetPrices[$i], 'M' => $basketPrices[$i]]);

			if (\is_nan($excess[$i])) {
				self::assertNan($metric->excessValue(), "excess at $i");
			} else {
				self::assertSame($excess[$i], $metric->excessValue(), "excess at $i");
			}
			if (\is_nan($adjusted[$i])) {
				self::assertNan($metric->value(), "adjusted at $i");
			} else {
				self::assertSame($adjusted[$i], $metric->value(), "adjusted at $i");
			}
		}
	}

	public function testTheAssetSymbolMustNotBeEmpty(): void
	{
		$this->expectException(InvalidArgument::class);
		new RelativeStrength('', ['M'], BasketWeighting::Equal, 60, 60);
	}

	public function testTheBetaWindowMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new RelativeStrength('A', ['M'], BasketWeighting::Equal, 1, 60);
	}

	public function testAMissingAssetPriceIsRejected(): void
	{
		$metric = new RelativeStrength('A', ['M'], BasketWeighting::Equal, 60, 60);
		$this->expectException(InvalidArgument::class);
		$metric->update(1, ['M' => 100.0]);
	}

	public function testRollingBetaNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		RelativeStrength::rollingBeta([1.0, 2.0], [1.0], 2);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new RelativeStrength('BTC', ['ETH', 'SOL'], BasketWeighting::Equal, 45, 90);
		self::assertSame(
			[
				'type' => 'relative-strength',
				'asset' => 'BTC',
				'constituents' => ['ETH', 'SOL'],
				'weighting' => 'equal',
				'betaWindow' => 45,
				'window' => 90,
			],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), RelativeStrength::fromArray($metric->toArray())->toArray());
	}
}
