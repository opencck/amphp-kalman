<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Metric;

use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;
use PHPUnit\Framework\TestCase;

/**
 * The windowed Welford update must stay exact: after thousands of pushes its
 * mean and variance still have to agree with a full recomputation over the
 * window to 1e-9. That is the whole reason the class exists instead of a
 * running sum of squares.
 */
final class RollingMomentsTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive mean of a window.
	 *
	 * @param list<float> $window
	 */
	private static function naiveMean(array $window): float
	{
		$sum = 0.0;
		foreach ($window as $value) {
			$sum += $value;
		}
		return $sum / \count($window);
	}

	/**
	 * Naive unbiased (N−1) variance of a window.
	 *
	 * @param list<float> $window
	 */
	private static function naiveVariance(array $window): float
	{
		$n = \count($window);
		$mean = self::naiveMean($window);
		$m2 = 0.0;
		foreach ($window as $value) {
			$d = $value - $mean;
			$m2 += $d * $d;
		}
		return $m2 / ($n - 1);
	}

	/**
	 * Naive population (N) variance of a window.
	 *
	 * @param list<float> $window
	 */
	private static function naivePopulationVariance(array $window): float
	{
		$n = \count($window);
		$mean = self::naiveMean($window);
		$m2 = 0.0;
		foreach ($window as $value) {
			$d = $value - $mean;
			$m2 += $d * $d;
		}
		return $m2 / $n;
	}

	/**
	 * Naive mean absolute deviation from the window mean.
	 *
	 * @param list<float> $window
	 */
	private static function naiveMad(array $window): float
	{
		$mean = self::naiveMean($window);
		$sum = 0.0;
		foreach ($window as $value) {
			$sum += \abs($value - $mean);
		}
		return $sum / \count($window);
	}

	/**
	 * @return iterable<string, array{int}>
	 */
	public function periods(): iterable
	{
		yield 'period 2' => [2];
		yield 'period 5' => [5];
		yield 'period 20' => [20];
		yield 'period 64' => [64];
	}

	/**
	 * A price-like series: a large level with a small spread, which is exactly
	 * the regime where subtracting x² from a running Σx² loses all
	 * significance. `refreshEvery` is set far beyond the number of pushes so
	 * that the exact rescan never runs and only the O(1) recurrence is tested.
	 *
	 * @dataProvider periods
	 */
	public function testMeanAndVarianceMatchNaiveRecomputationAfterThousandsOfPushes(int $period): void
	{
		\mt_srand(4242 + $period);
		$moments = new RollingMoments($period, \PHP_INT_MAX);
		$window = [];

		for ($i = 0; $i < 5000; $i++) {
			$value = 60000.0 + (float) \mt_rand(-500, 500) / 100.0;

			$moments->push($value);
			$window[] = $value;
			if (\count($window) > $period) {
				\array_shift($window);
			}

			self::assertSame(\count($window), $moments->count());
			self::assertEqualsWithDelta(self::naiveMean($window), $moments->mean(), self::TOLERANCE, "mean at push $i");
			if (\count($window) >= 2) {
				$variance = self::naiveVariance($window);
				self::assertEqualsWithDelta($variance, $moments->variance(), self::TOLERANCE, "variance at push $i");
				self::assertEqualsWithDelta(
					self::naivePopulationVariance($window),
					$moments->populationVariance(),
					self::TOLERANCE,
					"population variance at push $i",
				);
				self::assertEqualsWithDelta(\sqrt($variance), $moments->stdDev(), self::TOLERANCE, "stdDev at push $i");
			}
		}
		self::assertTrue($moments->isFull());
	}

	/**
	 * The periodic exact rescan must not move the answer.
	 *
	 * @dataProvider periods
	 */
	public function testRefreshDoesNotChangeTheAnswer(int $period): void
	{
		\mt_srand(99 + $period);
		$values = [];
		for ($i = 0; $i < 2000; $i++) {
			$values[] = 60000.0 + (float) \mt_rand(-500, 500) / 100.0;
		}

		$lazy = new RollingMoments($period, \PHP_INT_MAX);
		$eager = new RollingMoments($period, 7);
		foreach ($values as $value) {
			$lazy->push($value);
			$eager->push($value);
		}

		self::assertEqualsWithDelta($lazy->mean(), $eager->mean(), self::TOLERANCE);
		self::assertEqualsWithDelta($lazy->variance(), $eager->variance(), self::TOLERANCE);
	}

	/** @dataProvider periods */
	public function testMeanAbsoluteDeviationMatchesANaiveScan(int $period): void
	{
		\mt_srand(777 + $period);
		$moments = new RollingMoments($period, \PHP_INT_MAX);
		$window = [];

		for ($i = 0; $i < 1200; $i++) {
			$value = 100.0 + (float) \mt_rand(-30000, 30000) / 1000.0;

			$moments->push($value);
			$window[] = $value;
			if (\count($window) > $period) {
				\array_shift($window);
			}

			self::assertEqualsWithDelta(
				self::naiveMad($window),
				$moments->meanAbsoluteDeviation(),
				self::TOLERANCE,
				"mad at push $i",
			);
		}
	}

	/**
	 * For a normal sample the mean absolute deviation is about 0.7979 standard
	 * deviations — the constant that makes substituting one for the other in
	 * the CCI wrong by a quarter.
	 */
	public function testMeanAbsoluteDeviationIsAboutPointEightOfTheStandardDeviation(): void
	{
		\mt_srand(31337);
		$moments = new RollingMoments(4096, \PHP_INT_MAX);
		for ($i = 0; $i < 4096; $i++) {
			$moments->push(100.0 + self::gaussian());
		}
		$ratio = $moments->meanAbsoluteDeviation() / $moments->populationStdDev();
		self::assertEqualsWithDelta(\sqrt(2.0 / \M_PI), $ratio, 0.02);
	}

	public function testEmptyAndSingletonWindowsAreUndefinedRatherThanZero(): void
	{
		$moments = new RollingMoments(4);
		self::assertNan($moments->mean());
		self::assertNan($moments->variance());
		self::assertNan($moments->populationVariance());
		self::assertNan($moments->stdDev());
		self::assertNan($moments->meanAbsoluteDeviation());

		$moments->push(5.0);
		self::assertSame(5.0, $moments->mean());
		self::assertNan($moments->variance());
		self::assertSame(0.0, $moments->populationVariance());
		self::assertSame(0.0, $moments->meanAbsoluteDeviation());
	}

	public function testResetClearsEverything(): void
	{
		$moments = new RollingMoments(3);
		foreach ([1.0, 2.0, 3.0] as $value) {
			$moments->push($value);
		}
		self::assertTrue($moments->isFull());

		$moments->reset();
		self::assertSame(0, $moments->count());
		self::assertFalse($moments->isFull());
		self::assertNan($moments->mean());

		$moments->push(10.0);
		self::assertSame(10.0, $moments->mean());
		self::assertSame(10.0, $moments->oldest());
		self::assertSame(10.0, $moments->newest());
	}

	/** Box-Muller on a seeded mt_rand, so the sample is reproducible. */
	private static function gaussian(): float
	{
		$u1 = (\mt_rand(1, \mt_getrandmax()) / \mt_getrandmax());
		$u2 = (\mt_rand(0, \mt_getrandmax()) / \mt_getrandmax());
		return \sqrt(-2.0 * \log($u1)) * \cos(2.0 * \M_PI * $u2);
	}
}
