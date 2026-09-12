<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use Amp\Cancellation;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Entity\Measurement;

/**
 * A source of measurements that pumps into a shared queue until cancelled.
 * Implementations MUST NOT complete the queue — the orchestrator does.
 */
interface Feed
{
	public function name(): string;

	/**
	 * @param Queue<Measurement> $sink
	 */
	public function pumpInto(Queue $sink, Cancellation $cancellation): void;
}
