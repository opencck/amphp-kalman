<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Command;

use Amp\DeferredFuture;
use Amp\Future;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;

/**
 * Asks the owner fiber for a snapshot taken exactly at this point of the
 * command stream (after the preceding tick, before the next one).
 */
final class SnapshotRequest implements Command
{
	/** @var DeferredFuture<StateSnapshot> */
	public readonly DeferredFuture $deferred;

	public function __construct()
	{
		/** @var DeferredFuture<StateSnapshot> $deferred */
		$deferred = new DeferredFuture();
		$this->deferred = $deferred;
	}

	/** @return Future<StateSnapshot> */
	public function future(): Future
	{
		return $this->deferred->getFuture();
	}
}
