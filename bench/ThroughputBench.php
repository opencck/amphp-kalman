<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Model\Finance\EtfBasket;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;
use OpenCCK\Kalman\Infrastructure\Async\ReorderBuffer;
use OpenCCK\Kalman\Infrastructure\Ingest\IngestOrchestrator;
use OpenCCK\Kalman\Infrastructure\Ingest\JsonTickDecoder;
use OpenCCK\Kalman\Infrastructure\Ingest\WebsocketFeed;
use OpenCCK\Kalman\Tests\Support\MockExchangeServer;
use function Amp\async;

/**
 * §6.6 ThroughputBench: ticks per second through the whole async path —
 * mock WebSocket exchange (in-process) → WebsocketFeed → Queue →
 * ReorderBuffer → FilterSession(ETF basket, n = 7, m = 4).
 *
 * Note: the JSON decode + WebSocket framing dominates (§6.4): expect the
 * per-tick cost to exceed the filter step by an order of magnitude.
 */
final class ThroughputBench implements Benchmark
{
	private const TICKS = 20_000;

	public function name(): string
	{
		return 'throughput';
	}

	public function run(): array
	{
		/** @var array<string, float> $result */
		$result = async(function (): array {
			$script = [];
			for ($k = 1; $k <= self::TICKS; $k++) {
				$venue = $k % 4;
				$script[] = [0.0, MockExchangeServer::tick($k * 1_000_000, [$venue => 100.0 + 0.01 * ($k % 50)])];
			}
			$server = new MockExchangeServer($script, closeAfterScript: false);
			$url = $server->start();

			$etf = new EtfBasket([0.5, 0.3, 0.2], [4e-6, 1e-6, 5e-7, 1e-6, 3e-6, 2e-7, 5e-7, 2e-7, 2e-6], 0.002, 0.05, 0.01, [1e-4, 2e-4, 3e-4], 5e-5);
			$filter = $etf->filter([100.0, 100.0, 100.0]);

			$feed = new WebsocketFeed('mock', $url, new JsonTickDecoder(), staleAfterSeconds: 5.0);
			$handle = (new IngestOrchestrator([$feed], bufferSize: 4096))->start();
			/** @var Queue<Measurement|Command> $inbox */
			$inbox = new Queue(4096);
			/** @var Queue<StateSnapshot> $snapshots */
			$snapshots = new Queue(16);
			$session = new FilterSession($filter, $inbox, $snapshots, snapshotEvery: 1000);

			$t0 = \hrtime(true);
			$run = $session->start();
			$drain = async(static function () use ($snapshots): void {
				foreach ($snapshots->iterate() as $_) {
				}
			});
			$reorder = async(static function () use ($handle, $inbox, $t0): float {
				$buffer = new ReorderBuffer(2_000_000);
				$elapsed = 0.0;
				$forwarded = 0;
				foreach ($buffer->apply($handle->queue->iterate()) as $m) {
					if (!$m instanceof Measurement) {
						continue;
					}
					$inbox->push($m);
					// the reorder window withholds the last few ticks until the source ends:
					// stop a little before the end so the measurement is not distorted by the stale timeout
					if (++$forwarded >= self::TICKS - 8 && $elapsed === 0.0) {
						$elapsed = (\hrtime(true) - $t0) / 1e9;
						$handle->stop();
					}
				}
				$inbox->complete();
				return $elapsed;
			});
			/** @var float $elapsed */
			$elapsed = $reorder->await();
			$run->await();
			$drain->await();
			$server->stop();
			$handle->join();

			$processed = $session->processed();
			$seconds = $elapsed > 0.0 ? $elapsed : (\hrtime(true) - $t0) / 1e9;
			return [
				'ws_to_filter_ticks_per_s' => $processed / $seconds,
				'ws_to_filter_us_per_tick' => $seconds * 1e6 / \max(1, $processed),
			];
		})->await();
		return $result;
	}
}
