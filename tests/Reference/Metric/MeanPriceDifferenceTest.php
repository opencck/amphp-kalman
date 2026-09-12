<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Price\MeanPriceDifference;
use PHPUnit\Framework\TestCase;

/**
 * Mean-price difference (M-04) — the reference PromQL `NdT` — against a naive
 * implementation built from plain array slices.
 *
 * Two things are worth pinning beyond the arithmetic. The metric reports the
 * defect in its own definition through `overlapFor()`, so that fraction is
 * checked against the closed form it comes from; and on a perfectly linear
 * ramp the difference of two means separated by `lag` is exactly the slope
 * times the lag, which is the one case where the formula is unambiguous and
 * can be written down by hand.
 */
final class MeanPriceDifferenceTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive NdT: mean the price over `smoothWindow`, take that mean now and
	 * `lag` steps ago, divide by the mean price over `levelWindow`.
	 *
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naive(array $prices, int $smoothWindow, int $lag, int $levelWindow): array
	{
		$means = [];
		foreach ($prices as $i => $_) {
			$means[] = $i + 1 < $smoothWindow
				? \NAN
				: \array_sum(\array_slice($prices, $i + 1 - $smoothWindow, $smoothWindow)) / $smoothWindow;
		}

		$out = [];
		foreach ($prices as $i => $_) {
			$past = $i - $lag;
			if ($past < 0 || $i + 1 < $levelWindow || \is_nan($means[$i]) || \is_nan($means[$past])) {
				$out[] = \NAN;
				continue;
			}
			$level = \array_sum(\array_slice($prices, $i + 1 - $levelWindow, $levelWindow)) / $levelWindow;
			$out[] = $level > 0.0 ? ($means[$i] - $means[$past]) / $level : \NAN;
		}
		return $out;
	}

	/**
	 * Largest finite reading of a series: max() over one that still carries its
	 * warm-up NANs would simply return NAN.
	 *
	 * @param list<float> $series
	 */
	private static function peakOf(array $series): float
	{
		$highest = -\INF;
		foreach ($series as $value) {
			if (\is_finite($value) && $value > $highest) {
				$highest = $value;
			}
		}
		self::assertGreaterThan(-\INF, $highest, 'the series must contain a finite reading');
		return $highest;
	}

	/** @return list<float> */
	private static function prices(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 400.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-250, 250) / 100.0;
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

	/** @return iterable<string, array{int, int, int}> */
	public function configurations(): iterable
	{
		yield 'reference 30/15/120' => [30, 15, 120];
		yield 'disjoint 10/10/60' => [10, 10, 60];
		yield 'lag beyond the window 8/20/50' => [8, 20, 50];
		yield 'no smoothing 1/1/30' => [1, 1, 30];
		yield 'above the rescan boundary 17/9/40' => [17, 9, 40];
	}

	/** @dataProvider configurations */
	public function testMatchesANaiveImplementation(int $smooth, int $lag, int $level): void
	{
		$prices = self::prices(600, $smooth * 31 + $lag);
		self::assertSeriesMatches(
			self::naive($prices, $smooth, $lag, $level),
			MeanPriceDifference::compute($prices, $smooth, $lag, $level),
			"compute($smooth, $lag, $level)",
		);
	}

	/**
	 * The overlap is (w − L) / w, clamped at zero once the lag clears the
	 * window. The reference configuration 30/15 gives exactly one half: half of
	 * every reading is the same data subtracted from itself.
	 */
	public function testOverlapMatchesTheClosedForm(): void
	{
		self::assertSame(0.5, MeanPriceDifference::overlapFor(30, 15));
		self::assertSame(0.0, MeanPriceDifference::overlapFor(15, 15), 'touching windows share nothing');
		self::assertSame(0.0, MeanPriceDifference::overlapFor(10, 40), 'a lag past the window cannot overlap');
		self::assertEqualsWithDelta(0.9, MeanPriceDifference::overlapFor(10, 1), self::TOLERANCE);

		// and the instance reports its own configuration
		self::assertSame(0.5, (new MeanPriceDifference(30, 15, 120))->overlap());
		self::assertSame(0.5, (new MeanPriceDifference(30, 15, 120))->values()['overlap']);

		for ($w = 1; $w <= 40; $w++) {
			for ($l = 1; $l <= 40; $l++) {
				$shared = $w - $l;
				self::assertSame(
					$shared <= 0 ? 0.0 : $shared / $w,
					MeanPriceDifference::overlapFor($w, $l),
					"overlap($w, $l)",
				);
			}
		}
	}

	/**
	 * On an exact linear ramp the mean over any window is the price at its
	 * centre, so the difference of two means `lag` apart is exactly slope × lag
	 * — independently of the smoothing window. Dividing by the level mean gives
	 * a value that can be written down in closed form.
	 */
	public function testOnALinearRampTheValueIsSlopeTimesLagOverTheLevel(): void
	{
		$slope = 0.25;
		$base = 1000.0;
		$n = 400;
		$prices = [];
		for ($i = 0; $i < $n; $i++) {
			$prices[] = $base + $slope * $i;
		}

		$smooth = 30;
		$lag = 15;
		$level = 120;
		$out = MeanPriceDifference::compute($prices, $smooth, $lag, $level);

		for ($i = 200; $i < $n; $i++) {
			// the mean over the trailing level window of a ramp is the price at its centre
			$levelMean = $base + $slope * ($i - ($level - 1) / 2.0);
			self::assertEqualsWithDelta($slope * $lag / $levelMean, $out[$i], self::TOLERANCE, "index $i");
		}
	}

	/** A flat market has no difference at all — exactly zero, not nearly. */
	public function testAFlatSeriesGivesExactlyZero(): void
	{
		$flat = \array_fill(0, 200, 75.0);
		$out = MeanPriceDifference::compute($flat, 30, 15, 120);
		for ($i = 130; $i < 200; $i++) {
			self::assertSame(0.0, $out[$i], "index $i");
		}
	}

	/**
	 * Normalising by the level makes the reading dimensionless: scaling every
	 * price leaves it unchanged.
	 */
	public function testTheReadingIsInvariantUnderScaling(): void
	{
		$prices = self::prices(400, 2024);
		$scaled = \array_map(static fn (float $p): float => 60.0 * $p, $prices);

		self::assertSeriesMatches(
			MeanPriceDifference::compute($prices, 30, 15, 120),
			MeanPriceDifference::compute($scaled, 30, 15, 120),
			'under scaling',
		);
	}

	/**
	 * The overlap is not a cosmetic number: with windows that share half their
	 * data, a step change in the price is reported smaller than the same step
	 * measured over disjoint windows.
	 */
	public function testOverlappingWindowsCancelPartOfAStep(): void
	{
		// flat, then a clean step up, then flat again
		$prices = [];
		for ($i = 0; $i < 400; $i++) {
			$prices[] = $i < 200 ? 100.0 : 110.0;
		}

		$overlapping = MeanPriceDifference::compute($prices, 30, 15, 120);
		$disjoint = MeanPriceDifference::compute($prices, 15, 15, 120);

		self::assertSame(0.5, MeanPriceDifference::overlapFor(30, 15));
		self::assertSame(0.0, MeanPriceDifference::overlapFor(15, 15));

		self::assertGreaterThan(
			self::peakOf($overlapping),
			self::peakOf($disjoint),
			'disjoint windows must report the step at least as large as overlapping ones',
		);
	}

	public function testWarmUpAndReset(): void
	{
		$prices = self::prices(200, 3141);
		$metric = new MeanPriceDifference(10, 5, 40);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
		}
		self::assertTrue($metric->isReady());
		self::assertTrue(\is_finite($metric->value()));

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		// the overlap is a property of the configuration and survives a reset
		self::assertSame(0.5, $metric->overlap());
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		$prices = self::prices(500, 2718);
		$kernel = MeanPriceDifference::compute($prices, 30, 15, 120);

		$metric = new MeanPriceDifference(30, 15, 120);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			if (\is_nan($kernel[$i])) {
				self::assertNan($metric->value(), "index $i");
			} else {
				self::assertSame($kernel[$i], $metric->value(), "index $i");
			}
		}
	}

	/** @return iterable<string, array{int, int, int}> */
	public function invalidConfigurations(): iterable
	{
		yield 'zero smoothing window' => [0, 15, 120];
		yield 'zero lag' => [30, 0, 120];
		yield 'zero level window' => [30, 15, 0];
	}

	/** @dataProvider invalidConfigurations */
	public function testWindowsAndLagMustBePositive(int $smooth, int $lag, int $level): void
	{
		$this->expectException(InvalidArgument::class);
		new MeanPriceDifference($smooth, $lag, $level);
	}

	/** @dataProvider invalidConfigurations */
	public function testKernelRejectsTheSameConfigurations(int $smooth, int $lag, int $level): void
	{
		$this->expectException(InvalidArgument::class);
		MeanPriceDifference::compute([1.0, 2.0], $smooth, $lag, $level);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new MeanPriceDifference(20, 7, 90);
		self::assertSame(
			['type' => 'mean-price-difference', 'smoothWindow' => 20, 'lag' => 7, 'levelWindow' => 90],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), MeanPriceDifference::fromArray($metric->toArray())->toArray());
	}
}
