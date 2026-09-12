<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Price\NormalizedPrice;
use PHPUnit\Framework\TestCase;

/**
 * Normalised price (M-01) against a naive window implementation that keeps the
 * whole window in an array and sums it from scratch every step.
 *
 * The three forms differ in what they are invariant to, and that is the point
 * of the metric: the z-score survives an affine change of units, the ratio and
 * the log ratio survive only a change of scale. A threshold written against the
 * ratio therefore does not carry between instruments, which is the criticism
 * the class docblock makes of the reference form — so it is tested, not merely
 * asserted.
 */
final class NormalizedPriceTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Mean and population standard deviation of the trailing window, summed
	 * from scratch — no incremental state, nothing shared with RollingMoments.
	 *
	 * @param list<float> $window
	 * @return array{float, float}
	 */
	private static function naiveMoments(array $window): array
	{
		$n = \count($window);
		$sum = 0.0;
		foreach ($window as $v) {
			$sum += $v;
		}
		$mean = $sum / $n;

		$sq = 0.0;
		foreach ($window as $v) {
			$sq += ($v - $mean) ** 2;
		}
		return [$mean, \sqrt($sq / $n)];
	}

	/**
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naive(array $prices, int $period, string $form): array
	{
		$out = [];
		foreach ($prices as $i => $price) {
			if ($i + 1 < $period) {
				$out[] = \NAN;
				continue;
			}
			$window = \array_slice($prices, $i + 1 - $period, $period);
			[$mean, $sigma] = self::naiveMoments($window);

			$out[] = match ($form) {
				'z' => $sigma > 0.0 ? ($price - $mean) / $sigma : \NAN,
				'ratio' => $mean > 0.0 ? $price / $mean : \NAN,
				'log' => $mean > 0.0 && $price > 0.0 ? \log($price / $mean) : \NAN,
				default => throw new \InvalidArgumentException("unknown form $form"),
			};
		}
		return $out;
	}

	/** @return list<float> */
	private static function prices(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 100.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-150, 150) / 100.0;
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
	public function periods(): iterable
	{
		yield 'period 2' => [2];
		yield 'period 3' => [3];
		yield 'period 16' => [16];
		yield 'period 17' => [17];
		yield 'period 120' => [120];
	}

	/**
	 * Period 16 and 17 are both here on purpose: RollingMoments rescans exactly
	 * for windows of sixteen values or fewer and accumulates incrementally above
	 * that, so the two sides of that boundary are different code paths.
	 *
	 * @dataProvider periods
	 */
	public function testZScoreMatchesANaiveWindow(int $period): void
	{
		$prices = self::prices(600, 300 + $period);
		self::assertSeriesMatches(
			self::naive($prices, $period, 'z'),
			NormalizedPrice::zScore($prices, $period),
			"zScore($period)",
		);
	}

	/** @dataProvider periods */
	public function testRatioMatchesANaiveWindow(int $period): void
	{
		$prices = self::prices(600, 400 + $period);
		self::assertSeriesMatches(
			self::naive($prices, $period, 'ratio'),
			NormalizedPrice::ratio($prices, $period),
			"ratio($period)",
		);
	}

	/** @dataProvider periods */
	public function testLogRatioMatchesANaiveWindow(int $period): void
	{
		$prices = self::prices(600, 500 + $period);
		self::assertSeriesMatches(
			self::naive($prices, $period, 'log'),
			NormalizedPrice::logRatio($prices, $period),
			"logRatio($period)",
		);
	}

	/**
	 * Values derived by hand from the definition: window [1, 2, 3], so μ = 2,
	 * the population variance is ((−1)² + 0² + 1²) / 3 = 2/3 and σ = √(2/3).
	 * The last price is 3, so z = 1 / √(2/3) = √1.5.
	 */
	public function testPinnedValuesFromTheDefinition(): void
	{
		$prices = [1.0, 2.0, 3.0];

		self::assertEqualsWithDelta(\sqrt(1.5), NormalizedPrice::zScore($prices, 3)[2], self::TOLERANCE);
		self::assertEqualsWithDelta(1.5, NormalizedPrice::ratio($prices, 3)[2], self::TOLERANCE);
		self::assertEqualsWithDelta(\log(1.5), NormalizedPrice::logRatio($prices, 3)[2], self::TOLERANCE);

		// and the first two entries have no full window yet
		self::assertNan(NormalizedPrice::zScore($prices, 3)[0]);
		self::assertNan(NormalizedPrice::zScore($prices, 3)[1]);
	}

	/**
	 * The z-score is invariant under any affine change of units with a positive
	 * scale: measuring in cents instead of dollars, or against a shifted origin,
	 * leaves it alone. This is the property that makes a threshold transfer
	 * between instruments.
	 */
	public function testZScoreIsInvariantUnderAnAffineChangeOfUnits(): void
	{
		$prices = self::prices(300, 7717);
		$transformed = \array_map(static fn (float $p): float => 3.5 * $p + 40.0, $prices);

		self::assertSeriesMatches(
			NormalizedPrice::zScore($prices, 32),
			NormalizedPrice::zScore($transformed, 32),
			'z under affine transform',
		);
	}

	/**
	 * The ratio and the log ratio survive a change of scale but not a shift:
	 * they are ratios to the mean, and a shift moves price and mean by the same
	 * amount without moving their quotient the same way.
	 */
	public function testRatioSurvivesScalingButNotShifting(): void
	{
		$prices = self::prices(300, 8811);
		$scaled = \array_map(static fn (float $p): float => 7.0 * $p, $prices);
		$shifted = \array_map(static fn (float $p): float => $p + 25.0, $prices);

		self::assertSeriesMatches(
			NormalizedPrice::ratio($prices, 32),
			NormalizedPrice::ratio($scaled, 32),
			'ratio under scaling',
		);

		$plain = NormalizedPrice::ratio($prices, 32);
		$moved = NormalizedPrice::ratio($shifted, 32);
		$differs = false;
		foreach ($plain as $i => $value) {
			if (!\is_nan($value) && \abs($value - $moved[$i]) > 1e-6) {
				$differs = true;
				break;
			}
		}
		self::assertTrue($differs, 'the ratio must not be shift-invariant — that is the weakness being documented');
	}

	/**
	 * A flat window has σ = 0. The z-score is then undefined rather than zero
	 * or infinite; the ratio is exactly 1 and the log exactly 0.
	 */
	public function testAFlatWindowLeavesTheZScoreUndefined(): void
	{
		$flat = \array_fill(0, 40, 50.0);

		$z = NormalizedPrice::zScore($flat, 10);
		$ratio = NormalizedPrice::ratio($flat, 10);
		$log = NormalizedPrice::logRatio($flat, 10);

		for ($i = 9; $i < 40; $i++) {
			self::assertNan($z[$i], "z at $i");
			self::assertSame(1.0, $ratio[$i], "ratio at $i");
			self::assertSame(0.0, $log[$i], "log at $i");
		}
	}

	/** A non-positive mean or price has no ratio and no logarithm. */
	public function testNonPositiveInputsAreUndefinedRatherThanInfinite(): void
	{
		$aroundZero = [-1.0, 0.0, 1.0, 0.0];
		self::assertNan(NormalizedPrice::ratio($aroundZero, 4)[3], 'a zero mean has no ratio');
		self::assertNan(NormalizedPrice::logRatio($aroundZero, 4)[3], 'a zero mean has no logarithm');

		$negativeLast = [10.0, 10.0, 10.0, -5.0];
		self::assertNan(NormalizedPrice::logRatio($negativeLast, 4)[3], 'a negative price has no logarithm');
		self::assertTrue(\is_finite(NormalizedPrice::ratio($negativeLast, 4)[3]), 'but it still has a ratio');
	}

	public function testWarmUpAndReset(): void
	{
		$prices = self::prices(30, 9091);
		$metric = new NormalizedPrice(10);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['ratio']);

		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			self::assertSame($i >= 9, $metric->isReady(), "tick $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['z']);
		self::assertNan($metric->values()['log']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$prices = self::prices(400, 1123);
		$z = NormalizedPrice::zScore($prices, 24);
		$ratio = NormalizedPrice::ratio($prices, 24);
		$log = NormalizedPrice::logRatio($prices, 24);

		$metric = new NormalizedPrice(24);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			$values = $metric->values();

			foreach (['z' => $z[$i], 'ratio' => $ratio[$i], 'log' => $log[$i]] as $key => $want) {
				if (\is_nan($want)) {
					self::assertNan($values[$key], "$key at $i");
				} else {
					self::assertSame($want, $values[$key], "$key at $i");
				}
			}
		}
	}

	public function testPeriodMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new NormalizedPrice(1);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new NormalizedPrice(64);
		self::assertSame(['type' => 'normalized-price', 'period' => 64], $metric->toArray());
		self::assertSame($metric->toArray(), NormalizedPrice::fromArray($metric->toArray())->toArray());
	}
}
