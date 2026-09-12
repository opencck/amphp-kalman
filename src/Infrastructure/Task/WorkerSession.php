<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Task;

use Amp\Future;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Pipeline\Queue;
use Amp\Sync\ChannelException;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use function Amp\async;
use function Amp\Parallel\Worker\workerPool;

/**
 * §5.9 parent-side counterpart of FilterWorkerTask: submits the task, batches
 * an iterable of measurements into the worker channel, and forwards compact
 * snapshots coming back into a Queue. `run()` returns when the source is
 * exhausted and the worker has returned its final full snapshot.
 */
final class WorkerSession
{
	private readonly WorkerPool $pool;

	/** @var Execution<mixed, mixed, mixed>|null */
	private ?Execution $execution = null;
	private int $snapshotsReceived = 0;

	/**
	 * @param array<string, mixed> $modelConfig
	 * @param array<string, mixed>|null $filterConfig
	 * @param Queue<array<string, mixed>> $snapshots compact snapshot arrays from the worker
	 */
	public function __construct(
		private readonly array $modelConfig,
		private readonly ?array $filterConfig,
		private readonly Queue $snapshots,
		private readonly int $batchSize = 256,
		private readonly int $snapshotEvery = 200,
		?WorkerPool $pool = null,
	) {
		if ($batchSize < 1 || $snapshotEvery < 1) {
			throw new InvalidArgument('batchSize and snapshotEvery must be >= 1');
		}
		$this->pool = $pool ?? workerPool();
	}

	/**
	 * @param iterable<mixed> $source measurements (other items ignored)
	 * @param array<string, mixed>|null $resumeFrom StateSnapshot::toArray()
	 * @return Future<StateSnapshot> final state of the worker filter
	 */
	public function start(iterable $source, ?array $resumeFrom = null): Future
	{
		$execution = $this->pool->submit(new FilterWorkerTask($this->modelConfig, $this->filterConfig, $this->snapshotEvery, $resumeFrom));
		$this->execution = $execution;
		$channel = $execution->getChannel();
		$snapshots = $this->snapshots;

		$receiver = async(function () use ($channel, $snapshots): void {
			try {
				while (!$channel->isClosed()) {
					/** @var array<string, mixed> $compact */
					$compact = $channel->receive();
					$this->snapshotsReceived++;
					$snapshots->push($compact);
				}
			} catch (ChannelException) {
				// worker finished
			}
		});

		$batcher = new Batcher($this->batchSize);
		/** @var Future<StateSnapshot> $future */
		$future = async(static function () use ($batcher, $source, $channel, $execution, $receiver, $snapshots): StateSnapshot {
			// the batcher ends the stream with a null sentinel; the worker then returns its final snapshot
			$batcher->pump($source, $channel);
			/** @var array{processed: int, final: array{n: int, x: array<int, float>, P: array<int, float>, ts?: int|null, ll?: float, steps?: int}} $result */
			$result = $execution->getFuture()->await();
			$receiver->await();
			$snapshots->complete();
			return StateSnapshot::fromArray($result['final']);
		});
		return $future;
	}

	public function snapshotsReceived(): int
	{
		return $this->snapshotsReceived;
	}

	/** @return Execution<mixed, mixed, mixed>|null */
	public function execution(): ?Execution
	{
		return $this->execution;
	}
}
