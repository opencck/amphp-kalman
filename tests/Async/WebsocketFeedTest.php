<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use Amp\DeferredCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use Amp\TimeoutCancellation;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Infrastructure\Async\Clock\ManualClock;
use OpenCCK\Kalman\Infrastructure\Ingest\JsonTickDecoder;
use OpenCCK\Kalman\Infrastructure\Ingest\WebsocketFeed;
use OpenCCK\Kalman\Tests\Support\MockExchangeServer;
use function Amp\async;
use function Amp\delay;

final class WebsocketFeedTest extends AsyncTestCase
{
	private ?MockExchangeServer $server = null;

	protected function tearDown(): void
	{
		$this->server?->stop();
		parent::tearDown();
	}

	public function testDecodesTicksAndSkipsServiceMessages(): void
	{
		$this->server = new MockExchangeServer([
			[0.0, '{"event":"subscribed"}'],
			[0.0, MockExchangeServer::tick(1_000, [0 => 100.5])],
			[0.0, MockExchangeServer::tick(2_000, [0 => 100.7, 1 => 50.1])],
			[0.0, '{"ping":1}'],
		]);
		$url = $this->server->start();

		$clock = new ManualClock(5_000);
		$feed = new WebsocketFeed('mock', $url, new JsonTickDecoder(), staleAfterSeconds: 2.0, subscribeMessages: ['{"op":"subscribe"}'], clock: $clock);
		/** @var Queue<Measurement> $sink */
		$sink = new Queue();
		$stop = new DeferredCancellation();
		$pump = async(static fn () => $feed->pumpInto($sink, $stop->getCancellation()));

		/** @var array<int, Measurement> $received */
		$received = [];
		foreach ($sink->iterate() as $m) {
			$received[] = $m;
			if (\count($received) === 2) {
				break;
			}
		}
		$stop->cancel();
		$pump->await(new TimeoutCancellation(3.0));

		self::assertCount(2, $received);
		self::assertSame(1_000, $received[0]->timestampNs);
		self::assertSame([0 => 100.5], $received[0]->values);
		self::assertSame(4_000, $received[0]->latencyNs());
		self::assertSame([0 => 100.7, 1 => 50.1], $received[1]->values);
		self::assertSame(2, $feed->decoded());
	}

	/** §5.13: a feed that goes silent longer than staleAfterSeconds reconnects. */
	public function testStaleFeedReconnects(): void
	{
		$this->server = new MockExchangeServer([
			[0.0, MockExchangeServer::tick(1_000, [0 => 1.0])],
			[1.0, null],   // silence longer than staleAfter → client reconnects
		]);
		$url = $this->server->start();

		$feed = new WebsocketFeed('mock', $url, new JsonTickDecoder(), staleAfterSeconds: 0.2, reconnectDelay: 0.05);
		/** @var Queue<Measurement> $sink */
		$sink = new Queue(64);
		$stop = new DeferredCancellation();
		$pump = async(static fn () => $feed->pumpInto($sink, $stop->getCancellation()));

		delay(1.2);
		$stop->cancel();
		$pump->await(new TimeoutCancellation(3.0));

		self::assertGreaterThanOrEqual(2, $this->server->connections(), 'feed should have reconnected');
		self::assertGreaterThanOrEqual(1, $feed->reconnects());
		self::assertGreaterThanOrEqual(2, $feed->decoded());
	}

	public function testServerCloseTriggersReconnect(): void
	{
		$this->server = new MockExchangeServer([
			[0.0, MockExchangeServer::tick(1_000, [0 => 1.0])],
		], closeAfterScript: true);
		$url = $this->server->start();

		$feed = new WebsocketFeed('mock', $url, new JsonTickDecoder(), staleAfterSeconds: 2.0, reconnectDelay: 0.05);
		/** @var Queue<Measurement> $sink */
		$sink = new Queue(64);
		$stop = new DeferredCancellation();
		$pump = async(static fn () => $feed->pumpInto($sink, $stop->getCancellation()));
		delay(0.5);
		$stop->cancel();
		$pump->await(new TimeoutCancellation(3.0));
		self::assertGreaterThanOrEqual(3, $this->server->connections());
	}
}
