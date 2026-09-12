<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use OpenCCK\Kalman\App\Calibration\InnovationLikelihood;
use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Infrastructure\Task\WorkerPools;
use OpenCCK\Kalman\Infrastructure\Ingest\HistoryReader;
use OpenCCK\Kalman\Infrastructure\Task\LikelihoodTask;
use function Amp\async;
use function Amp\Future\await;

/**
 * §6.6 ScalingBench: 32 independent likelihood evaluations over a 100k-tick
 * LLT history (workers read the file once and cache it) on 1, 2, 4, 8 workers
 * vs sequential. Reports wall time and speed-up. Target §1.3: ≥ 6.5× on 8
 * workers (hardware permitting).
 */
final class ScalingBench implements Benchmark
{
	private const TICKS = 100_000;
	private const JOBS = 32;

	public function name(): string
	{
		return 'scaling';
	}

	public function run(): array
	{
		$config = [
			'motion' => ['type' => 'constant-velocity', 'sigmaA' => 0.5],
			'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [0.0025]],
			'x0' => [100.0, 0.0],
			'P0' => [0.01, 0.0, 0.0, 1.0],
		];
		$param = ['motion.sigmaA' => 'log', 'observation.variances.0' => 'log'];
		$ticks = [];
		for ($k = 1; $k <= self::TICKS; $k++) {
			$ticks[] = ['ts' => $k * 100_000_000, 'values' => [0 => 100.0 + 0.01 * \sin($k / 50.0)]];
		}
		$thetas = [];
		for ($j = 0; $j < self::JOBS; $j++) {
			$thetas[] = [\log(0.3 + 0.1 * $j), \log(0.002 + 0.0005 * $j)];
		}
		// workers read the history themselves (path, not data — §5.10)
		$path = \sys_get_temp_dir() . '/kalman-scaling-bench.jsonl';
		HistoryReader::write($path, $ticks);

		$out = [];
		$t0 = \hrtime(true);
		foreach ($thetas as $theta) {
			InnovationLikelihood::evaluateTheta($config, null, $param, $theta, $ticks);
		}
		$sequential = (\hrtime(true) - $t0) / 1e9;
		$out['sequential_s'] = $sequential;

		$cores = self::cores();
		foreach ([1, 2, 4, 8] as $workers) {
			if ($workers > \max(1, $cores)) {
				continue;
			}
			/** @var float $elapsed */
			$elapsed = async(static function () use ($workers, $config, $param, $thetas, $path): float {
				$pool = WorkerPools::likeParent($workers);
				// warm the pool so process start-up and the one-time history decode per worker are not measured
				$warm = [];
				for ($w = 0; $w < $workers; $w++) {
					$warm[] = $pool->submit(new LikelihoodTask($config, null, $param, [\log(0.5), \log(0.0025)], $path))->getFuture();
				}
				await($warm);
				$t0 = \hrtime(true);
				$futures = [];
				foreach ($thetas as $theta) {
					$futures[] = $pool->submit(new LikelihoodTask($config, null, $param, $theta, $path))->getFuture();
				}
				await($futures);
				$elapsed = (\hrtime(true) - $t0) / 1e9;
				$pool->shutdown();
				return $elapsed;
			})->await();
			$out["workers_{$workers}_s"] = $elapsed;
			$out["workers_{$workers}_speedup"] = $sequential / $elapsed;
		}
		$out['cores_detected'] = (float) $cores;
		return $out;
	}

	private static function cores(): int
	{
		$env = \getenv('NUMBER_OF_PROCESSORS');
		if (\is_string($env) && \ctype_digit($env)) {
			return (int) $env;
		}
		if (\is_file('/proc/cpuinfo')) {
			$info = \file_get_contents('/proc/cpuinfo');
			if ($info !== false) {
				$found = \preg_match_all('/^processor/m', $info);
				return $found === false ? 4 : \max(1, $found);
			}
		}
		return 4;
	}
}
