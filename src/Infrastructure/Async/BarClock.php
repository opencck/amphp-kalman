<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Async;

use Amp\Interval;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Contract\Clock;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use function Amp\async;
use function Amp\weakClosure;

/**
 * §5.6 bar clock: when no data arrives (night, halt) the covariance must
 * still grow honestly. Every period a blind measurement stamped with the
 * REALTIME clock (epoch ns, comparable with exchange time — never hrtime)
 * is pushed into the session inbox.
 *
 * weakClosure() breaks the BarClock → Interval → closure → BarClock cycle
 * so the clock is collected when dropped; stop() cancels explicitly.
 */
final class BarClock
{
	private ?Interval $interval;
	private int $ticks = 0;

	/**
	 * @param Queue<Measurement|Command> $inbox
	 */
	public function __construct(
		private readonly Queue $inbox,
		private readonly Clock $clock,
		float $periodSeconds,
	) {
		if ($periodSeconds <= 0.0) {
			throw new InvalidArgument('periodSeconds must be > 0');
		}
		$this->interval = new Interval($periodSeconds, weakClosure(function (): void {
			// Interval callbacks are not fiber contexts for suspension; push() may suspend → async()
			$this->ticks++;
			$ts = $this->clock->realtimeNs();
			async(fn () => $this->inbox->push(Measurement::blind($ts)))->ignore();
		}), reference: false);
	}

	public function stop(): void
	{
		$this->interval = null;
	}

	public function isRunning(): bool
	{
		return $this->interval !== null;
	}

	public function ticks(): int
	{
		return $this->ticks;
	}
}
