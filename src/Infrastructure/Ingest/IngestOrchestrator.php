<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use Amp\DeferredCancellation;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use function Amp\async;
use function Amp\Future\awaitAll;

/**
 * §5.3 several feeds → one measurement queue. Completes the queue when ALL
 * feeds have stopped (otherwise the consumer would hang forever).
 */
final class IngestOrchestrator
{
	/** @var array<int, Feed> */
	private array $feeds;

	/** @param array<int, Feed> $feeds */
	public function __construct(array $feeds, private readonly int $bufferSize = 4096)
	{
		if ($feeds === []) {
			throw new InvalidArgument('At least one feed is required');
		}
		if ($bufferSize < 0) {
			throw new InvalidArgument('bufferSize must be >= 0');
		}
		$this->feeds = \array_values($feeds);
	}

	public function start(): IngestHandle
	{
		/** @var Queue<Measurement> $queue */
		$queue = new Queue($this->bufferSize);   // ticks that may pile up before back-pressure kicks in
		$stop = new DeferredCancellation();

		$pumps = [];
		foreach ($this->feeds as $feed) {
			// foreach + use, not array_map + fn (common-mistakes #15)
			$pumps[] = async(static function () use ($feed, $queue, $stop): void {
				$feed->pumpInto($queue, $stop->getCancellation());
			});
		}

		/** @var \Amp\Future<null> $done */
		$done = async(static function () use ($pumps, $queue): null {
			[$errors] = awaitAll($pumps);
			if ($errors !== []) {
				$queue->error(\reset($errors));
			} else {
				$queue->complete();      // ← mandatory, otherwise FilterSession never exits
			}
			return null;
		});

		return new IngestHandle($queue, $stop, $done);
	}
}
