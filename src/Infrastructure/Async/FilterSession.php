<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Async;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Diagnostics\ConsistencyMonitor;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\OutOfSequencePolicy;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Exception\OutOfSequenceMeasurement;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Filter\Retrodiction;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use OpenCCK\Kalman\Infrastructure\Command\CorporateAction;
use OpenCCK\Kalman\Infrastructure\Command\SnapshotRequest;
use function Amp\async;

/**
 * The single owner of a KalmanFilter (§4.5). Reads Measurement | Command
 * from the inbox and executes them strictly sequentially. The filter step
 * itself is synchronous and atomic — there is no await between predict()
 * and correct(). Asynchrony lives around the filter, never inside it.
 *
 * Output: every snapshotEvery-th tick a StateSnapshot is pushed to the
 * snapshots queue (push() may suspend if consumers are slow — the filter
 * state is already consistent at that point). The queue is completed when
 * the inbox completes.
 *
 * Batching (§6.5), two levels. Producer side: MeasurementBatcher pushes ARRAYS
 * of measurements so one Queue push/iterate pair is paid per batch; the
 * session accepts them natively. Consumer side: with batchSize > 0 a helper fiber drains the inbox into
 * a plain array and the owner takes the whole array when woken, so under
 * load one fiber switch is amortised over many ticks; at low load a batch
 * is one tick and latency is unchanged. Back-pressure is preserved: the
 * helper stops draining when the array holds batchSize items and resumes
 * when the owner has taken them. Command order is preserved exactly.
 */
final class FilterSession
{
	private KalmanFilter $filter;
	private int $processed = 0;
	private int $dropped = 0;
	private int $retrodicted = 0;
	private int $batches = 0;

	/**
	 * @param Queue<Measurement|Command>|Queue<Measurement|Command|array<int, Measurement>> $inbox measurements, commands, or arrays of measurements (MeasurementBatcher)
	 * @param Queue<StateSnapshot> $snapshots
	 * @param int $batchSize 0 = one command per fiber switch (plain iterate); > 0 = §6.5 batching cap
	 */
	public function __construct(
		KalmanFilter $filter,
		private readonly Queue $inbox,
		private readonly Queue $snapshots,
		private readonly ?ConsistencyMonitor $monitor = null,
		private readonly int $snapshotEvery = 100,
		private readonly OutOfSequencePolicy $oosPolicy = OutOfSequencePolicy::Drop,
		private readonly int $batchSize = 0,
	) {
		if ($snapshotEvery < 1) {
			throw new InvalidArgument('snapshotEvery must be >= 1');
		}
		if ($batchSize < 0) {
			throw new InvalidArgument('batchSize must be >= 0');
		}
		$this->filter = $filter;
	}

	/**
	 * Starts the owner loop in its own fiber and returns the final snapshot future.
	 *
	 * @return Future<StateSnapshot>
	 */
	public function start(): Future
	{
		/** @var Future<StateSnapshot> $future */
		$future = async(fn (): StateSnapshot => $this->run());
		return $future;
	}

	/**
	 * Owner loop. Returns the final snapshot when the inbox is completed.
	 * Runs in the calling fiber — use start() to spawn it.
	 */
	public function run(): StateSnapshot
	{
		try {
			if ($this->batchSize > 0) {
				$this->runBatched($this->batchSize);
			} else {
				foreach ($this->inbox->iterate() as $command) {
					$this->dispatch($command);
					$this->batches++;
				}
			}
			return $this->filter->snapshot();
		} finally {
			$this->snapshots->complete();
		}
	}

	/**
	 * §6.5: drain fiber → array → owner takes everything available.
	 *
	 * $buffer, $completed and $failure are written by the drain fiber through a
	 * by-reference capture. Psalm does not model that, concludes the loop can never
	 * reach its `break` and reports everything after it as unevaluated.
	 *
	 * @psalm-suppress UnevaluatedCode, UnusedVariable
	 */
	private function runBatched(int $max): void
	{
		/** @var array<int, Measurement|Command|array<int, Measurement>> $buffer */
		$buffer = [];
		$completed = false;
		$failure = null;
		/** @var DeferredFuture<null>|null $wake owner sleeps on it while the buffer is empty */
		$wake = null;
		/** @var DeferredFuture<null>|null $space drain fiber sleeps on it while the buffer is full */
		$space = null;

		$drain = async(function () use (&$buffer, &$completed, &$failure, &$wake, &$space, $max): void {
			try {
				foreach ($this->inbox->iterate() as $command) {
					$buffer[] = $command;
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
				$batch = $buffer;
				$buffer = [];
				if ($space !== null) {
					$s = $space;
					$space = null;
					$s->complete();
				}
				$this->batches++;
				foreach ($batch as $command) {
					$this->dispatch($command);
				}
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
	 * @param Measurement|Command|array<int, Measurement> $command an array is a batch of measurements
	 *                                                            (MeasurementBatcher) — one fiber switch for all of them
	 */
	private function dispatch(Measurement|Command|array $command): void
	{
		if ($command instanceof Measurement) {
			$this->onMeasurement($command);
		} elseif (\is_array($command)) {
			foreach ($command as $m) {
				$this->onMeasurement($m);
			}
		} elseif ($command instanceof SnapshotRequest) {
			$command->deferred->complete($this->filter->snapshot());
		} elseif ($command instanceof CorporateAction) {
			$this->filter = $command->applyTo($this->filter);
		} else {
			throw new InvalidArgument('Unknown command ' . $command::class);
		}
	}

	private function onMeasurement(Measurement $m): void
	{
		$last = $this->filter->lastTimestampNs();
		if ($last !== null && $m->timestampNs < $last) {
			switch ($this->oosPolicy) {
				case OutOfSequencePolicy::Drop:
					$this->dropped++;
					$this->monitor?->recordDropped($m);
					return;
				case OutOfSequencePolicy::Retrodict:
					$result = Retrodiction::apply($this->filter, $m);
					$this->retrodicted++;
					if ($result !== null) {
						$this->monitor?->record($result);
					} else {
						$this->dropped++;
						$this->monitor?->recordDropped($m);
					}
					return;
				case OutOfSequencePolicy::Fail:
					throw new OutOfSequenceMeasurement($m->timestampNs, $last);
			}
		}

		// ── atomic synchronous step: no await below this line ──
		$result = $this->filter->step($m);
		$this->monitor?->record($result);
		// ── end of atomic section ──

		if (++$this->processed % $this->snapshotEvery === 0) {
			$this->snapshots->push($this->filter->snapshot());
		}
	}

	/**
	 * Snapshot linearised in the command stream: reflects the state exactly
	 * after the measurement that precedes this request in the inbox.
	 *
	 * @return Future<StateSnapshot>
	 */
	public function requestSnapshot(): Future
	{
		$request = new SnapshotRequest();
		$this->inbox->push($request);
		return $request->future();
	}

	public function processed(): int
	{
		return $this->processed;
	}

	public function dropped(): int
	{
		return $this->dropped;
	}

	public function retrodicted(): int
	{
		return $this->retrodicted;
	}

	/** Number of owner wake-ups (batches taken); processed()/batches() is the amortisation factor. */
	public function batches(): int
	{
		return $this->batches;
	}

	public function monitor(): ?ConsistencyMonitor
	{
		return $this->monitor;
	}
}
