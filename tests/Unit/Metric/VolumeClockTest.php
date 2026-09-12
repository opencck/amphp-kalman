<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Support\VolumeClock;
use PHPUnit\Framework\TestCase;

/**
 * Volume time. The property that makes the clock usable is exactness: a trade
 * that overflows the current bucket is split across boundaries, so the bucket
 * totals add up to the traded volume no matter how the tape is chunked.
 */
final class VolumeClockTest extends TestCase
{
	public function testBucketSizeMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new VolumeClock(0.0, 10);
	}

	public function testZeroAndNegativeTradesAreIgnored(): void
	{
		$clock = new VolumeClock(10.0, 8);
		self::assertSame(0, $clock->add(0.0, 0.0));
		self::assertSame(0, $clock->add(-5.0, 0.0));
		self::assertSame(0.0, $clock->pending());
		self::assertSame(0, $clock->completedBuckets());
	}

	/** A trade that fits inside the bucket completes nothing. */
	public function testSmallTradeOnlyFillsTheBucket(): void
	{
		$clock = new VolumeClock(10.0, 8);
		self::assertSame(0, $clock->add(4.0, 4.0));
		self::assertSame(4.0, $clock->pending());
		self::assertSame(0, $clock->completedBuckets());
		self::assertSame(0, $clock->imbalances()->count());
	}

	/** A trade that exactly fills the bucket completes it and leaves nothing. */
	public function testExactFillCompletesOneBucket(): void
	{
		$clock = new VolumeClock(10.0, 8);
		self::assertSame(1, $clock->add(10.0, 10.0));
		self::assertSame(0.0, $clock->pending());
		self::assertSame(1, $clock->completedBuckets());
		self::assertSame([10.0], $clock->imbalances()->toList());
	}

	/**
	 * A trade larger than a bucket is split across buckets exactly: 25 units
	 * into buckets of 10 completes two of them and leaves 5 pending.
	 */
	public function testOversizedTradeIsSplitAcrossBuckets(): void
	{
		$clock = new VolumeClock(10.0, 8);

		self::assertSame(2, $clock->add(25.0, 25.0));
		self::assertSame(2, $clock->completedBuckets());
		self::assertSame(5.0, $clock->pending());
		self::assertSame([10.0, 10.0], $clock->imbalances()->toList());

		// a sell of 25 finishes the half-filled bucket (5 bought, 5 sold ⇒ 0)
		// and then completes two whole sell buckets
		self::assertSame(3, $clock->add(25.0, 0.0));
		self::assertSame(5, $clock->completedBuckets());
		self::assertSame(0.0, $clock->pending());
		self::assertSame([10.0, 10.0, 0.0, -10.0, -10.0], $clock->imbalances()->toList());
	}

	/** A single trade spanning many buckets completes all of them. */
	public function testATradeManyBucketsWideCompletesThemAll(): void
	{
		$clock = new VolumeClock(2.0, 64);
		self::assertSame(50, $clock->add(100.0, 100.0));
		self::assertSame(0.0, $clock->pending());
		self::assertSame(50, $clock->completedBuckets());
	}

	/**
	 * The buy fraction of a trade is carried into every piece it is split into,
	 * so the imbalance of a bucket is the volume-weighted signed flow through
	 * it, not the flow of whichever trade happened to close it.
	 */
	public function testBuyFractionIsPreservedAcrossTheSplit(): void
	{
		$clock = new VolumeClock(10.0, 8);
		// 30 units, 60 % buyer-initiated ⇒ every bucket is 6 bought, 4 sold
		self::assertSame(3, $clock->add(30.0, 18.0));
		foreach ($clock->imbalances()->toList() as $imbalance) {
			self::assertEqualsWithDelta(2.0, $imbalance, 1e-12);
		}
	}

	/**
	 * The point of the split: bucket totals sum to the input volume. With
	 * buyer-initiated trades only, every completed bucket's imbalance is its
	 * whole volume, so the completed buckets plus the pending remainder must
	 * reproduce the traded volume exactly.
	 */
	public function testBucketTotalsSumToTheInputVolume(): void
	{
		\mt_srand(20240918);
		$clock = new VolumeClock(37.5, 4096);
		$total = 0.0;
		$completed = 0;

		for ($i = 0; $i < 500; $i++) {
			// sizes from a sliver of a bucket to several buckets wide
			$volume = (float) \mt_rand(1, 20000) / 100.0;
			$total += $volume;
			$completed += $clock->add($volume, $volume);
		}

		self::assertSame($completed, $clock->completedBuckets());
		self::assertLessThan(4096, $completed, 'the imbalance ring must not have wrapped for this assertion to mean anything');

		$sum = 0.0;
		foreach ($clock->imbalances()->toList() as $imbalance) {
			$sum += $imbalance;
		}
		$sum += $clock->pending();

		self::assertEqualsWithDelta($total, $sum, 1e-9);
		// and the completed volume is what the bucket count says it is
		self::assertEqualsWithDelta((float) $completed * 37.5, $total - $clock->pending(), 1e-9);
	}

	public function testResetClearsBucketsAndPendingVolume(): void
	{
		$clock = new VolumeClock(10.0, 8);
		$clock->add(25.0, 25.0);
		$clock->reset();

		self::assertSame(0, $clock->completedBuckets());
		self::assertSame(0.0, $clock->pending());
		self::assertSame([], $clock->imbalances()->toList());

		self::assertSame(1, $clock->add(10.0, 0.0));
		self::assertSame([-10.0], $clock->imbalances()->toList());
	}
}
