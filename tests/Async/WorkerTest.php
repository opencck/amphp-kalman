<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\App\Calibration\Calibrator;
use OpenCCK\Kalman\App\Calibration\InnovationLikelihood;
use OpenCCK\Kalman\App\Calibration\NelderMead;
use OpenCCK\Kalman\App\Calibration\Parametrization;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Factory\FilterFactory;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Infrastructure\Task\LikelihoodTask;
use OpenCCK\Kalman\Infrastructure\Task\ParallelCalibrator;
use OpenCCK\Kalman\Infrastructure\Task\WorkerSession;
use OpenCCK\Kalman\Tests\Unit\Calibration\CalibrationTest;
use function Amp\async;

/**
 * §5.13 / Phase 5 DoD: results through worker processes are bit-identical to
 * the in-process computation.
 *
 * @group slow
 */
final class WorkerTest extends AsyncTestCase
{
	private ?ContextWorkerPool $pool = null;

	protected function setUp(): void
	{
		parent::setUp();
		$this->setTimeout(120);
		$this->pool = new ContextWorkerPool(limit: 2);
	}

	protected function tearDown(): void
	{
		$this->pool?->shutdown();
		$this->pool = null;
		parent::tearDown();
	}

	public function testBatchedIpcMatchesInProcess(): void
	{
		$config = CalibrationTest::config(0.5, 0.05);
		$ticks = CalibrationTest::simulate(new LocalLinearTrend(0.5, 0.05), 1000, seed: 61);

		$inProcess = FilterFactory::fromConfig($config, null);
		foreach ($ticks as $t) {
			$inProcess->stepRaw($t['ts'], $t['values']);
		}

		$measurements = [];
		foreach ($ticks as $t) {
			$measurements[] = Measurement::at($t['ts'], $t['values']);
		}
		/** @var Queue<array<string, mixed>> $snapshots */
		$snapshots = new Queue(64);
		$session = new WorkerSession($config, null, $snapshots, batchSize: 128, snapshotEvery: 250, pool: $this->pool);
		$compact = [];
		$collector = async(static function () use ($snapshots, &$compact): void {
			foreach ($snapshots->iterate() as $s) {
				$compact[] = $s;
			}
		});
		$final = $session->start($measurements)->await();
		$collector->await();

		self::assertSame($inProcess->mean(), $final->mean);
		self::assertSame($inProcess->covariance(), $final->covariance);
		self::assertSame($inProcess->logLikelihood(), $final->logLikelihood);
		self::assertSame(1000, $final->steps);
		self::assertCount(4, $compact);
		self::assertSame(4, $session->snapshotsReceived());
		$first = $compact[0] ?? null;
		self::assertIsArray($first);
		self::assertArrayHasKey('diag', $first);
		self::assertSame(250, $first['steps']);
	}

	public function testResumeFromSnapshotInWorker(): void
	{
		$config = CalibrationTest::config(0.5, 0.05);
		$ticks = CalibrationTest::simulate(new LocalLinearTrend(0.5, 0.05), 400, seed: 62);
		$reference = FilterFactory::fromConfig($config, null);
		foreach ($ticks as $t) {
			$reference->stepRaw($t['ts'], $t['values']);
		}
		$half = FilterFactory::fromConfig($config, null);
		foreach (\array_slice($ticks, 0, 200) as $t) {
			$half->stepRaw($t['ts'], $t['values']);
		}
		$rest = [];
		foreach (\array_slice($ticks, 200) as $t) {
			$rest[] = Measurement::at($t['ts'], $t['values']);
		}
		/** @var Queue<array<string, mixed>> $snapshots */
		$snapshots = new Queue(64);
		$session = new WorkerSession($config, null, $snapshots, batchSize: 64, snapshotEvery: 1000, pool: $this->pool);
		$final = $session->start($rest, $half->snapshot()->toArray())->await();
		// the matrix cache is cold in the worker → compare within quantisation error (ADR: FilterBatch note)
		self::assertEqualsWithDelta($reference->meanAt(0), $final->mean[0], 1e-9);
		self::assertSame(400, $final->steps);
	}

	public function testLikelihoodTaskMatchesInProcess(): void
	{
		$config = CalibrationTest::config(0.5, 0.05);
		$ticks = CalibrationTest::simulate(new LocalLinearTrend(0.5, 0.05), 500, seed: 63);
		$param = ['motion.sigmaA' => 'log', 'observation.variances.0' => 'log'];
		$theta = [\log(0.7), \log(0.004)];
		$expected = InnovationLikelihood::evaluateTheta($config, null, $param, $theta, $ticks);
		$pool = $this->pool;
		self::assertNotNull($pool);
		$actual = $pool->submit(new LikelihoodTask($config, null, $param, $theta, null, $ticks))->getFuture()->await();
		self::assertSame($expected, $actual);
	}

	public function testParallelCalibratorMatchesSequentialCalibrator(): void
	{
		$config = CalibrationTest::config(1.5, 0.15);
		$ticks = CalibrationTest::simulate(new LocalLinearTrend(0.5, 0.05), 600, seed: 64);
		$param = new Parametrization(['motion.sigmaA' => 'log', 'observation.variances.0' => 'log']);
		$optimizer = new NelderMead(tolerance: 1e-4, maxIterations: 40);
		$pool = $this->pool;
		self::assertNotNull($pool);

		$sequential = (new Calibrator($param, $optimizer))->calibrate($config, null, $ticks);
		$parallel = (new ParallelCalibrator($param, $pool, $optimizer))->calibrate($config, null, $ticks);

		self::assertSame($sequential['evaluations'], $parallel['evaluations']);
		self::assertSame($sequential['theta'], $parallel['theta']);
		self::assertSame($sequential['logLikelihood'], $parallel['logLikelihood']);
	}
}
