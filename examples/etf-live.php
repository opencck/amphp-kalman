<?php declare(strict_types=1);

/**
 * Live ETF basket estimation from two WebSocket feeds (§8 Phase 4 example).
 *
 *   php examples/etf-live.php --mock            # two in-process mock exchanges, 3 constituents + ETF quote
 *   php examples/etf-live.php ws://a ws://b     # real feeds using the library JSON tick format
 *
 * Topology (§5.1): feeds → Queue → ReorderBuffer → FilterSession → snapshots → console.
 * Ctrl+C stops feeds first, then completes the inbox, then waits for the owner (§5.12).
 */

use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Diagnostics\ConsistencyMonitor;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Model\Finance\EtfBasket;
use OpenCCK\Kalman\Infrastructure\Command\Command;
use OpenCCK\Kalman\Infrastructure\Async\Clock\SystemClock;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;
use OpenCCK\Kalman\Infrastructure\Async\ReorderBuffer;
use OpenCCK\Kalman\Infrastructure\Ingest\IngestOrchestrator;
use OpenCCK\Kalman\Infrastructure\Ingest\JsonTickDecoder;
use OpenCCK\Kalman\Infrastructure\Ingest\WebsocketFeed;
use OpenCCK\Kalman\Tests\Support\MockExchangeServer;
use function Amp\async;
use function Amp\trapSignal;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */

$args = \array_slice($argv, 1);
$mock = \in_array('--mock', $args, true);
$urls = \array_values(\array_filter($args, static fn (string $a): bool => !\str_starts_with($a, '--')));

$etf = new EtfBasket(
	weights: [0.5, 0.3, 0.2],
	sigma: [4e-6, 1e-6, 5e-7, 1e-6, 3e-6, 2e-7, 5e-7, 2e-7, 2e-6],
	sigmaA: 0.002,
	premiumTheta: 0.05,
	premiumSigma: 0.01,
	quoteVariances: [1e-4, 2e-4, 3e-4],
	etfVariance: 5e-5,
);
$filter = $etf->filter([100.0, 50.0, 20.0], FilterConfig::default()->withGating(GatingPolicy::chiSquare(0.001)));

$servers = [];
if ($mock) {
	$clock = new SystemClock();
	/**
	 * @param array<int, int> $channels
	 * @return array<int, array{0: float, 1: string|null}>
	 */
	/**
	 * @param list<int> $channels
	 * @return array<int, array{0: float, 1: string}>
	 */
	$mkScript = static function (array $channels, float $period) use ($clock): array {
		/** @var list<int> $channels */
		$script = [];
		$t = $clock->realtimeNs();
		$prices = [0 => 100.0, 1 => 50.0, 2 => 20.0, 3 => 69.0];
		for ($k = 0; $k < 2000; $k++) {
			$t += (int) ($period * 1e9);
			$ch = $channels[$k % \count($channels)];
			$prices[$ch] += 0.01 * (\mt_rand(-100, 100) / 100.0);
			$script[] = [$period, MockExchangeServer::tick($t, [$ch => $prices[$ch]])];
		}
		return $script;
	};
	$servers[] = $a = new MockExchangeServer($mkScript([0, 1, 2], 0.01));
	$servers[] = $b = new MockExchangeServer($mkScript([3], 0.03));
	$urls = [$a->start(), $b->start()];
	\fwrite(\STDOUT, "mock exchanges: {$urls[0]} (constituents), {$urls[1]} (ETF quote)\n");
}
if (\count($urls) < 1) {
	\fwrite(\STDERR, "usage: php examples/etf-live.php --mock | ws://feed-a [ws://feed-b ...]\n");
	exit(1);
}

$feeds = [];
foreach ($urls as $i => $url) {
	$feeds[] = new WebsocketFeed("feed$i", (string) $url, new JsonTickDecoder(), staleAfterSeconds: 10.0, clock: new SystemClock());
}
$handle = (new IngestOrchestrator($feeds))->start();

/** @var Queue<Measurement|Command> $inbox */
$inbox = new Queue(4096);
/** @var Queue<StateSnapshot> $snapshots */
$snapshots = new Queue(16);
$monitor = new ConsistencyMonitor($etf->observation()->channelCount());
$session = new FilterSession($filter, $inbox, $snapshots, $monitor, snapshotEvery: 100);

$sessionFuture = $session->start();

// reorder fiber: exchange-time order with a 50 ms window
async(static function () use ($handle, $inbox): void {
	// the buffer passes commands through untouched, so an item is mixed until it is checked
	/** @psalm-suppress MixedAssignment */
	foreach ((new ReorderBuffer(50_000_000))->apply($handle->queue->iterate()) as $m) {
		if ($m instanceof Measurement) {
			$inbox->push($m);
		}
	}
	$inbox->complete();
});

// console consumer of snapshots
$printer = async(static function () use ($snapshots, $etf, $monitor): void {
	foreach ($snapshots->iterate() as $s) {
		$nav = 0.0;
		foreach ($etf->weights() as $i => $w) {
			$nav += $w * $s->mean($i);
		}
		\fwrite(\STDOUT, \sprintf(
			"steps=%6d  p=[%.3f %.3f %.3f]  premium=%+.4f±%.4f  NAV=%.3f±%.4f  NIS=%.2f %s\n",
			$s->steps,
			$s->mean(0),
			$s->mean(1),
			$s->mean(2),
			$s->mean($etf->premiumIndex()),
			$s->stddev($etf->premiumIndex()),
			$nav,
			\sqrt($s->linearVariance($etf->weights())),
			$monitor->rollingNis(),
			$monitor->modelBreakSuspected() ? 'MODEL BREAK?' : '',
		));
	}
});

$shutdown = static function () use ($handle, $sessionFuture, $printer, $servers): void {
	\fwrite(\STDOUT, "stopping feeds…\n");
	$handle->stop();          // 1. sources stop
	$handle->join();          //    queue completes → reorder fiber completes the inbox
	$final = $sessionFuture->await();   // 2. owner drains the buffer
	$printer->await();
	foreach ($servers as $s) {
		$s->stop();
	}
	\fwrite(\STDOUT, \sprintf("final: steps=%d ts=%s\n", $final->steps, (string) $final->timestampNs));
};

if (\extension_loaded('pcntl')) {                       // common-mistakes #7: SIGINT undefined on Windows
	trapSignal([\SIGINT, \SIGTERM]);
	$shutdown();
} else {
	// Windows / no pcntl: run for a fixed time in mock mode, forever otherwise
	if ($mock) {
		\Amp\delay(15.0);
		$shutdown();
	} else {
		\Revolt\EventLoop::run();
	}
}
