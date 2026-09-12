<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Async;

use Amp\DeferredFuture;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use function Amp\async;

/**
 * §6.5, producer side: turns a stream of measurements into ARRAYS of
 * measurements before they enter the FilterSession inbox, so that one
 * Queue::push/iterate pair (≈ 1 µs) is paid per batch instead of per tick.
 *
 * A helper fiber drains the source into a plain array; the pump fiber takes
 * everything that has accumulated while it was pushing the previous batch and
 * pushes it as one item. Under load batches grow towards $maxBatch; at low
 * load a batch is one measurement and latency is unchanged. Back-pressure is
 * preserved (the drain fiber stops at $maxBatch), order is preserved, and
 * commands are forwarded individually at their position in the stream.
 *
 * FilterSession accepts array<Measurement> items natively.
 */
final class MeasurementBatcher
{
	private int $batches = 0;
	private int $items = 0;

	public function __construct(private readonly int $maxBatch = 256)
	{
		if ($maxBatch < 1) {
			throw new InvalidArgument('maxBatch must be >= 1');
		}
	}

	/**
	 * Pumps $source into $inbox until the source is exhausted. Runs in the calling fiber.
	 *
	 * @param iterable<mixed> $source measurements and commands (other items are ignored)
	 * @param Queue<Measurement|Command|array<int, Measurement>> $inbox
	 */
	public function pump(iterable $source, Queue $inbox): void
	{
		/** @var array<int, Measurement|Command> $buffer */
		$buffer = [];
		$completed = false;
		$failure = null;
		/** @var DeferredFuture<null>|null $wake */
		$wake = null;
		/** @var DeferredFuture<null>|null $space */
		$space = null;
		$max = $this->maxBatch;

		$drain = async(static function () use ($source, &$buffer, &$completed, &$failure, &$wake, &$space, $max): void {
			try {
				foreach ($source as $item) {
					if (!$item instanceof Measurement && !$item instanceof Command) {
						continue;
					}
					$buffer[] = $item;
					if ($wake !== null) {
						$w = $wake;
						$wake = null;
						$w->complete();
					}
					if (\count($buffer) >= $max) {
						$space = new DeferredFuture();
						$space->getFuture()->await();
					}
				}
			} catch (\Throwable $e) {
				$failure = $e;
			} finally {
				$completed = true;
				if ($wake !== null) {
					$w = $wake;
					$wake = null;
					$w->complete();
				}
			}
		});

		try {
			while (true) {
				if ($buffer === []) {
					if ($completed) {
						break;
					}
					$wake = new DeferredFuture();
					$wake->getFuture()->await();
					continue;
				}
				$taken = $buffer;
				$buffer = [];
				if ($space !== null) {
					$s = $space;
					$space = null;
					$s->complete();
				}
				$this->flush($taken, $inbox);
			}
		} finally {
			if ($space !== null) {
				$space->complete();
			}
		}
		$drain->await();
		if ($failure !== null) {
			throw $failure;
		}
	}

	/**
	 * Pushes a taken buffer: runs of measurements become one array item, commands go through individually.
	 *
	 * @param array<int, Measurement|Command> $taken
	 * @param Queue<Measurement|Command|array<int, Measurement>> $inbox
	 */
	private function flush(array $taken, Queue $inbox): void
	{
		/** @var array<int, Measurement> $run */
		$run = [];
		foreach ($taken as $item) {
			if ($item instanceof Measurement) {
				$run[] = $item;
				continue;
			}
			if ($run !== []) {
				$this->push($run, $inbox);
				$run = [];
			}
			$inbox->push($item);
			$this->batches++;
			$this->items++;
		}
		if ($run !== []) {
			$this->push($run, $inbox);
		}
	}

	/**
	 * @param array<int, Measurement> $run
	 * @param Queue<Measurement|Command|array<int, Measurement>> $inbox
	 */
	private function push(array $run, Queue $inbox): void
	{
		$inbox->push(\count($run) === 1 ? $run[0] : $run);
		$this->batches++;
		$this->items += \count($run);
	}

	/** Items pushed into the inbox (batches + individual commands). */
	public function batches(): int
	{
		return $this->batches;
	}

	/** Measurements and commands forwarded. */
	public function items(): int
	{
		return $this->items;
	}
}
