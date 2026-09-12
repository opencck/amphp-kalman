<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Diagnostics\ConsistencyMonitor;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\OutOfSequencePolicy;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Exception\OutOfSequenceMeasurement;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;
use OpenCCK\Kalman\Infrastructure\Async\ReorderBuffer;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use OpenCCK\Kalman\Infrastructure\Command\Dividend;
use OpenCCK\Kalman\Infrastructure\Command\Rebalance;
use OpenCCK\Kalman\Infrastructure\Command\Split;
use OpenCCK\Kalman\Tests\Support\Rng;
use function Amp\async;

final class FilterSessionTest extends AsyncTestCase
{
	private function filter(): KalmanFilter
	{
		return (new LocalLinearTrend(0.5, 0.05, 0.01))->filter(100.0);
	}

	/** §5.13: shuffled ticks through ReorderBuffer → monotone dt in the session. */
	public function testProcessesInTimestampOrder(): void
	{
		$rng = new Rng(301);
		$ordered = [];
		for ($k = 1; $k <= 200; $k++) {
			$ordered[] = Measurement::at($k * 10_000_000, [0 => 100.0 + 0.01 * $k]);
		}
		// network jitter: each tick arrives 0–40 ms after its exchange time → arrival order ≠ exchange order,
		// but every displacement is below the 100 ms reorder window
		$arrival = [];
		foreach ($ordered as $i => $m) {
			$arrival[$i] = $m->timestampNs + $rng->int(0, 40_000_000);
		}
		$indexes = \range(0, 199);
		\usort($indexes, static fn (int $a, int $b): int => $arrival[$a] <=> $arrival[$b]);
		$shuffled = [];
		$outOfOrder = 0;
		foreach ($indexes as $pos => $i) {
			$shuffled[] = $ordered[$i];
			if ($i !== $pos) {
				$outOfOrder++;
			}
		}
		self::assertGreaterThan(20, $outOfOrder, 'the arrival order must actually be shuffled');

		/** @var Queue<Measurement> $source */
		$source = new Queue();
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue();
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue();
		$monitor = new ConsistencyMonitor(1);
		$session = new FilterSession($this->filter(), $inbox, $snapshots, $monitor, snapshotEvery: 50, oosPolicy: OutOfSequencePolicy::Fail);

		async(static function () use ($source, $shuffled): void {
			foreach ($shuffled as $m) {
				$source->push($m);
			}
			$source->complete();
		});
		async(static function () use ($source, $inbox): void {
			foreach ((new ReorderBuffer(windowNs: 100_000_000))->apply($source->iterate()) as $m) {
				if ($m instanceof Measurement) {
					$inbox->push($m);
				}
			}
			$inbox->complete();
		});
		/** @var array<int, StateSnapshot> $received */
		$received = [];
		$drain = async(static function () use ($snapshots, &$received): void {
			foreach ($snapshots->iterate() as $s) {
				$received[] = $s;
			}
		});

		$final = $session->start()->await();
		$drain->await();

		self::assertSame(200, $session->processed());
		self::assertSame(0, $session->dropped());
		self::assertSame(200 * 10_000_000, $final->timestampNs);
		self::assertCount(4, $received);
		self::assertSame(50, $received[0]->steps);
		self::assertSame(200, $received[3]->steps);
		self::assertSame(200, $monitor->stepsRecorded());

		// same result as a plain in-order run
		$reference = $this->filter();
		foreach ($ordered as $m) {
			$reference->step($m);
		}
		self::assertSame($reference->mean(), $final->mean);
		self::assertSame($reference->covariance(), $final->covariance);
	}

	/** §5.13: a snapshot requested between two ticks reflects exactly the first. */
	public function testSnapshotReflectsExactTick(): void
	{
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue();
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue();
		$session = new FilterSession($this->filter(), $inbox, $snapshots, snapshotEvery: 1000);
		$run = $session->start();
		async(static function () use ($snapshots): void {
			foreach ($snapshots->iterate() as $_) {
			}
		});

		$reference = $this->filter();
		$m1 = Measurement::at(1_000_000_000, [0 => 100.5]);
		$m2 = Measurement::at(2_000_000_000, [0 => 101.5]);
		$reference->step($m1);

		$inbox->push($m1);
		$future = $session->requestSnapshot();    // queued after m1, before m2
		$inbox->push($m2);
		$inbox->complete();

		$snapshot = $future->await();
		self::assertSame($reference->mean(), $snapshot->mean);
		self::assertSame($reference->covariance(), $snapshot->covariance);
		self::assertSame(1_000_000_000, $snapshot->timestampNs);

		$final = $run->await();
		$reference->step($m2);
		self::assertSame($reference->mean(), $final->mean);
	}

	public function testDropPolicyCountsLateMeasurements(): void
	{
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue();
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue();
		$monitor = new ConsistencyMonitor(1);
		$session = new FilterSession($this->filter(), $inbox, $snapshots, $monitor, oosPolicy: OutOfSequencePolicy::Drop);
		$run = $session->start();
		async(static function () use ($snapshots): void {
			foreach ($snapshots->iterate() as $_) {
			}
		});
		$inbox->push(Measurement::at(2_000_000_000, [0 => 100.0]));
		$inbox->push(Measurement::at(1_000_000_000, [0 => 100.0]));   // late
		$inbox->push(Measurement::at(3_000_000_000, [0 => 100.0]));
		$inbox->complete();
		$final = $run->await();
		self::assertSame(2, $session->processed());
		self::assertSame(1, $session->dropped());
		self::assertSame(1, $monitor->dropped());
		self::assertSame(3_000_000_000, $final->timestampNs);
	}

