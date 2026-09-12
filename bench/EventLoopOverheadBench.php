<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Bench\Support\Timer;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use function Amp\async;

/**
 * §6.4 event-loop cost per tick: Queue push→iterate across a fiber boundary,
 * async() fiber creation, and the full FilterSession path vs a bare step(),
 * with consumer-side batching (FilterSession batchSize) and with producer-side
 * array batches (MeasurementBatcher / arrays pushed into the inbox).
 * Target (§1.3): loop overhead ≤ 2 µs per tick; §6.5 hopes for ~0.1 µs with batching.
 */
final class EventLoopOverheadBench implements Benchmark
{
	public function name(): string
	{
		return 'eventloop';
	}

	public function run(): array
	{
		$out = [];
		$out['queue_push_iterate_us'] = self::queueRoundTrip(200_000);
		$out['async_spawn_us'] = self::asyncSpawn(20_000);
		[$raw, $session] = self::sessionVsRaw(100_000, 0);
		$out['raw_step_us'] = $raw;
		$out['session_step_us'] = $session;
		$out['loop_overhead_per_tick_us'] = \max(0.0, $session - $raw);
		[, $batched] = self::sessionVsRaw(100_000, 256);
		$out['session_batched_step_us'] = $batched;
		$out['loop_overhead_batched_per_tick_us'] = \max(0.0, $batched - $raw);
		[, $arrays] = self::sessionVsRaw(100_000, 0, 256);
		$out['session_array_batches_step_us'] = $arrays;
		$out['loop_overhead_array_batches_per_tick_us'] = \max(0.0, $arrays - $raw);
		return $out;
	}

	private static function queueRoundTrip(int $n): float
	{
		/** @var float $result */
		$result = async(static function () use ($n): float {
			/** @var Queue<Measurement> $queue */
			$queue = new Queue(1024);
			$m = Measurement::at(1, [0 => 1.0]);
			$t0 = \hrtime(true);
			$producer = async(static function () use ($queue, $n, $m): void {
				for ($i = 0; $i < $n; $i++) {
					$queue->push($m);
				}
				$queue->complete();
			});
			$consumed = 0;
			foreach ($queue->iterate() as $_) {
				$consumed++;
			}
			$producer->await();
			if ($consumed !== $n) {
				throw new \RuntimeException("queue delivered $consumed of $n items");
			}
			return (\hrtime(true) - $t0) / 1e3 / $n;
		})->await();
		return $result;
	}

	private static function asyncSpawn(int $n): float
	{
		/** @var float $result */
		$result = async(static function () use ($n): float {
			$t0 = \hrtime(true);
			$futures = [];
			for ($i = 0; $i < $n; $i++) {
				$futures[] = async(static fn (): int => 1);
			}
			\Amp\Future\await($futures);
			return (\hrtime(true) - $t0) / 1e3 / $n;
		})->await();
		return $result;
	}

	/**
	 * @param int $batchSize FilterSession consumer-side batching (0 = off)
	 * @param int $arrayBatch producer pushes arrays of this many measurements (0 = one measurement per push)
	 * @return array{0: float, 1: float}
	 */
	private static function sessionVsRaw(int $n, int $batchSize, int $arrayBatch = 0): array
	{
		$llt = new LocalLinearTrend(0.5, 0.05, 0.01);

		$raw = $llt->filter(100.0);
		$ticks = [];
		for ($k = 1; $k <= $n; $k++) {
			$ticks[] = Measurement::at($k * 100_000_000, [0 => 100.0 + 0.001 * ($k % 100)]);
		}
		$t0 = \hrtime(true);
		foreach ($ticks as $m) {
			$raw->step($m);
		}
		$rawUs = (\hrtime(true) - $t0) / 1e3 / $n;

		/** @var float $sessionUs */
		$sessionUs = async(static function () use ($llt, $ticks, $n, $batchSize, $arrayBatch): float {
			/** @var Queue<Measurement|Command|array<int, Measurement>> $inbox */
			$inbox = new Queue(1024);
			/** @var Queue<StateSnapshot> $snapshots */
			$snapshots = new Queue(16);
			$session = new FilterSession($llt->filter(100.0), $inbox, $snapshots, snapshotEvery: 1_000_000, batchSize: $batchSize);
			$run = $session->start();
			$drain = async(static function () use ($snapshots): void {
				foreach ($snapshots->iterate() as $_) {
				}
			});
			$t0 = \hrtime(true);
			if ($arrayBatch > 0) {
				foreach (\array_chunk($ticks, $arrayBatch) as $chunk) {
					$inbox->push($chunk);
				}
			} else {
				foreach ($ticks as $m) {
					$inbox->push($m);
				}
			}
			$inbox->complete();
			$run->await();
			$drain->await();
			return (\hrtime(true) - $t0) / 1e3 / $n;
		})->await();

		return [$rawUs, $sessionUs];
	}
}
