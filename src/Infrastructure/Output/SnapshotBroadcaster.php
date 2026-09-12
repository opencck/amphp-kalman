<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Output;

use Amp\Pipeline\Queue;
use Amp\Websocket\Server\WebsocketClientGateway;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;

/**
 * §5.7 fan-out of snapshots to every connected WebSocket client.
 * broadcastText() is non-blocking: a slow client never stalls the others
 * or the session. Requires amphp/websocket-server.
 */
final class SnapshotBroadcaster
{
	private int $broadcast = 0;

	public function __construct(
		private readonly WebsocketClientGateway $gateway,
		private readonly SnapshotSerializer $serializer = new SnapshotSerializer(compact: true),
	) {
	}

	/**
	 * Consumes the snapshot queue until it completes.
	 *
	 * @param Queue<StateSnapshot> $snapshots
	 */
	public function consume(Queue $snapshots): void
	{
		foreach ($snapshots->iterate() as $snapshot) {
			$this->gateway->broadcastText($this->serializer->encode($snapshot))->ignore();
			$this->broadcast++;
		}
	}

	public function gateway(): WebsocketClientGateway
	{
		return $this->gateway;
	}

	public function broadcast(): int
	{
		return $this->broadcast;
	}
}
