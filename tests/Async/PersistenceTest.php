<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use Amp\File;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Infrastructure\Ingest\HistoryReader;
use OpenCCK\Kalman\Infrastructure\Ingest\TickCodec;
use OpenCCK\Kalman\Infrastructure\Output\FilePersister;
use OpenCCK\Kalman\Infrastructure\Output\SnapshotSerializer;
use function Amp\async;

final class PersistenceTest extends AsyncTestCase
{
	private string $dir = '';

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = \sys_get_temp_dir() . '/kalman-test-' . \bin2hex(\random_bytes(4));
		File\createDirectoryRecursively($this->dir, 0755);
	}

	protected function tearDown(): void
	{
		foreach (File\listFiles($this->dir) as $name) {
			File\deleteFile($this->dir . '/' . $name);
		}
		File\deleteDirectory($this->dir);
		parent::tearDown();
	}

	public function testFilePersisterRoundTripAndRestore(): void
	{
		$llt = new LocalLinearTrend(0.5, 0.05, 0.01);
		$filter = $llt->filter(100.0);
		for ($k = 1; $k <= 10; $k++) {
			$filter->step(Measurement::at($k * 100_000_000, [0 => 100.0 + 0.1 * $k]));
		}
		$path = $this->dir . '/state.json';
		$persister = new FilePersister($path, new SnapshotSerializer('TEST'));

		/** @var Queue<StateSnapshot> $snapshots */
		$snapshots = new Queue();
		$consumer = async(static fn () => $persister->consume($snapshots));
		$snapshots->push($filter->snapshot());
		$snapshots->complete();
		$consumer->await();

		self::assertSame(1, $persister->written());
		self::assertTrue(File\exists($path));
		self::assertFalse(File\exists($path . '.tmp'));

		$restored = $persister->load();
		self::assertNotNull($restored);
		$resumed = KalmanFilter::fromSnapshot($llt->motion(), $llt->observation(), $restored);
		self::assertSame($filter->mean(), $resumed->mean());
		self::assertSame($filter->lastTimestampNs(), $resumed->lastTimestampNs());

		// after a restart the first step predicts over the real gap → P inflated honestly
		$before = $resumed->variance(0);
		$resumed->step(Measurement::blind(10 * 100_000_000 + 60_000_000_000));
		self::assertGreaterThan($before * 10, $resumed->variance(0));

		self::assertNull((new FilePersister($this->dir . '/missing.json'))->load());
	}

	public function testHistoryReaderStreamsJsonLinesAndGzip(): void
	{
		$ticks = [];
		for ($k = 1; $k <= 100; $k++) {
			$ticks[] = Measurement::at($k * 1_000_000, [0 => 100.0 + $k, 1 => 50.0 - $k]);
		}
		foreach (['history.jsonl', 'history.jsonl.gz'] as $name) {
			$path = $this->dir . '/' . $name;
			self::assertSame(100, HistoryReader::write($path, $ticks));
			$read = HistoryReader::readAll($path);
			self::assertCount(100, $read);
			self::assertSame(1_000_000, $read[0]['ts']);
			self::assertSame([0 => 101.0, 1 => 49.0], $read[0]['values']);
			self::assertSame(100_000_000, $read[99]['ts']);
			$n = 0;
			foreach (HistoryReader::measurements($path) as $m) {
				self::assertSame($ticks[$n]->values, $m->values);
				$n++;
			}
			self::assertSame(100, $n);
		}
	}

	public function testTickCodecFormats(): void
	{
		$m = Measurement::at(1_700_000_000_000_000_000, [0 => 100.5, 2 => 99.75]);
		self::assertSame(['ts' => 1_700_000_000_000_000_000, 'values' => [0 => 100.5, 2 => 99.75]], TickCodec::decodeLine(TickCodec::encodeLine($m)));
		self::assertSame(['ts' => 1_700_000_000_000_000_000, 'values' => [0 => 100.5, 2 => 99.75]], TickCodec::decodeLine(TickCodec::encodeCsv($m)));
		self::assertSame(['ts' => 5, 'values' => [0 => 1.5, 2 => 3.0]], TickCodec::decodeLine('5,1.5,,3'));
		self::assertSame([0 => 100.5, 2 => 99.75], TickCodec::decodeMeasurement('{"ts":"7","values":{"2":99.75,"0":100.5}}')->values);
	}
}
