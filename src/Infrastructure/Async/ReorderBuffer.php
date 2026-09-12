<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Async;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Restores exchange-time order across venues (§2.7 variant 3, §5.4).
 *
 * Holds a window of W ns: a measurement is released once something newer
 * than (its timestamp + W) has arrived, or when the source ends. Ties are
 * released FIFO. Anything arriving later than W is still released (at the
 * end of the window) and reaches the session with a backwards timestamp —
 * the session's OutOfSequencePolicy decides what to do with it.
 *
 * Usable as a Pipeline operator: Pipeline::fromIterable($buffer->apply($queue->iterate())).
 */
final class ReorderBuffer
{
	private int $sequence = 0;

	public function __construct(private readonly int $windowNs)
	{
		if ($windowNs < 0) {
			throw new InvalidArgument('windowNs must be >= 0');
		}
	}

	/**
	 * @param iterable<mixed> $source Measurement items are reordered; other
	 *                                items (commands) pass through immediately
	 *                                after flushing older measurements.
	 * @return \Generator<int, mixed>
	 */
	public function apply(iterable $source): \Generator
	{
		/** @var \SplMinHeap<array{0: int, 1: int, 2: Measurement}> $heap */
		$heap = new \SplMinHeap();
		$watermark = \PHP_INT_MIN;

		foreach ($source as $item) {
			if (!$item instanceof Measurement) {
				// a command: everything that is already releasable goes first, then the command
				while (!$heap->isEmpty() && $heap->top()[0] <= $watermark - $this->windowNs) {
					yield $heap->extract()[2];
				}
				yield $item;
				continue;
			}
			if ($item->timestampNs > $watermark) {
				$watermark = $item->timestampNs;
			}
			$heap->insert([$item->timestampNs, $this->sequence++, $item]);
			while (!$heap->isEmpty() && $heap->top()[0] <= $watermark - $this->windowNs) {
				yield $heap->extract()[2];
			}
		}
		while (!$heap->isEmpty()) {
			yield $heap->extract()[2];
		}
	}

	public function windowNs(): int
	{
		return $this->windowNs;
	}
}
