<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use Amp\PHPUnit\AsyncTestCase;
use Amp\TimeoutCancellation;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Infrastructure\Ingest\IngestOrchestrator;
use OpenCCK\Kalman\Tests\Support\ScriptedFeed;
use function Amp\async;
use function Amp\delay;

final class IngestOrchestratorTest extends AsyncTestCase
{
	/** §5.13: the shared queue completes only when ALL feeds have stopped. */
	public function testQueueCompletesWhenAllFeedsStop(): void
	{
		$a = new ScriptedFeed('a', [Measurement::at(1, [0 => 1.0]), Measurement::at(3, [0 => 3.0])]);
		$b = new ScriptedFeed('b', [Measurement::at(2, [1 => 2.0])], pause: 0.01);
		$handle = (new IngestOrchestrator([$a, $b]))->start();

		$received = [];
		foreach ($handle->queue->iterate() as $m) {
			$received[] = $m->timestampNs;
		}
		$handle->join();
		\sort($received);
		self::assertSame([1, 2, 3], $received);
		self::assertSame(2, $a->pushed);
		self::assertSame(1, $b->pushed);
	}

	public function testStopCancelsLongRunningFeeds(): void
	{
		$forever = new ScriptedFeed('live', [Measurement::at(1, [0 => 1.0])], waitForCancellation: true);
		$handle = (new IngestOrchestrator([$forever]))->start();

		$consumer = async(static function () use ($handle): int {
			$n = 0;
			foreach ($handle->queue->iterate() as $_) {
				$n++;
			}
			return $n;
		});
		delay(0.02);
		self::assertFalse($handle->isStopped());
		$handle->stop();
		self::assertTrue($handle->isStopped());
		$handle->done->await(new TimeoutCancellation(2.0));
		self::assertSame(1, $consumer->await());
	}

	public function testBackPressureLimitsBufferedItems(): void
	{
		$items = [];
		for ($k = 1; $k <= 50; $k++) {
			$items[] = Measurement::at($k, [0 => 1.0]);
		}
		$feed = new ScriptedFeed('bulk', $items);
		$handle = (new IngestOrchestrator([$feed], bufferSize: 4))->start();
		delay(0.01);
		// producer suspended on push(): at most bufferSize + 1 items got through before a consumer exists
		self::assertLessThanOrEqual(5, $feed->pushed);
		$count = 0;
		foreach ($handle->queue->iterate() as $_) {
			$count++;
		}
		self::assertSame(50, $count);
		$handle->join();
	}
}
