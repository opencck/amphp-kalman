<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Support;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Psr\Log\NullLogger;
use function Amp\delay;

/**
 * Scripted WebSocket "exchange" for tests and benchmarks: on every
 * connection it plays a script of [delaySeconds, payload] entries (payload
 * null = stay silent for the delay), then keeps the connection open until
 * the client leaves (or closes it if $closeAfterScript).
 */
final class MockExchangeServer implements WebsocketClientHandler
{
	private ?SocketHttpServer $server = null;
	private int $connections = 0;
	private string $address = '';

	/**
	 * @param array<int, array{0: float, 1: string|null}> $script
	 */
	public function __construct(
		private array $script,
		private readonly bool $closeAfterScript = false,
	) {
	}

	/** @param array<int, array{0: float, 1: string|null}> $script */
	public function setScript(array $script): void
	{
		$this->script = $script;
	}

	public function start(): string
	{
		$logger = new NullLogger();
		$server = SocketHttpServer::createForDirectAccess($logger, enableCompression: false);
		$server->expose('127.0.0.1:0');
		$websocket = new Websocket($server, $logger, new Rfc6455Acceptor(), $this);
		$server->start($websocket, new DefaultErrorHandler());
		$this->server = $server;
		$sockets = $server->getServers();
		$first = \reset($sockets);
		if ($first === false) {
			throw new \RuntimeException('mock server did not bind');
		}
		$this->address = $first->getAddress()->toString();
		return $this->url();
	}

	public function url(): string
	{
		return 'ws://' . $this->address . '/ws';
	}

	public function stop(): void
	{
		$this->server?->stop();
		$this->server = null;
	}

	public function connections(): int
	{
		return $this->connections;
	}

	public function handleClient(WebsocketClient $client, Request $request, Response $response): void
	{
		$this->connections++;
		foreach ($this->script as [$pause, $payload]) {
			if ($pause > 0.0) {
				delay($pause);
			}
			if ($client->isClosed()) {
				return;
			}
			if ($payload !== null) {
				$client->sendText($payload);
			}
		}
		if ($this->closeAfterScript) {
			$client->close();
			return;
		}
		while ($client->receive() !== null) {
			// drain until the client disconnects
		}
	}

	/**
	 * Builds a JSON tick payload in the library format.
	 *
	 * @param array<int, float> $values channel => value
	 */
	public static function tick(int $ts, array $values): string
	{
		$v = [];
		foreach ($values as $ch => $val) {
			$v[(string) $ch] = $val;
		}
		return \json_encode(['ts' => $ts, 'values' => $v], \JSON_THROW_ON_ERROR);
	}
}
