<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;
use OpenCCK\Kalman\Infrastructure\Async\MeasurementBatcher;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use OpenCCK\Kalman\Infrastructure\Command\SnapshotRequest;
use function Amp\async;

final class MeasurementBatcherTest extends AsyncTestCase
{
	/** §6.5: arrays of measurements through the inbox give the same state, with far fewer queue items. */
	public function testArrayBatchesAreProcessedIdenticallyAndAmortiseTheQueue(): void
	{
		$llt = new LocalLinearTrend(0.5, 0.05, 0.01);
		$ticks = [];
		for ($k = 1; $k <= 2000; $k++) {
			$ticks[] = Measurement::at($k * 10_000_000, [0 => 100.0 + 0.01 * ($k % 41)]);
		}
		$reference = $llt->filter(100.0);
		foreach ($ticks as $i => $m) {
			$reference->step($m);
			if ($i === 999) {
				$midExpected = $reference->mean();
			}
		}
		self::assertTrue(isset($midExpected));

		/** @var Queue<Measurement|Command> $source */
		$source = new Queue(1024);
		/** @var Queue<Measurement|Command|array<int, Measurement>> $inbox */
		$inbox = new Queue(64);
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue(8);
		$session = new FilterSession($llt->filter(100.0), $inbox, $snapshots, snapshotEvery: 500);
		$run = $session->start();
		$drain = async(static function () use ($snapshots): void {
			foreach ($snapshots->iterate() as $_) {
			}
		});

		$batcher = new MeasurementBatcher(256);
		$pump = async(static function () use ($batcher, $source, $inbox): void {
			$batcher->pump($source->iterate(), $inbox);
			$inbox->complete();
		});
		$mid = null;
		foreach ($ticks as $i => $m) {
			$source->push($m);
			if ($i === 999) {
				// a command in the middle of the SOURCE stream: the batcher must forward it at its position
				$request = new SnapshotRequest();
				$source->push($request);
				$mid = $request->future();
			}
		}
		$source->complete();
		$pump->await();
		$final = $run->await();
		$drain->await();

		self::assertNotNull($mid);
		self::assertSame($midExpected, $mid->await()->mean);
		self::assertSame($reference->mean(), $final->mean);
		self::assertSame($reference->covariance(), $final->covariance);
		self::assertSame(2000, $session->processed());
		self::assertSame(2001, $batcher->items());   // 2000 measurements + the snapshot request
		self::assertLessThan(2000, $batcher->batches(), 'batches must be formed under load');
	}

	public function testLowLoadKeepsSingleMeasurements(): void
	{
		/** @var Queue<Measurement|Command> $source */
		$source = new Queue();
		/** @var Queue<Measurement|Command|array<int, Measurement>> $inbox */
		$inbox = new Queue(64);
		$batcher = new MeasurementBatcher(16);
		$pump = async(static function () use ($batcher, $source, $inbox): void {
			$batcher->pump($source->iterate(), $inbox);
			$inbox->complete();
		});
		$received = [];
		$consumer = async(static function () use ($inbox, &$received): void {
			foreach ($inbox->iterate() as $item) {
				$received[] = $item;
			}
		});
		// push one at a time and let the pump catch up between pushes (Queue() without buffer suspends until consumed)
		for ($k = 1; $k <= 5; $k++) {
			$source->push(Measurement::at($k, [0 => 1.0]));
		}
		$source->complete();
		$pump->await();
		$consumer->await();
		self::assertSame(5, $batcher->items());
		$count = 0;
		foreach ($received as $item) {
			$count += \is_array($item) ? \count($item) : 1;
		}
		self::assertSame(5, $count);
	}
}
