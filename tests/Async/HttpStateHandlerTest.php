<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use Amp\ByteStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use Amp\Websocket\Server\WebsocketClientGateway;
use League\Uri\Http;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use OpenCCK\Kalman\Infrastructure\Output\HttpStateHandler;
use OpenCCK\Kalman\Infrastructure\Output\SnapshotBroadcaster;
use OpenCCK\Kalman\Infrastructure\Output\SnapshotSerializer;
use function Amp\async;

final class HttpStateHandlerTest extends AsyncTestCase
{
	public function testReturnsLinearisedSnapshotAsJson(): void
	{
		/** @var Queue<Measurement|Command> $inbox */
		$inbox = new Queue();
		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue();
		$session = new FilterSession((new LocalLinearTrend(0.5, 0.05, 0.01))->filter(100.0), $inbox, $snapshots);
		$run = $session->start();
		async(static function () use ($snapshots): void {
			foreach ($snapshots->iterate() as $_) {
			}
		});
		$inbox->push(Measurement::at(1_000_000_000, [0 => 101.0]));

		$handler = new HttpStateHandler(['SPY' => $session]);
		$client = $this->createMock(Client::class);

		$ok = $handler->handleRequest(new Request($client, 'GET', Http::new('http://localhost/state/SPY')));
		self::assertSame(HttpStatus::OK, $ok->getStatus());
		self::assertSame('application/json', $ok->getHeader('content-type'));
		$body = \json_decode(ByteStream\buffer($ok->getBody()), true, 512, \JSON_THROW_ON_ERROR);
		self::assertIsArray($body);
		self::assertSame('SPY', $body['instrument']);
		self::assertSame(2, $body['n']);
		self::assertSame(1_000_000_000, $body['ts']);
		self::assertSame(1, $body['steps']);

		$missing = $handler->handleRequest(new Request($client, 'GET', Http::new('http://localhost/state/QQQ')));
		self::assertSame(HttpStatus::NOT_FOUND, $missing->getStatus());

		$inbox->complete();
		$run->await();
	}

	public function testBroadcasterEncodesEverySnapshot(): void
	{
		$gateway = new WebsocketClientGateway();   // no clients: broadcast is a no-op but must not fail
		$broadcaster = new SnapshotBroadcaster($gateway, new SnapshotSerializer('X', compact: true));
		/** @var Queue<StateSnapshot> $queue */
		$queue = new Queue();
		$consumer = async(static fn () => $broadcaster->consume($queue));
		$filter = (new LocalLinearTrend(0.5, 0.05, 0.01))->filter(100.0);
		$queue->push($filter->snapshot());
		$queue->push($filter->snapshot());
		$queue->complete();
		$consumer->await();
		self::assertSame(2, $broadcaster->broadcast());

		$json = (new SnapshotSerializer('X', compact: true))->encode($filter->snapshot());
		$data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
		self::assertIsArray($data);
		self::assertArrayHasKey('diag', $data);
		self::assertArrayNotHasKey('P', $data);
	}
}
