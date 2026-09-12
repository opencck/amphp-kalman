<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Filtered\Derivative;
use OpenCCK\Kalman\Tests\Reference\NaiveKalmanFilter;
use PHPUnit\Framework\TestCase;

/**
 * Filtered derivative (M-02) against the textbook Kalman filter in
 * `NaiveKalmanFilter` — nested arrays, an explicit inverse and the (I−KH)P
 * form, none of which the production path uses.
 *
 * The metric is a constant-velocity filter with its noise calibrated from the
 * series. Given an explicit `noiseStd` and a regular clock the whole
 * configuration is determined in closed form — R = σ², q = (κ·σ·√3 / Δt^1.5)²,
 * a velocity prior of σ/Δt — so the naive filter can be driven with exactly the
 * same matrices and the two must agree to 1e-9.
 *
 * Beyond that, the reason this metric exists at all is measured rather than
 * asserted: against a known true rate it beats a finite difference, which is
 * the claim `ROADMAP` makes for replacing fourteen `dX` columns with one
 * filter.
 */
final class DerivativeTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Drives the textbook filter and the metric over the same series, step by
	 * step, asserting they agree at every observation.
	 *
	 * The two are compared in place rather than through returned arrays: a
	 * divergence is then reported at the observation where it first appears.
	 *
	 * @param list<int> $timestampsNs
	 * @param list<float> $values
	 */
	private static function assertAgreesWithTheTextbookFilter(
		array $timestampsNs,
		array $values,
		float $trackingIndex,
		float $noise,
		int $calibrationWindow,
	): void {
		// the interval the metric calibrates against: the mean gap over the
		// calibration window, which on a regular clock is that gap
		$intervals = [];
		for ($i = 1; $i <= $calibrationWindow; $i++) {
			$intervals[] = ($timestampsNs[$i] - $timestampsNs[$i - 1]) / 1e9;
		}
		$interval = \array_sum($intervals) / \count($intervals);

		$processNoise = $trackingIndex * $noise * \M_SQRT3 / ($interval ** 1.5);
		$q = $processNoise * $processNoise;
		$r = $noise * $noise;
		$velocityPrior = $noise / $interval;

		$metric = new Derivative($trackingIndex, $noise, $calibrationWindow);
		$filter = null;
		$previousNs = 0;

		foreach ($values as $i => $value) {
			$metric->updatePrice($timestampsNs[$i], $value);

			if ($i < $calibrationWindow) {
				self::assertNan($metric->value(), "still calibrating at $i");
				$previousNs = $timestampsNs[$i];
				continue;
			}

			if ($i === $calibrationWindow) {
				$filter = new NaiveKalmanFilter(
					[$value, 0.0],
					[[$r, 0.0], [0.0, $velocityPrior * $velocityPrior]],
				);
				$previousNs = $timestampsNs[$i];
				// seeded but not yet stepped: the metric is not ready either
				self::assertNan($metric->value(), "seeded but unstepped at $i");
				continue;
			}

			self::assertNotNull($filter);
			$dt = ($timestampsNs[$i] - $previousNs) / 1e9;
			$previousNs = $timestampsNs[$i];

			$dt2 = $dt * $dt;
			$filter->predict(
				[[1.0, $dt], [0.0, 1.0]],
				[[$q * $dt2 * $dt / 3.0, $q * $dt2 * 0.5], [$q * $dt2 * 0.5, $q * $dt]],
			);
			$filter->correct([[1.0, 0.0]], [[$r]], [$value]);

			$rate = $metric->value();
			$level = $metric->level();

			self::assertEqualsWithDelta(
				$filter->x[1],
				$rate,
				\max(self::TOLERANCE, \abs($rate) * 1e-9),
				"rate at $i",
			);
			self::assertEqualsWithDelta(
				$filter->x[0],
				$level,
				\max(self::TOLERANCE, \abs($level) * 1e-9),
				"level at $i",
			);
		}
	}

	/**
	 * @return array{list<int>, list<float>}
	 */
	private static function series(int $n, int $seed, float $slope, float $noise, int $stepNs = 1_000_000_000): array
	{
		\mt_srand($seed);
		$timestamps = [];
		$values = [];
		for ($i = 0; $i < $n; $i++) {
			$timestamps[] = ($i + 1) * $stepNs;
			$truth = 100.0 + $slope * ($i * $stepNs / 1e9);
			$values[] = $truth + $noise * ((float) \mt_rand(-1000, 1000) / 1000.0);
		}
		return [$timestamps, $values];
	}

	/** @return iterable<string, array{float, float, int}> */
	public function configurations(): iterable
	{
		yield 'default tracking, 50 samples' => [0.01, 0.5, 50];
		yield 'fast tracking' => [0.5, 0.5, 20];
		yield 'slow tracking' => [0.001, 0.2, 30];
		yield 'short calibration' => [0.05, 1.0, 5];
	}

	/** @dataProvider configurations */
	public function testMatchesTheTextbookFilter(float $trackingIndex, float $noise, int $calibrationWindow): void
	{
		[$timestamps, $values] = self::series(300, (int) ($trackingIndex * 1000) + $calibrationWindow, 0.05, $noise);

		self::assertAgreesWithTheTextbookFilter($timestamps, $values, $trackingIndex, $noise, $calibrationWindow);
	}

	/**
	 * The reason the metric replaces a finite difference: against a known true
	 * rate it is the more accurate estimator. Measured over a noisy ramp, not
	 * asserted.
	 */
	public function testItEstimatesAKnownRateBetterThanAFiniteDifference(): void
	{
		$slope = 0.05;
		$noise = 0.5;
		$n = 600;
		[$timestamps, $values] = self::series($n, 4242, $slope, $noise);

		$metric = new Derivative(0.01, $noise, 50);

		$filterError = 0.0;
		$differenceError = 0.0;
		$counted = 0;

		foreach ($values as $i => $value) {
			$metric->updatePrice($timestamps[$i], $value);
			if (!$metric->isReady() || $i === 0) {
				continue;
			}
			$dt = ($timestamps[$i] - $timestamps[$i - 1]) / 1e9;
			$difference = ($value - $values[$i - 1]) / $dt;

			$filterError += ($metric->value() - $slope) ** 2;
			$differenceError += ($difference - $slope) ** 2;
			$counted++;
		}

		self::assertGreaterThan(100, $counted);
		self::assertLessThan(
			$differenceError / 10.0,
			$filterError,
			'the filter must beat a finite difference by a wide margin on a noisy ramp',
		);
	}

	/** On a clean ramp the estimated rate converges to the true slope. */
	public function testOnANoiselessRampTheRateConvergesToTheSlope(): void
	{
		foreach ([0.25, -1.5] as $slope) {
			$n = 600;
			$timestamps = [];
			$values = [];
			for ($i = 0; $i < $n; $i++) {
				$timestamps[] = ($i + 1) * 1_000_000_000;
				$values[] = 100.0 + $slope * $i;
			}

			$metric = new Derivative(0.01, 0.1, 50);
			foreach ($values as $i => $value) {
				$metric->updatePrice($timestamps[$i], $value);
			}

			self::assertEqualsWithDelta($slope, $metric->value(), 1e-6, "slope $slope");
			self::assertEqualsWithDelta($values[$n - 1], $metric->level(), 1e-3, "level for slope $slope");
		}
	}

	/** A flat series has a rate of zero and a t-statistic to match. */
	public function testAFlatSeriesHasNoRate(): void
	{
		$metric = new Derivative(0.01, 0.1, 20);
		for ($i = 0; $i < 200; $i++) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, 50.0);
		}

		self::assertTrue($metric->isReady());
		self::assertEqualsWithDelta(0.0, $metric->value(), 1e-9);
		self::assertEqualsWithDelta(50.0, $metric->level(), 1e-9);
		self::assertEqualsWithDelta(0.0, $metric->values()['tStat'], 1e-9);
	}

	/** The t-statistic is the rate over its own standard error. */
	public function testTheTStatisticIsTheRateOverItsStandardError(): void
	{
		[$timestamps, $values] = self::series(300, 9182, 0.05, 0.5);

		$metric = new Derivative(0.01, 0.5, 50);
		foreach ($values as $i => $value) {
			$metric->updatePrice($timestamps[$i], $value);
		}

		$out = $metric->values();
		self::assertEqualsWithDelta($out['rate'] / $out['rateStd'], $out['tStat'], self::TOLERANCE);
		self::assertGreaterThan(0.0, $out['rateStd']);
		self::assertGreaterThan(0.0, $out['levelStd']);
		self::assertSame($out['rate'], $metric->value());
		self::assertSame($out['level'], $metric->level());
	}

	/**
	 * The rate is per second, so it carries the units of the series divided by
	 * time: scaling the values scales the rate by the same factor, and halving
	 * the clock interval doubles it.
	 */
	public function testTheRateCarriesTheUnitsOfTheSeriesPerSecond(): void
	{
		$slope = 0.2;
		$n = 400;

		$build = static function (float $scale, int $stepNs) use ($slope, $n): float {
			$metric = new Derivative(0.01, 0.1 * $scale, 50);
			for ($i = 0; $i < $n; $i++) {
				$metric->updatePrice(($i + 1) * $stepNs, $scale * (100.0 + $slope * $i));
			}
			return $metric->value();
		};

		$base = $build(1.0, 1_000_000_000);
		self::assertEqualsWithDelta($slope, $base, 1e-6);

		// ten times the values, same clock: ten times the rate
		self::assertEqualsWithDelta(10.0 * $base, $build(10.0, 1_000_000_000), 1e-5);

		// same values, half the interval: twice the rate per second
		self::assertEqualsWithDelta(2.0 * $base, $build(1.0, 500_000_000), 1e-5);
	}

	/** NAN readings are skipped rather than fed to the filter. */
	public function testNanReadingsAreSkipped(): void
	{
		$metric = new Derivative(0.01, 0.1, 10);
		$clean = new Derivative(0.01, 0.1, 10);

		$t = 0;
		for ($i = 0; $i < 100; $i++) {
			$t += 1_000_000_000;
			$value = 100.0 + 0.1 * $i;
			$metric->updatePrice($t, $value);
			$clean->updatePrice($t, $value);
			// an interleaved NAN must change nothing
			$metric->updatePrice($t + 1, \NAN);
		}

		self::assertEqualsWithDelta($clean->value(), $metric->value(), self::TOLERANCE);
		self::assertEqualsWithDelta($clean->level(), $metric->level(), self::TOLERANCE);
	}

	/**
	 * A perfectly constant calibration window carries no information about the
	 * noise, so the metric waits for the series to move rather than inventing a
	 * scale. With an explicit noise it starts regardless.
	 */
	public function testACalibrationWindowWithNoMovementWaits(): void
	{
		$metric = new Derivative(0.01, null, 10);
		for ($i = 0; $i < 50; $i++) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, 25.0);
		}
		self::assertFalse($metric->isReady(), 'a motionless series gives no noise scale to calibrate against');
		self::assertNan($metric->value());
		self::assertNan($metric->measurementNoise());

		// once it starts moving, calibration completes
		for ($i = 50; $i < 100; $i++) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, 25.0 + 0.01 * $i);
		}
		self::assertTrue($metric->isReady());
		self::assertGreaterThan(0.0, $metric->measurementNoise());

		// an explicit noise needs no calibration of its own
		$explicit = new Derivative(0.01, 0.5, 10);
		for ($i = 0; $i < 50; $i++) {
			$explicit->updatePrice(($i + 1) * 1_000_000_000, 25.0);
		}
		self::assertTrue($explicit->isReady());
		self::assertSame(0.5, $explicit->measurementNoise());
	}

	public function testWarmUpAndReset(): void
	{
		[$timestamps, $values] = self::series(100, 1470, 0.05, 0.3);
		$metric = new Derivative(0.01, 0.3, 20);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->level());
		self::assertNan($metric->values()['tStat']);

		foreach ($values as $i => $value) {
			$metric->updatePrice($timestamps[$i], $value);
			// seeded at index 20, first stepped at 21
			self::assertSame($i >= 21, $metric->isReady(), "tick $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->measurementNoise());
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		[$timestamps, $values] = self::series(300, 7531, 0.05, 0.4);

		$series = Derivative::ofSeries($timestamps, $values, 0.01, 50);
		$rate = Derivative::rateOf($timestamps, $values, 0.01);

		$metric = new Derivative(0.01, null, 50);
		foreach ($values as $i => $value) {
			$metric->updatePrice($timestamps[$i], $value);

			if (\is_nan($series['rate'][$i])) {
				self::assertNan($metric->value(), "rate at $i");
			} else {
				self::assertSame($series['rate'][$i], $metric->value(), "rate at $i");
				self::assertSame($series['level'][$i], $metric->level(), "level at $i");
			}
			if (!\is_nan($rate[$i])) {
				self::assertSame($rate[$i], $series['rate'][$i], "rateOf at $i");
			}
		}
	}

	public function testTrackingIndexMustBeFiniteAndPositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Derivative(0.0, null, 50);
	}

	public function testAnExplicitNoiseMustBeFiniteAndPositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Derivative(0.01, 0.0, 50);
	}

	public function testTheCalibrationWindowMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new Derivative(0.01, null, 1);
	}

	public function testKernelNeedsOneTimestampPerValue(): void
	{
		$this->expectException(InvalidArgument::class);
		Derivative::ofSeries([1, 2], [1.0], 0.01, 2);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new Derivative(0.02, 0.75, 40);
		self::assertSame(
			['type' => 'filtered-derivative', 'trackingIndex' => 0.02, 'noiseStd' => 0.75, 'calibrationWindow' => 40],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), Derivative::fromArray($metric->toArray())->toArray());
	}
}
