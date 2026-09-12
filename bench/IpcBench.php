<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Infrastructure\Task\WorkerPools;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Infrastructure\Task\WorkerSession;
use function Amp\async;

/**
 * §6.6 IpcBench: cost of shipping ticks to a worker as a function of batch
 * size — total µs per tick through Batcher → Channel → FilterWorkerTask (LLT).
 * Picks the batch size for §5.9 (expected: ≥ 64 amortises the ~20–50 µs send).
 */
final class IpcBench implements Benchmark
{
	private const TICKS = 20_000;

	public function name(): string
	{
		return 'ipc';
	}

	public function run(): array
	{
		/** @var array<string, float> $result */
		$result = async(function (): array {
			$pool = WorkerPools::likeParent(1);
			$config = [
				'motion' => ['type' => 'constant-velocity', 'sigmaA' => 0.5],
				'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [0.0025]],
				'x0' => [100.0, 0.0],
				'P0' => [0.01, 0.0, 0.0, 1.0],
			];
			$measurements = [];
			for ($k = 1; $k <= self::TICKS; $k++) {
				$measurements[] = Measurement::at($k * 100_000_000, [0 => 100.0 + 0.001 * ($k % 100)]);
			}
			$out = [];
			foreach ([1, 16, 64, 256, 1024] as $batch) {
				/** @var Queue<array<string, mixed>> $snapshots */
				$snapshots = new Queue(64);
				$session = new WorkerSession($config, null, $snapshots, batchSize: $batch, snapshotEvery: 1_000_000, pool: $pool);
				$drain = async(static function () use ($snapshots): void {
					foreach ($snapshots->iterate() as $_) {
					}
				});
				$t0 = \hrtime(true);
				$session->start($measurements)->await();
				$drain->await();
				$out["batch_{$batch}_us_per_tick"] = (\hrtime(true) - $t0) / 1e3 / self::TICKS;
			}
			$pool->shutdown();
			return $out;
		})->await();
		return $result;
	}
}
