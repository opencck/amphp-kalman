<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Volume\VolumeProfile;
use PHPUnit\Framework\TestCase;

/**
 * Volume profile (M-14) against a naive implementation that re-sums both
 * windows from scratch.
 *
 * The reading is a standardised volume surprise: the window's total size
 * against what the longer reference window says to expect, divided by the
 * standard error of a sum of `window` draws — hence the √window in the
 * denominator. That √window is the part a reimplementation gets wrong, so it is
 * pinned directly: doubling the window on i.i.d. sizes must leave the z-score's
 * scale alone rather than inflating it by the window length.
 */
final class VolumeProfileTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Population standard deviation of a slice, summed from scratch.
	 *
	 * @param list<float> $values
	 */
	private static function naivePopulationSd(array $values): float
	{
		$n = \count($values);
		$mean = \array_sum($values) / $n;
		$sq = 0.0;
		foreach ($values as $v) {
			$sq += ($v - $mean) ** 2;
		}
		return \sqrt($sq / $n);
	}

	/**
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @param list<float> $signs
	 * @return array{z: list<float>, volume: list<float>, normalized: list<float>, delta: list<float>, imbalance: list<float>}
	 */
	private static function naive(array $prices, array $sizes, array $signs, int $window, int $reference): array
	{
		$z = [];
		$volume = [];
		$normalized = [];
		$delta = [];
		$imbalance = [];

		foreach ($sizes as $i => $_) {
			$seen = $i + 1;

			$windowSlice = \array_slice($sizes, \max(0, $seen - $window), \min($seen, $window));
			$total = \array_sum($windowSlice);

			$buy = 0.0;
			for ($k = \max(0, $seen - $window); $k < $seen; $k++) {
				if ($signs[$k] > 0.0) {
					$buy += $sizes[$k];
				}
			}
			$sell = $total - $buy;

			$volume[] = $total;
			$delta[] = $buy - $sell;
			$imbalance[] = $total > 0.0 ? ($buy - $sell) / $total : \NAN;

			if ($seen < $reference) {
				$z[] = \NAN;
				$normalized[] = \NAN;
				continue;
			}

			$referenceSlice = \array_slice($sizes, $seen - $reference, $reference);
			$expected = $window * (\array_sum($referenceSlice) / $reference);
			$sd = self::naivePopulationSd($referenceSlice);

			$z[] = $sd > 0.0 ? ($total - $expected) / (\sqrt((float) $window) * $sd) : \NAN;
			$normalized[] = $expected > 0.0 ? $total / $expected : \NAN;
		}

		return ['z' => $z, 'volume' => $volume, 'normalized' => $normalized, 'delta' => $delta, 'imbalance' => $imbalance];
	}

	/**
	 * @return array{list<float>, list<float>, list<float>}
	 */
	private static function tape(int $n, int $seed): array
	{
		\mt_srand($seed);
		$prices = [];
		$sizes = [];
		$signs = [];
		$price = 50.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-40, 40) / 100.0;
			$prices[] = $price;
			$sizes[] = (float) \mt_rand(1, 900) / 10.0;
			$signs[] = match (\mt_rand(0, 2)) {
				0 => 1.0,
				1 => -1.0,
				default => 0.0,
			};
		}
		return [$prices, $sizes, $signs];
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
		yield 'window 1, reference 10' => [1, 10];
		yield 'window 5, reference 5' => [5, 5];
		yield 'window 10, reference 50' => [10, 50];
		yield 'window 20, reference 200' => [20, 200];
	}

	/** @dataProvider configurations */
	public function testMatchesANaiveResummingOfBothWindows(int $window, int $reference): void
	{
		[$prices, $sizes, $signs] = self::tape(500, $window * 41 + $reference);
		$naive = self::naive($prices, $sizes, $signs, $window, $reference);
		$actual = VolumeProfile::compute($prices, $sizes, $signs, $window, $reference);

		foreach (['z', 'volume', 'normalized', 'delta', 'imbalance'] as $key) {
			self::assertSeriesMatches($naive[$key], $actual[$key], "$key($window, $reference)");
		}
	}

	/** The two convenience kernels are rows of `compute()`. */
	public function testTheConvenienceKernelsAgreeWithCompute(): void
	{
		[, $sizes, $signs] = self::tape(300, 6543);
		$n = \count($sizes);

		self::assertSeriesMatches(
			VolumeProfile::compute(\array_fill(0, $n, 1.0), $sizes, \array_fill(0, $n, 0.0), 10, 100)['normalized'],
			VolumeProfile::normalized($sizes, 10, 100),
			'normalized',
		);
		self::assertSeriesMatches(
			VolumeProfile::compute(\array_fill(0, $n, 1.0), $sizes, $signs, 10, 10)['delta'],
			VolumeProfile::delta($sizes, $signs, 10),
			'delta',
		);
	}

	/**
	 * Values derived by hand. With a reference window of four sizes
	 * [1, 1, 3, 3] the mean is 2 and the population σ is 1. A window of two
	 * covering [3, 3] totals 6 against an expectation of 2·2 = 4, so the
	 * z-score is (6 − 4) / (√2 · 1) = √2, and the normalised form is 6/4 = 1.5.
	 */
	public function testPinnedValuesFromTheDefinition(): void
	{
		$sizes = [1.0, 1.0, 3.0, 3.0];
		$signs = [1.0, -1.0, 1.0, -1.0];
		$prices = [10.0, 10.0, 10.0, 10.0];

		$out = VolumeProfile::compute($prices, $sizes, $signs, 2, 4);

		self::assertEqualsWithDelta(6.0, $out['volume'][3], self::TOLERANCE);
		self::assertEqualsWithDelta(\sqrt(2.0), $out['z'][3], self::TOLERANCE);
		self::assertEqualsWithDelta(1.5, $out['normalized'][3], self::TOLERANCE);

		// the window holds one buy of 3 and one sell of 3
		self::assertEqualsWithDelta(0.0, $out['delta'][3], self::TOLERANCE);
		self::assertEqualsWithDelta(0.0, $out['imbalance'][3], self::TOLERANCE);
	}

	/**
	 * The √window in the denominator is the standard error of a sum of `window`
	 * independent draws. Its consequence, and the reason it is there: on an
	 * i.i.d. tape the z-score does not grow with the window, whereas dividing
	 * by σ alone would inflate it by √window.
	 */
	public function testTheZScoreDoesNotGrowWithTheWindow(): void
	{
		\mt_srand(31337);
		$n = 6000;
		$sizes = [];
		for ($i = 0; $i < $n; $i++) {
			$sizes[] = (float) \mt_rand(1, 1000) / 10.0;
		}
		$prices = \array_fill(0, $n, 1.0);
		$signs = \array_fill(0, $n, 0.0);

		$spread = static function (int $window) use ($prices, $sizes, $signs): float {
			$z = VolumeProfile::compute($prices, $sizes, $signs, $window, 1000)['z'];
			$finite = \array_values(\array_filter($z, static fn (float $v): bool => \is_finite($v)));
			$mean = \array_sum($finite) / \count($finite);
			$sq = 0.0;
			foreach ($finite as $v) {
				$sq += ($v - $mean) ** 2;
			}
			return \sqrt($sq / \count($finite));
		};

		$small = $spread(10);
		$large = $spread(40);

		// four times the window, and the dispersion of the reading stays the
		// same order — it would double without the root
		self::assertGreaterThan(0.3, $small);
		self::assertLessThan(3.0, $small);
		self::assertLessThan(2.0 * $small, $large, 'the reading must not scale with the window');
	}

	/** A tape of identical sizes has no surprise in it at all. */
	public function testAConstantTapeHasNoSurprise(): void
	{
		$sizes = \array_fill(0, 100, 5.0);
		$prices = \array_fill(0, 100, 2.0);
		$signs = \array_fill(0, 100, 0.0);

		$out = VolumeProfile::compute($prices, $sizes, $signs, 10, 50);

		// every reference size is identical, so its dispersion is zero and the
		// z-score is undefined rather than infinite
		self::assertNan($out['z'][99]);
		// while the normalised form is exactly one: the window delivered what was expected
		self::assertEqualsWithDelta(1.0, $out['normalized'][99], self::TOLERANCE);
	}

	/**
	 * The delta is signed buy volume less sell volume, and unsigned prints
	 * count towards neither side while still counting towards the total.
	 */
	public function testUnsignedPrintsCountTowardsVolumeButNotTheDelta(): void
	{
		$sizes = [10.0, 10.0, 10.0];
		$signs = [1.0, -1.0, 0.0];
		$prices = \array_fill(0, 3, 1.0);

		$out = VolumeProfile::compute($prices, $sizes, $signs, 3, 3);

		self::assertEqualsWithDelta(30.0, $out['volume'][2], self::TOLERANCE);
		// one buy of 10 against a sell of 10 plus an unsigned 10 counted as sell-side
		self::assertEqualsWithDelta(10.0 - 20.0, $out['delta'][2], self::TOLERANCE);
		self::assertEqualsWithDelta(-10.0 / 30.0, $out['imbalance'][2], self::TOLERANCE);
	}

	/** An all-buy tape is fully imbalanced, an all-sell one the other way. */
	public function testAOneSidedTapeIsFullyImbalanced(): void
	{
		$prices = \array_fill(0, 20, 1.0);
		$sizes = \array_fill(0, 20, 4.0);

		$buys = VolumeProfile::compute($prices, $sizes, \array_fill(0, 20, 1.0), 10, 10);
		self::assertEqualsWithDelta(1.0, $buys['imbalance'][19], self::TOLERANCE);

		$sells = VolumeProfile::compute($prices, $sizes, \array_fill(0, 20, -1.0), 10, 10);
		self::assertEqualsWithDelta(-1.0, $sells['imbalance'][19], self::TOLERANCE);
	}

	/** The notional is the size weighted by the price it printed at. */
	public function testTheNotionalWeighsSizeByPrice(): void
	{
		$metric = new VolumeProfile(3, 3);
		$metric->updateTrade(Trade::at(1, 10.0, 2.0, TradeSide::Buy));
		$metric->updateTrade(Trade::at(2, 20.0, 3.0, TradeSide::Sell));

		$values = $metric->values();
		self::assertEqualsWithDelta(5.0, $values['volume'], self::TOLERANCE);
		self::assertEqualsWithDelta(2.0 * 10.0 + 3.0 * 20.0, $values['notional'], self::TOLERANCE);
		self::assertEqualsWithDelta(2.0 - 3.0, $values['delta'], self::TOLERANCE);
	}

	public function testWarmUpAndReset(): void
	{
		[$prices, $sizes, $signs] = self::tape(60, 2020);
		$metric = new VolumeProfile(5, 30);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		foreach ($sizes as $i => $size) {
			$side = match (true) {
				$signs[$i] > 0.0 => TradeSide::Buy,
				$signs[$i] < 0.0 => TradeSide::Sell,
				default => TradeSide::Unknown,
			};
			$metric->updateTrade(Trade::at(($i + 1) * 1_000_000_000, $prices[$i], $size, $side));
			self::assertSame($i >= 29, $metric->isReady(), "trade $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertSame(0.0, $metric->volume());
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		[$prices, $sizes, $signs] = self::tape(300, 4004);
		$kernel = VolumeProfile::compute($prices, $sizes, $signs, 10, 100);

		$metric = new VolumeProfile(10, 100);
		foreach ($sizes as $i => $size) {
			$side = match (true) {
				$signs[$i] > 0.0 => TradeSide::Buy,
				$signs[$i] < 0.0 => TradeSide::Sell,
				default => TradeSide::Unknown,
			};
			$metric->updateTrade(Trade::at(($i + 1) * 1_000_000_000, $prices[$i], $size, $side));

			if (\is_nan($kernel['z'][$i])) {
				self::assertNan($metric->value(), "z at $i");
			} else {
				self::assertSame($kernel['z'][$i], $metric->value(), "z at $i");
			}
			self::assertSame($kernel['volume'][$i], $metric->values()['volume'], "volume at $i");
		}
	}

	public function testWindowMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new VolumeProfile(0, 100);
	}

	public function testTheReferenceWindowMustNotBeShorterThanTheWindow(): void
	{
		$this->expectException(InvalidArgument::class);
		new VolumeProfile(100, 50);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		VolumeProfile::compute([1.0, 2.0], [1.0], [1.0, 1.0], 1, 1);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new VolumeProfile(50, 500);
		self::assertSame(['type' => 'volume-profile', 'window' => 50, 'reference' => 500], $metric->toArray());
		self::assertSame($metric->toArray(), VolumeProfile::fromArray($metric->toArray())->toArray());
	}
}
