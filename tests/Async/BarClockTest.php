<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Infrastructure\Async\BarClock;
use OpenCCK\Kalman\Infrastructure\Async\Clock\ManualClock;
use OpenCCK\Kalman\Infrastructure\Async\Clock\SystemClock;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use function Amp\async;
use function Amp\delay;

final class BarClockTest extends AsyncTestCase
{
	/** §5.13: blind steps from the clock grow the uncertainty while no data arrives. */
	public function testBlindStepsGrowUncertainty(): void
	{
		$filter = (new LocalLinearTrend(0.5, 0.05, 0.01))->filter(100.0);
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue();
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue();
		$session = new FilterSession($filter, $inbox, $snapshots, snapshotEvery: 1);
		$run = $session->start();
		// the snapshot consumer must exist before the first tick: snapshotEvery=1 pushes on every step
		$variances = [];
		$collector = async(static function () use ($snapshots, &$variances): void {
			foreach ($snapshots->iterate() as $s) {
				$variances[] = $s->variance(0);
			}
		});

		$clock = new ManualClock(1_000_000_000_000);
		$inbox->push(Measurement::at($clock->realtimeNs(), [0 => 100.0]));   // one real tick anchors the clock
		$afterTick = $session->requestSnapshot()->await();

		$bar = new BarClock($inbox, $clock, periodSeconds: 0.02);
		for ($i = 0; $i < 5; $i++) {
			$clock->advance(1_000_000_000);   // each bar is 1 s of exchange time
			delay(0.025);
		}
		$bar->stop();
		self::assertFalse($bar->isRunning());
		$ticks = $bar->ticks();
		self::assertGreaterThanOrEqual(3, $ticks);
		delay(0.05);
		$inbox->complete();
		$final = $run->await();
		$collector->await();

		self::assertGreaterThan($afterTick->variance(0), $final->variance(0));
		// monotone growth across blind steps
		for ($i = 2; $i < \count($variances); $i++) {
			self::assertGreaterThanOrEqual($variances[$i - 1], $variances[$i]);
		}
		self::assertSame($ticks + 1, $session->processed());
	}

	public function testSystemClockIsEpochNanoseconds(): void
	{
		$ns = (new SystemClock())->realtimeNs();
		$nowSec = \time();
		self::assertGreaterThan(($nowSec - 5) * 1_000_000_000, $ns);
		self::assertLessThan(($nowSec + 5) * 1_000_000_000, $ns);
	}

	public function testStoppedClockStopsTicking(): void
	{
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue(100);
		$bar = new BarClock($inbox, new ManualClock(), 0.01);
		delay(0.035);
		$bar->stop();
		$count = $bar->ticks();
		delay(0.03);
		self::assertSame($count, $bar->ticks());
		$inbox->complete();
	}
}
