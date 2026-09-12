<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Task;

use Amp\Sync\Channel;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * §5.9 parent-side batcher: accumulates measurements from an iterable
 * (Queue::iterate(), ReorderBuffer::apply()) and ships them to a worker in
 * arrays of batchSize raw ticks. Latency grows by one batch; IPC cost per
 * tick drops by the batch size. A partial batch is flushed at the end.
 */
final class Batcher
{
	private int $sent = 0;
	private int $batches = 0;

	public function __construct(private readonly int $batchSize = 256)
	{
		if ($batchSize < 1) {
			throw new InvalidArgument('batchSize must be >= 1');
		}
	}

	/**
	 * Ships the source as batches and finally a null sentinel (end of stream).
	 *
	 * @param iterable<mixed> $source Measurement items are batched; anything else is ignored
	 * @param Channel<mixed, mixed> $channel worker channel; receives batches then a null sentinel
	 */
	public function pump(iterable $source, Channel $channel, bool $sendSentinel = true): void
	{
		$batch = [];
		foreach ($source as $item) {
			if (!$item instanceof Measurement) {
				continue;
			}
			$batch[] = ['ts' => $item->timestampNs, 'values' => $item->values];
			if (\count($batch) >= $this->batchSize) {
				$channel->send($batch);
				$this->sent += \count($batch);
				$this->batches++;
				$batch = [];
			}
		}
		if ($batch !== []) {
			$channel->send($batch);
			$this->sent += \count($batch);
			$this->batches++;
		}
		if ($sendSentinel) {
			$channel->send(null);
		}
	}

	public function sent(): int
	{
		return $this->sent;
	}

	public function batches(): int
	{
		return $this->batches;
	}
}
