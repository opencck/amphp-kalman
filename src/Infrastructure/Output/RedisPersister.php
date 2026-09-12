<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Output;

use Amp\Pipeline\Queue;
use Amp\Redis\RedisClient;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;

/**
 * §5.8 cross-process snapshot persistence in Redis: the latest state under
 * `{prefix}:{instrument}:state` and an optional capped history list under
 * `{prefix}:{instrument}:history` for restart / audit.
 *
 * Requires amphp/redis (suggested, not required). Create the client with the
 * factory function — `Amp\Redis\createRedisClient('redis://127.0.0.1:6379')`
 * — never with the RedisClient constructor (common-mistakes #16).
 */
final class RedisPersister
{
	private int $written = 0;

	public function __construct(
		private readonly RedisClient $redis,
		private readonly string $instrument,
		private readonly SnapshotSerializer $serializer = new SnapshotSerializer(),
		private readonly string $prefix = 'kalman',
		private readonly int $historyLength = 0,
	) {
	}

	/** @param Queue<StateSnapshot> $snapshots */
	public function consume(Queue $snapshots): void
	{
		foreach ($snapshots->iterate() as $snapshot) {
			$this->save($snapshot);
		}
	}

	public function save(StateSnapshot $snapshot): void
	{
		$encoded = $this->serializer->encode($snapshot);
		$this->redis->set($this->stateKey(), $encoded);
		if ($this->historyLength > 0) {
			$list = $this->redis->getList($this->historyKey());
			$list->pushHead($encoded);
			$list->trim(0, $this->historyLength - 1);
		}
		$this->written++;
	}

	public function load(): ?StateSnapshot
	{
		$json = $this->redis->get($this->stateKey());
		return $json === null ? null : $this->serializer->decode($json);
	}

	public function stateKey(): string
	{
		return $this->prefix . ':' . $this->instrument . ':state';
	}

	public function historyKey(): string
	{
		return $this->prefix . ':' . $this->instrument . ':history';
	}

	public function written(): int
	{
		return $this->written;
	}
}
