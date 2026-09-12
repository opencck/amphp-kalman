<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Task;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use OpenCCK\Kalman\Domain\Factory\FilterFactory;

/**
 * §5.9 a worker that owns one filter for a group of instruments. Receives
 * BATCHES of raw ticks from the parent through the IPC channel (one send per
 * 64–512 ticks — IPC costs 20–50 µs per message, a filter step ~5 µs), runs
 * the same atomic stepRaw() as the in-process filter and sends compact
 * snapshots back every snapshotEvery ticks.
 *
 * Constructor arguments are plain arrays: the model is rebuilt INSIDE run()
 * from the serialisable config (no closures, no connections cross the boundary).
 *
 * Wire format from the parent: array<int, array{ts: int, values: array<int, float>}>, then null = end of stream.
 * Wire format to the parent:   StateSnapshot::toCompactArray(); the full snapshot is the task's return value.
 *
 * @implements Task<mixed, mixed, mixed>
 */
final class FilterWorkerTask implements Task
{
	/**
	 * @param array<string, mixed> $modelConfig
	 * @param array<string, mixed>|null $filterConfig
	 * @param array<string, mixed>|null $snapshot resume point (StateSnapshot::toArray())
	 */
	public function __construct(
		private readonly array $modelConfig,
		private readonly ?array $filterConfig = null,
		private readonly int $snapshotEvery = 200,
		private readonly ?array $snapshot = null,
	) {
	}

	/** @return array{processed: int, final: array<string, mixed>} */
	public function run(Channel $channel, Cancellation $cancellation): array
	{
		$filter = FilterFactory::fromConfig($this->modelConfig, $this->filterConfig, $this->snapshot);
		$processed = 0;
		$sinceSnapshot = 0;

		try {
			while (true) {
				/** @var array<int, array{ts: int, values: array<int, float>}>|null $batch */
				$batch = $channel->receive($cancellation);   // throws ChannelException when the parent closes
				if ($batch === null) {
					break;                                    // explicit end-of-stream sentinel from Batcher
				}
				foreach ($batch as $tick) {
					$filter->stepRaw($tick['ts'], $tick['values']);
					$processed++;
					if (++$sinceSnapshot >= $this->snapshotEvery) {
						$sinceSnapshot = 0;
						$channel->send($filter->snapshot()->toCompactArray());
					}
				}
			}
		} catch (ChannelException) {
			// parent closed the channel — normal shutdown
		}

		return ['processed' => $processed, 'final' => $filter->snapshot()->toArray()];
	}
}
