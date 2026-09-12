<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Support;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Infrastructure\Ingest\Feed;
use function Amp\delay;

/**
 * In-memory Feed for tests: pushes the given measurements (with optional
 * pauses) and then either returns or waits for cancellation.
 */
final class ScriptedFeed implements Feed
{
	public int $pushed = 0;

	/**
	 * @param array<int, Measurement> $measurements
	 */
	public function __construct(
		private readonly string $name,
		private readonly array $measurements,
		private readonly float $pause = 0.0,
		private readonly bool $waitForCancellation = false,
	) {
	}

	public function name(): string
	{
		return $this->name;
	}

	public function pumpInto(Queue $sink, Cancellation $cancellation): void
	{
		foreach ($this->measurements as $m) {
			if ($cancellation->isRequested()) {
				return;
			}
			if ($this->pause > 0.0) {
				try {
					delay($this->pause, cancellation: $cancellation);
				} catch (CancelledException) {
					return;
				}
			}
			$sink->push($m);
			$this->pushed++;
		}
		if ($this->waitForCancellation) {
			try {
				delay(3600.0, cancellation: $cancellation);
			} catch (CancelledException) {
				return;
			}
		}
	}
}
