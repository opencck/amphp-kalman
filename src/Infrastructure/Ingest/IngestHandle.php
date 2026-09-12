<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Entity\Measurement;

/**
 * Running ingest: the shared measurement queue, a stop switch and a future
 * that completes when every feed has exited and the queue is completed.
 */
final class IngestHandle
{
	/**
	 * @param Queue<Measurement> $queue
	 * @param Future<null> $done
	 */
	public function __construct(
		public readonly Queue $queue,
		private readonly DeferredCancellation $stop,
		public readonly Future $done,
	) {
	}

	/** Cancels every feed; the queue completes once they have all exited. */
	public function stop(): void
	{
		$this->stop->cancel();
	}

	public function isStopped(): bool
	{
		return $this->stop->isCancelled();
	}

	/** Waits until all feeds stopped and the queue was completed. */
	public function join(): void
	{
		$this->done->await();
	}
}