	public function testFailPolicyThrows(): void
	{
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue();
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue();
		$session = new FilterSession($this->filter(), $inbox, $snapshots, oosPolicy: OutOfSequencePolicy::Fail);
		$run = $session->start();
		$inbox->push(Measurement::at(2_000_000_000, [0 => 100.0]));
		$inbox->push(Measurement::at(1_000_000_000, [0 => 100.0]));
		$inbox->complete();
		$this->expectException(OutOfSequenceMeasurement::class);
		$run->await();
	}

	public function testRetrodictPolicyFoldsInLateMeasurementWithinOneStep(): void
	{
		$make = static fn (): KalmanFilter => new KalmanFilter(
			new ConstantVelocity(0.3),
			new StaticObservation([[0 => 1.0]], [0.04]),
			[100.0, 0.0],
			[1.0, 0.0, 0.0, 0.25],
		);
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue();
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue();
		$session = new FilterSession($make(), $inbox, $snapshots, oosPolicy: OutOfSequencePolicy::Retrodict);
		$run = $session->start();
		async(static function () use ($snapshots): void {
			foreach ($snapshots->iterate() as $_) {
			}
		});
		$inbox->push(Measurement::at(1_000_000_000, [0 => 100.2]));
		$inbox->push(Measurement::at(2_000_000_000, [0 => 100.6]));
		$inbox->push(Measurement::at(1_500_000_000, [0 => 100.3]));   // half a step late
		$inbox->complete();
		$final = $run->await();
		self::assertSame(1, $session->retrodicted());
		self::assertSame(0, $session->dropped());

		// compare with the in-order run: retrodiction is an approximation but must be close
		$reference = $make();
		$reference->step(Measurement::at(1_000_000_000, [0 => 100.2]));
		$reference->step(Measurement::at(1_500_000_000, [0 => 100.3]));
		$reference->step(Measurement::at(2_000_000_000, [0 => 100.6]));
		self::assertEqualsWithDelta($reference->meanAt(0), $final->mean[0], 0.02);
		self::assertLessThan($make()->variance(0), $final->variance(0));
	}

	public function testCorporateActionsAreAppliedBetweenTicks(): void
	{
		$llt = new LocalLinearTrend(0.5, 0.05, 0.01);
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue();
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue();
		$session = new FilterSession($llt->filter(100.0), $inbox, $snapshots);
		$run = $session->start();
		async(static function () use ($snapshots): void {
			foreach ($snapshots->iterate() as $_) {
			}
		});
		$inbox->push(Measurement::at(1_000_000_000, [0 => 100.0]));
		$inbox->push(new Split([0 => 0.5, 1 => 0.5]));               // 2:1 split
		$afterSplit = $session->requestSnapshot();
		$inbox->push(new Dividend([0 => 1.0]));
		$afterDividend = $session->requestSnapshot();
		$inbox->push(new Rebalance(new StaticObservation([[0 => 1.0]], [0.01]), [1 => 0.5]));
		$inbox->push(Measurement::at(2_000_000_000, [0 => 49.0]));
		$inbox->complete();

		$s1 = $afterSplit->await();
		$s2 = $afterDividend->await();
		self::assertEqualsWithDelta(50.0, $s1->mean[0], 1e-9);
		self::assertEqualsWithDelta(49.0, $s2->mean[0], 1e-9);
		$final = $run->await();
		self::assertSame(2, $session->processed());
		self::assertSame(2_000_000_000, $final->timestampNs);
	}
	/** §6.5: batched owner loop is bit-identical to the plain loop and keeps command order (snapshot linearisation). */
	public function testBatchedSessionMatchesPlainSession(): void
	{
		$ticks = [];
		for ($k = 1; $k <= 500; $k++) {
			$ticks[] = Measurement::at($k * 10_000_000, [0 => 100.0 + 0.01 * ($k % 37)]);
		}
		$reference = $this->filter();
		foreach ($ticks as $i => $m) {
			$reference->step($m);
			if ($i === 249) {
				$expectedMid = $reference->mean();
			}
		}
		self::assertTrue(isset($expectedMid));

		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue(64);
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue(8);
		$session = new FilterSession($this->filter(), $inbox, $snapshots, snapshotEvery: 100, batchSize: 32);
		$run = $session->start();
		/** @var array<int, StateSnapshot> $received */
		$received = [];
		$drain = async(static function () use ($snapshots, &$received): void {
			foreach ($snapshots->iterate() as $s) {
				$received[] = $s;
			}
		});

		$mid = null;
		foreach ($ticks as $i => $m) {
			$inbox->push($m);
			if ($i === 249) {
				$mid = $session->requestSnapshot();
			}
		}
		$inbox->complete();
		$final = $run->await();
		$drain->await();

		self::assertNotNull($mid);
		self::assertSame($expectedMid, $mid->await()->mean);
		self::assertSame($reference->mean(), $final->mean);
		self::assertSame($reference->covariance(), $final->covariance);
		self::assertSame(500, $session->processed());
		self::assertCount(5, $received);
		self::assertLessThan(500, $session->batches(), 'batching must amortise wake-ups under load');
		self::assertGreaterThan(0, $session->batches());
	}

	public function testBatchedSessionPropagatesFailures(): void
	{
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue(64);
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue(8);
		$session = new FilterSession($this->filter(), $inbox, $snapshots, oosPolicy: OutOfSequencePolicy::Fail, batchSize: 16);
		$run = $session->start();
		$inbox->push(Measurement::at(2_000_000_000, [0 => 100.0]));
		$inbox->push(Measurement::at(1_000_000_000, [0 => 100.0]));
		$inbox->complete();
		$this->expectException(OutOfSequenceMeasurement::class);
		$run->await();
	}
}
