<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Output;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;

/**
 * Push-only WebSocket endpoint: registers the client in the gateway (which
 * removes it on disconnect) and DRAINS incoming messages — not reading them
 * would overflow the receive buffer and deadlock (common-mistakes #18).
 */
final class StateWebsocketHandler implements WebsocketClientHandler
{
	public function __construct(private readonly WebsocketClientGateway $gateway)
	{
	}

	public function handleClient(WebsocketClient $client, Request $request, Response $response): void
	{
		$this->gateway->addClient($client);
		while ($client->receive() !== null) {
			// clients have nothing to say; drain to keep the connection healthy
		}
	}
}
