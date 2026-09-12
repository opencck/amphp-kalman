<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Metric;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Support\BarAggregator;
use OpenCCK\Kalman\Domain\Metric\Support\BarClock;
use PHPUnit\Framework\TestCase;

/**
 * Bar aggregation on the three clocks.
 *
 * The two properties that matter: time bars are cut on absolute epoch
 * boundaries — never on the first tick the process happened to see — and the
 * still-open bar is reachable only through `flush()`, so a partial bar cannot
 * reach an indicator by accident.
 */
final class BarAggregatorTest extends TestCase
{
	private const SECOND = 1_000_000_000;

	public function testThresholdMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new BarAggregator(BarClock::Time, 0.0);
	}

	public function testPriceMustBeFiniteAndPositive(): void
	{
		$aggregator = BarAggregator::timeBars(self::SECOND);
		$this->expectException(InvalidArgument::class);
		$aggregator->add(0, 0.0, 1.0);
	}

	public function testNamedConstructorsPickTheClock(): void
	{
		self::assertSame(BarClock::Time, BarAggregator::timeBars(self::SECOND)->clock);
		self::assertSame((float) self::SECOND, BarAggregator::timeBars(self::SECOND)->threshold);
		self::assertSame(BarClock::Volume, BarAggregator::volumeBars(10.0)->clock);
		self::assertSame(BarClock::Ticks, BarAggregator::tickBars(3)->clock);
		self::assertSame(3.0, BarAggregator::tickBars(3)->threshold);
	}

	/** A bar is emitted by the observation that opens the next one, never before. */
	public function testTimeBarClosesOnTheFirstTickOfTheNextInterval(): void
	{
		$aggregator = BarAggregator::timeBars(self::SECOND);

		self::assertNull($aggregator->add(1_500_000_000, 100.0, 1.0));
		self::assertNull($aggregator->add(1_700_000_000, 102.0, 2.0));
		self::assertNull($aggregator->add(1_900_000_000, 99.0, 3.0));
		self::assertTrue($aggregator->isActive());

		$bar = $aggregator->add(2_050_000_000, 101.0, 1.0);
		self::assertInstanceOf(Bar::class, $bar);
		self::assertSame(1_500_000_000, $bar->openNs);
		self::assertSame(1_900_000_000, $bar->closeNs);
		self::assertSame(100.0, $bar->open);
		self::assertSame(102.0, $bar->high);
		self::assertSame(99.0, $bar->low);
		self::assertSame(99.0, $bar->close);
		self::assertSame(6.0, $bar->volume);
		self::assertSame(3, $bar->trades);
	}

	/**
	 * Absolute epoch boundaries: two aggregators fed the same stream from
	 * different starting points still cut it in the same places, and every
	 * timestamp in a bar lies inside one interval of the epoch grid.
	 */
	public function testTimeBarsAlignToAbsoluteEpochBoundaries(): void
	{
		$interval = self::SECOND;
		$ticks = [
			1_200_000_000,
			1_500_000_000,
			1_900_000_000,
			2_100_000_000,
			2_800_000_000,
			3_000_000_000,
			3_400_000_000,
			4_600_000_000,
		];

		$early = BarAggregator::timeBars($interval);
		$late = BarAggregator::timeBars($interval);

		$earlyBars = [];
		$lateBars = [];
		foreach ($ticks as $i => $ts) {
			$bar = $early->add($ts, 100.0 + (float) $i, 1.0);
			if ($bar !== null) {
				$earlyBars[] = $bar;
			}
			// the late aggregator misses the first two ticks entirely
			if ($i >= 2) {
				$bar = $late->add($ts, 100.0 + (float) $i, 1.0);
				if ($bar !== null) {
					$lateBars[] = $bar;
				}
			}
		}
		$earlyTail = $early->flush();
		$lateTail = $late->flush();
		self::assertInstanceOf(Bar::class, $earlyTail);
		self::assertInstanceOf(Bar::class, $lateTail);
		$earlyBars[] = $earlyTail;
		$lateBars[] = $lateTail;

		// the buckets both produced, keyed by the grid cell they belong to
		$earlyBuckets = [];
		foreach ($earlyBars as $bar) {
			self::assertSame(
				\intdiv($bar->openNs, $interval),
				\intdiv($bar->closeNs, $interval),
				'a bar must not straddle an epoch boundary',
			);
			$earlyBuckets[\intdiv($bar->openNs, $interval)] = $bar->closeNs;
		}
		$lateBuckets = [];
		foreach ($lateBars as $bar) {
			$lateBuckets[\intdiv($bar->openNs, $interval)] = $bar->closeNs;
		}

		// grid cells 2, 3 and 4 are covered by both, and they close identically
		self::assertSame([1, 2, 3, 4], \array_keys($earlyBuckets));
		self::assertSame([1, 2, 3, 4], \array_keys($lateBuckets));
		foreach ([2, 3, 4] as $cell) {
			self::assertSame($earlyBuckets[$cell], $lateBuckets[$cell], "cell $cell");
		}
		// an interval with no ticks produces no bar at all: cell 0 is absent
		self::assertArrayNotHasKey(0, $earlyBuckets);
	}

	public function testVolumeBarClosesWhenTheThresholdIsReached(): void
	{
		$aggregator = BarAggregator::volumeBars(10.0);

		self::assertNull($aggregator->add(1000, 100.0, 4.0));
		self::assertNull($aggregator->add(2000, 103.0, 4.0));
		$bar = $aggregator->add(3000, 101.0, 4.0);

		self::assertInstanceOf(Bar::class, $bar);
		self::assertSame(1000, $bar->openNs);
		self::assertSame(3000, $bar->closeNs);
		self::assertSame(100.0, $bar->open);
		self::assertSame(103.0, $bar->high);
		self::assertSame(100.0, $bar->low);
		self::assertSame(101.0, $bar->close);
		self::assertSame(12.0, $bar->volume);
		self::assertSame(3, $bar->trades);
		self::assertFalse($aggregator->isActive());

		// the next observation starts a fresh bar rather than extending the old one
		self::assertNull($aggregator->add(4000, 105.0, 1.0));
		$next = $aggregator->flush();
		self::assertInstanceOf(Bar::class, $next);
		self::assertSame(105.0, $next->open);
		self::assertSame(1.0, $next->volume);
	}

	public function testVolumeBarClosesExactlyAtTheThreshold(): void
	{
		$aggregator = BarAggregator::volumeBars(10.0);
		self::assertNull($aggregator->add(1000, 100.0, 7.5));
		$bar = $aggregator->add(2000, 100.0, 2.5);
		self::assertInstanceOf(Bar::class, $bar);
		self::assertSame(10.0, $bar->volume);
	}

	public function testTickBarsCountObservations(): void
	{
		$aggregator = BarAggregator::tickBars(3);
		self::assertNull($aggregator->add(1, 100.0));
		self::assertNull($aggregator->add(2, 101.0));
		$bar = $aggregator->add(3, 99.0);

		self::assertInstanceOf(Bar::class, $bar);
		self::assertSame(3, $bar->trades);
		self::assertSame(100.0, $bar->open);
		self::assertSame(101.0, $bar->high);
		self::assertSame(99.0, $bar->low);
		self::assertSame(99.0, $bar->close);
		self::assertSame(0.0, $bar->volume);
	}

	public function testFlushReturnsThePartialBarAndOnlyOnce(): void
	{
		$aggregator = BarAggregator::timeBars(self::SECOND);
		self::assertNull($aggregator->flush(), 'nothing in progress, nothing to flush');

		$aggregator->add(1_100_000_000, 100.0, 2.0);
		$aggregator->add(1_200_000_000, 104.0, 3.0);

		$bar = $aggregator->flush();
		self::assertInstanceOf(Bar::class, $bar);
		self::assertSame(100.0, $bar->open);
		self::assertSame(104.0, $bar->high);
		self::assertSame(100.0, $bar->low);
		self::assertSame(104.0, $bar->close);
		self::assertSame(5.0, $bar->volume);
		self::assertSame(2, $bar->trades);

		self::assertFalse($aggregator->isActive());
		self::assertNull($aggregator->flush());
	}

	public function testAddTradeUsesThePriceSizeAndTimestampOfTheTrade(): void
	{
		$aggregator = BarAggregator::volumeBars(5.0);
		self::assertNull($aggregator->addTrade(Trade::at(10, 200.0, 2.0, TradeSide::Buy)));
		$bar = $aggregator->addTrade(Trade::at(20, 198.0, 4.0, TradeSide::Sell));

		self::assertInstanceOf(Bar::class, $bar);
		self::assertSame(10, $bar->openNs);
		self::assertSame(20, $bar->closeNs);
		self::assertSame(200.0, $bar->open);
		self::assertSame(200.0, $bar->high);
		self::assertSame(198.0, $bar->low);
		self::assertSame(198.0, $bar->close);
		self::assertSame(6.0, $bar->volume);
	}

	/** OHLC over a long random stream, against a naive per-bucket rescan. */
	public function testOhlcMatchesANaiveRescanOfEachTimeBucket(): void
	{
		\mt_srand(606060);
		$interval = self::SECOND;
		$aggregator = BarAggregator::timeBars($interval);

		/** @var array<int, list<array{int, float, float}>> $buckets */
		$buckets = [];
		$bars = [];
		$ts = 7_000_000_000;
		for ($i = 0; $i < 2000; $i++) {
			$ts += \mt_rand(1_000_000, 400_000_000);
			$price = 100.0 + (float) \mt_rand(-2000, 2000) / 100.0;
			$size = (float) \mt_rand(1, 100) / 10.0;
			$buckets[\intdiv($ts, $interval)][] = [$ts, $price, $size];

			$bar = $aggregator->add($ts, $price, $size);
			if ($bar !== null) {
				$bars[] = $bar;
			}
		}
		$last = $aggregator->flush();
		self::assertInstanceOf(Bar::class, $last);
		$bars[] = $last;

		self::assertSame(\count($buckets), \count($bars));
		$pending = $bars;
		$cell = 0;
		foreach ($buckets as $rows) {
			$bar = \array_shift($pending);
			self::assertInstanceOf(Bar::class, $bar, "bucket $cell has no bar");

			$openNs = 0;
			$closeNs = 0;
			$open = 0.0;
			$close = 0.0;
			$high = -\INF;
			$low = \INF;
			$volume = 0.0;
			$trades = 0;
			foreach ($rows as $row) {
				[$rowNs, $rowPrice, $rowSize] = $row;
				if ($trades === 0) {
					$openNs = $rowNs;
					$open = $rowPrice;
				}
				$closeNs = $rowNs;
				$close = $rowPrice;
				if ($rowPrice > $high) {
					$high = $rowPrice;
				}
				if ($rowPrice < $low) {
					$low = $rowPrice;
				}
				$volume += $rowSize;
				$trades++;
			}

			self::assertSame($openNs, $bar->openNs, "bucket $cell openNs");
			self::assertSame($closeNs, $bar->closeNs, "bucket $cell closeNs");
			self::assertSame($open, $bar->open, "bucket $cell open");
			self::assertSame($high, $bar->high, "bucket $cell high");
			self::assertSame($low, $bar->low, "bucket $cell low");
			self::assertSame($close, $bar->close, "bucket $cell close");
			self::assertEqualsWithDelta($volume, $bar->volume, 1e-9, "bucket $cell volume");
			self::assertSame($trades, $bar->trades, "bucket $cell trades");
			$cell++;
		}
		self::assertSame([], $pending, 'every bar must belong to a bucket');
	}
}
