<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Microstructure\Vpin;
use PHPUnit\Framework\TestCase;

/**
 * VPIN (M-30) against a naive implementation that fills volume buckets with a
 * plain loop.
 *
 * VPIN runs on a volume clock rather than a time clock, and the part worth
 * testing is what that means for a trade that does not fit: it is *split*
 * across the bucket boundary in proportion, not assigned whole to one side.
 * A rewrite that pushes the whole trade into the bucket it started in gets the
 * same answer whenever trades are small relative to a bucket and a different
 * one exactly when they are not — which is the case that matters, since a large
 * print is what VPIN is supposed to notice.
 *
 * The reading itself is bounded by 0 and 1 by construction, reaching 1 only
 * when every bucket is one-sided; both ends are pinned.
 */
final class VpinTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive volume clock: fill buckets of `bucketSize`, splitting a trade that
	 * straddles a boundary in proportion to how much of it fits.
	 *
	 * @param list<float> $sizes
	 * @param list<float> $signs
	 * @return list<float> the VPIN reading after each trade
	 */
	private static function naive(array $sizes, array $signs, float $bucketSize, int $buckets): array
	{
		/** @var list<float> $imbalances */
		$imbalances = [];
		$buy = 0.0;
		$sell = 0.0;
		$filled = 0.0;
		$out = [];

		foreach ($sizes as $i => $size) {
			if ($size > 0.0) {
				$sign = $signs[$i];
				$buyFraction = $sign > 0.0 ? 1.0 : ($sign < 0.0 ? 0.0 : 0.5);

				$remaining = $size;
				while ($remaining > 0.0) {
					$room = $bucketSize - $filled;
					$take = \min($remaining, $room);
					$buy += $take * $buyFraction;
					$sell += $take * (1.0 - $buyFraction);
					$filled += $take;
					$remaining -= $take;

					if ($filled >= $bucketSize) {
						$imbalances[] = $buy - $sell;
						$buy = 0.0;
						$sell = 0.0;
						$filled = 0.0;
					}
				}
			}

			$window = \array_slice($imbalances, -$buckets);
			if (\count($window) < $buckets) {
				$out[] = \NAN;
				continue;
			}
			$sum = 0.0;
			foreach ($window as $imbalance) {
				$sum += \abs($imbalance);
			}
			$out[] = $sum / (\count($window) * $bucketSize);
		}
		return $out;
	}

	/**
	 * @return array{list<float>, list<float>}
	 */
	private static function tape(int $n, int $seed, float $maxSize): array
	{
		\mt_srand($seed);
		$sizes = [];
		$signs = [];
		for ($i = 0; $i < $n; $i++) {
			$sizes[] = (float) \mt_rand(1, (int) ($maxSize * 10)) / 10.0;
			$signs[] = match (\mt_rand(0, 2)) {
				0 => 1.0,
				1 => -1.0,
				default => 0.0,
			};
		}
		return [$sizes, $signs];
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

	/** @return iterable<string, array{float, int, float}> */
	public function configurations(): iterable
	{
		yield 'small trades, small buckets' => [100.0, 5, 10.0];
		yield 'trades comparable to a bucket' => [100.0, 5, 90.0];
		yield 'trades larger than a bucket' => [50.0, 10, 400.0];
		yield 'one bucket only' => [200.0, 1, 30.0];
		yield 'defaults' => [1000.0, 50, 50.0];
	}

	/** @dataProvider configurations */
	public function testMatchesANaiveVolumeClock(float $bucketSize, int $buckets, float $maxSize): void
	{
		for ($seed = 1; $seed <= 10; $seed++) {
			[$sizes, $signs] = self::tape(600, $seed * 31 + $buckets, $maxSize);
			self::assertSeriesMatches(
				self::naive($sizes, $signs, $bucketSize, $buckets),
				Vpin::compute($sizes, $signs, $bucketSize, $buckets),
				"seed $seed, bucket $bucketSize x $buckets",
			);
		}
	}

	/**
	 * A trade larger than a whole bucket must be spread across every bucket it
	 * spans, not dropped into one. Feeding a single 300-unit buy into 100-unit
	 * buckets has to complete three fully one-sided buckets.
	 */
	public function testALargeTradeIsSplitAcrossTheBucketsItSpans(): void
	{
		$out = Vpin::compute([300.0], [1.0], 100.0, 3);

		// three one-sided buckets, each with an imbalance of the full 100
		self::assertEqualsWithDelta(1.0, $out[0], self::TOLERANCE, 'every bucket is pure buying');

		// and the same volume arriving as three separate prints is identical
		$split = Vpin::compute([100.0, 100.0, 100.0], [1.0, 1.0, 1.0], 100.0, 3);
		self::assertEqualsWithDelta($out[0], $split[2], self::TOLERANCE);

		// a trade straddling a boundary contributes to both buckets in
		// proportion: 150 buy then 50 sell fills bucket one with 100 buy and
		// bucket two with 50 buy and 50 sell
		$straddle = Vpin::compute([150.0, 50.0], [1.0, -1.0], 100.0, 2);
		self::assertEqualsWithDelta((100.0 + 0.0) / 200.0, $straddle[1], self::TOLERANCE);
	}

	/**
	 * The reading is bounded by construction: the absolute imbalance of a
	 * bucket cannot exceed its size, so the ratio lies in [0, 1]. It reaches 1
	 * only when every bucket is one-sided and 0 only when every one is balanced.
	 */
	public function testTheReadingIsBoundedByZeroAndOne(): void
	{
		// perfectly balanced flow: alternating buys and sells of equal size
		$n = 200;
		$sizes = \array_fill(0, $n, 10.0);
		$signs = [];
		for ($i = 0; $i < $n; $i++) {
			$signs[] = $i % 2 === 0 ? 1.0 : -1.0;
		}
		$balanced = Vpin::compute($sizes, $signs, 100.0, 5);
		self::assertEqualsWithDelta(0.0, $balanced[$n - 1], self::TOLERANCE, 'balanced flow is not toxic');

		// entirely one-sided flow
		$toxic = Vpin::compute($sizes, \array_fill(0, $n, 1.0), 100.0, 5);
		self::assertEqualsWithDelta(1.0, $toxic[$n - 1], self::TOLERANCE, 'one-sided flow is maximally toxic');

		// and anything in between stays inside the interval
		for ($seed = 1; $seed <= 15; $seed++) {
			[$s, $g] = self::tape(400, $seed, 40.0);
			foreach (Vpin::compute($s, $g, 100.0, 5) as $i => $value) {
				if (\is_nan($value)) {
					continue;
				}
				self::assertGreaterThanOrEqual(0.0, $value, "seed $seed index $i");
				self::assertLessThanOrEqual(1.0 + self::TOLERANCE, $value, "seed $seed index $i");
			}
		}
	}

	/**
	 * An unclassified trade is split evenly rather than dropped: dropping it
	 * would shrink the bucket and inflate the imbalance of what remains. Half a
	 * bucket of unsigned flow therefore dilutes the reading rather than leaving
	 * it alone.
	 */
	public function testUnsignedFlowIsSplitEvenlyRatherThanDropped(): void
	{
		// one bucket: 50 units of pure buying and 50 unsigned
		$mixed = Vpin::compute([50.0, 50.0], [1.0, 0.0], 100.0, 1);
		// buy = 50 + 25 = 75, sell = 25, imbalance 50 over a bucket of 100
		self::assertEqualsWithDelta(0.5, $mixed[1], self::TOLERANCE);

		// entirely unsigned flow is perfectly balanced, not undefined
		$unsigned = Vpin::compute([100.0], [0.0], 100.0, 1);
		self::assertEqualsWithDelta(0.0, $unsigned[0], self::TOLERANCE);
	}

	/** `fromBuckets()` takes pre-aggregated buckets and normalises by their volume. */
	public function testFromBucketsNormalisesByTheVolumeItWasGiven(): void
	{
		$buys = [80.0, 50.0, 100.0];
		$sells = [20.0, 50.0, 0.0];

		$out = Vpin::fromBuckets($buys, $sells, 3);
		self::assertNan($out[0]);
		self::assertNan($out[1]);

		// |80−20| + |50−50| + |100−0| = 160, over a total volume of 300
		self::assertEqualsWithDelta(160.0 / 300.0, $out[2], self::TOLERANCE);

		// a window of empty buckets has nothing to normalise against
		self::assertNan(Vpin::fromBuckets([0.0], [0.0], 1)[0]);
	}

	/** The bucket counter and the pending fill describe the clock's state. */
	public function testTheClockReportsItsProgress(): void
	{
		$metric = new Vpin(100.0, 2);

		$metric->updateTrade(Trade::at(1, 10.0, 60.0, TradeSide::Buy));
		self::assertSame(0.0, $metric->values()['buckets']);
		self::assertEqualsWithDelta(60.0, $metric->values()['pending'], self::TOLERANCE);
		self::assertFalse($metric->isReady());

		$metric->updateTrade(Trade::at(2, 10.0, 60.0, TradeSide::Buy));
		self::assertSame(1.0, $metric->values()['buckets'], 'one bucket closed, 20 left over');
		self::assertEqualsWithDelta(20.0, $metric->values()['pending'], self::TOLERANCE);

		$metric->updateTrade(Trade::at(3, 10.0, 80.0, TradeSide::Buy));
		self::assertSame(2.0, $metric->values()['buckets']);
		self::assertTrue($metric->isReady());
		self::assertEqualsWithDelta(1.0, $metric->value(), self::TOLERANCE);
	}

	public function testWarmUpAndReset(): void
	{
		$metric = new Vpin(100.0, 3);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		for ($i = 0; $i < 10; $i++) {
			$metric->updateTrade(Trade::at($i + 1, 10.0, 50.0, TradeSide::Buy));
		}
		self::assertTrue($metric->isReady());

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertSame(0.0, $metric->values()['buckets']);
		self::assertSame(0.0, $metric->values()['pending']);
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		[$sizes, $signs] = self::tape(400, 5678, 60.0);
		$kernel = Vpin::compute($sizes, $signs, 100.0, 10);

		$metric = new Vpin(100.0, 10);
		foreach ($sizes as $i => $size) {
			$side = match (true) {
				$signs[$i] > 0.0 => TradeSide::Buy,
				$signs[$i] < 0.0 => TradeSide::Sell,
				default => TradeSide::Unknown,
			};
			$metric->updateTrade(Trade::at(($i + 1) * 1_000_000_000, 10.0, $size, $side));

			if (\is_nan($kernel[$i])) {
				self::assertNan($metric->value(), "index $i");
			} else {
				self::assertSame($kernel[$i], $metric->value(), "index $i");
			}
		}
	}

	public function testAtLeastOneBucketIsNeeded(): void
	{
		$this->expectException(InvalidArgument::class);
		new Vpin(1000.0, 0);
	}

	public function testBucketSizeMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Vpin(0.0, 50);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		Vpin::compute([1.0, 2.0], [1.0], 100.0, 1);
	}

	public function testFromBucketsNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		Vpin::fromBuckets([1.0, 2.0], [1.0], 1);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new Vpin(2500.0, 20);
		self::assertSame(['type' => 'vpin', 'bucketSize' => 2500.0, 'buckets' => 20], $metric->toArray());
		self::assertSame($metric->toArray(), Vpin::fromArray($metric->toArray())->toArray());
	}
}
