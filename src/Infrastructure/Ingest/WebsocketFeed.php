<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use OpenCCK\Kalman\Domain\Contract\Clock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\delay;

/**
 * §5.2 one market-data WebSocket → stream of Measurement with back-pressure.
 *
 * If the session cannot keep up, Queue::push() suspends this fiber, the
 * socket stops being read, the TCP window closes and the exchange throttles.
 * Nothing is dropped or buffered without bound.
 *
 * A silent connection (no message within staleAfterSeconds) is treated as
 * dead and reconnected — TimeoutCancellation on receive() is the only way
 * to detect a hung feed.
 */
final class WebsocketFeed implements Feed
{
	private int $reconnects = 0;
	private int $decoded = 0;
	private WebsocketConnector $connector;
	private LoggerInterface $logger;

	/**
	 * @param array<int, string> $subscribeMessages sent right after connecting (exchange subscription JSON)
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $url,
		private readonly MessageDecoder $decoder,
		private readonly float $staleAfterSeconds = 5.0,
		private readonly float $reconnectDelay = 1.0,
		private readonly float $connectTimeout = 10.0,
		private readonly array $subscribeMessages = [],
		private readonly ?Clock $clock = null,
		?WebsocketConnector $connector = null,
		?LoggerInterface $logger = null,
	) {
		$this->connector = $connector ?? new Rfc6455Connector();
		$this->logger = $logger ?? new NullLogger();
	}

	public function name(): string
	{
		return $this->name;
	}

	public function pumpInto(Queue $sink, Cancellation $cancellation): void
	{
		// No explicit check at the top of the loop: when the cancellation is already
		// requested, connect() throws CancelledException immediately and the catch
		// below returns — one exit path for "stopped" instead of two.
		while (true) {
			$connection = null;
			try {
				$connection = $this->connector->connect(
					new WebsocketHandshake($this->url),
					new CompositeCancellation($cancellation, new TimeoutCancellation($this->connectTimeout)),
				);
				foreach ($this->subscribeMessages as $message) {
					$connection->sendText($message);
				}
				$this->logger->info('feed connected', ['feed' => $this->name, 'url' => $this->url]);

				// receive() returns null when the server closes → reconnect.
				// The composite cancellation makes an external stop interrupt receive() immediately,
				// while the timeout part detects a silent (stale) connection.
				while (true) {
					$message = $connection->receive(new CompositeCancellation($cancellation, new TimeoutCancellation($this->staleAfterSeconds)));
					if ($message === null) {
						break;
					}
					$payload = $message->buffer();
					$received = $this->clock?->realtimeNs();
					$measurement = $this->decoder->decode($payload, $received);
					if ($measurement !== null) {
						$this->decoded++;
						$sink->push($measurement);   // ← back-pressure
					}
				}
				$this->logger->notice('feed closed by server', ['feed' => $this->name]);
			} catch (CancelledException) {
				if ($cancellation->isRequested()) {
					self::closeQuietly($connection);
					return;
				}
				$this->logger->warning('feed stale, reconnecting', ['feed' => $this->name, 'staleAfter' => $this->staleAfterSeconds]);
			} catch (DisposedException) {
				// the consumer went away: nothing left to feed
				self::closeQuietly($connection);
				return;
			} catch (\Throwable $e) {
				$this->logger->error('feed error, reconnecting', ['feed' => $this->name, 'error' => $e->getMessage()]);
			}
			self::closeQuietly($connection);

			$this->reconnects++;
			try {
				delay($this->reconnectDelay, cancellation: $cancellation);
			} catch (CancelledException) {
				return;
			}
		}
		// NOT completing $sink: shared by several feeds, the orchestrator completes it (§5.3)
	}

	private static function closeQuietly(?WebsocketConnection $connection): void
	{
		if ($connection === null || $connection->isClosed()) {
			return;
		}
		try {
			$connection->close();
		} catch (\Throwable) {
			// closing a half-dead socket may itself fail; nothing to do about it
		}
	}

	public function reconnects(): int
	{
		return $this->reconnects;
	}

	public function decoded(): int
	{
		return $this->decoded;
	}
}
