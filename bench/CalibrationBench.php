<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use OpenCCK\Kalman\App\Calibration\Calibrator;
use OpenCCK\Kalman\App\Calibration\MultiStartNelderMead;
use OpenCCK\Kalman\App\Calibration\NelderMead;
use OpenCCK\Kalman\App\Calibration\Parametrization;
use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Infrastructure\Task\WorkerPools;
use OpenCCK\Kalman\Infrastructure\Ingest\HistoryReader;
use OpenCCK\Kalman\Infrastructure\Task\ParallelCalibrator;
use function Amp\async;

/**
 * §6.6 CalibrationBench: MLE of (σ_a, r) for an LLT on a long history —
 * sequential Calibrator vs ParallelCalibrator on the available cores, single
 * start (4 points per iteration) and 4 starts in lockstep (16 points).
 * History length is 1M ticks in the original plan; KALMAN_BENCH_TICKS overrides
 * (default 200k so the bench finishes in a couple of minutes).
 */
final class CalibrationBench implements Benchmark
{
	public function name(): string
	{
		return 'calibration';
	}

	public function run(): array
	{
		$env = \getenv('KALMAN_BENCH_TICKS');
		$n = \is_string($env) && \ctype_digit($env) ? (int) $env : 200_000;
		$config = [
			'motion' => ['type' => 'constant-velocity', 'sigmaA' => 1.5],
			'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [0.01]],
			'x0' => [100.0, 0.0],
			'P0' => [0.01, 0.0, 0.0, 1.0],
		];
		$ticks = [];
		$p = 100.0;
		$v = 0.0;
		$seed = 12345;
		for ($k = 1; $k <= $n; $k++) {
			// deterministic LCG noise, no RNG dependency in bench code
			$seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
			$u1 = ($seed % 100000) / 100000.0 + 1e-6;
			$seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
			$u2 = ($seed % 100000) / 100000.0;
			$g = \sqrt(-2.0 * \log($u1)) * \cos(2.0 * \M_PI * $u2);
			$v += 0.5 * \sqrt(0.1) * $g;
			$p += 0.1 * $v;
			$ticks[] = ['ts' => $k * 100_000_000, 'values' => [0 => $p + 0.05 * $g]];
		}
		$param = new Parametrization(['motion.sigmaA' => 'log', 'observation.variances.0' => 'log']);
		$optimizer = new NelderMead(tolerance: 1e-4, maxIterations: 30);

		$out = [];
		$t0 = \hrtime(true);
		$seq = (new Calibrator($param, $optimizer))->calibrate($config, null, $ticks);
		$out['sequential_s'] = (\hrtime(true) - $t0) / 1e9;
		$out['evaluations'] = (float) $seq['evaluations'];

		$workers = \min(8, self::cores());
		$path = \sys_get_temp_dir() . '/kalman-calibration-bench.jsonl';
		HistoryReader::write($path, $ticks);
		/** @var float $par */
		$par = async(static function () use ($param, $optimizer, $config, $path, $workers): float {
			$pool = WorkerPools::likeParent($workers);
			$t0 = \hrtime(true);
			(new ParallelCalibrator($param, $pool, $optimizer))->calibrate($config, null, $path);
			$elapsed = (\hrtime(true) - $t0) / 1e9;
			$pool->shutdown();
			return $elapsed;
		})->await();
		$out["parallel_{$workers}w_s"] = $par;
		$out['speedup'] = $out['sequential_s'] / $par;

		// 4 simplexes in lockstep: 16 candidate points per iteration keep 8 workers busy;
		// compared per evaluation against the sequential single-start run
		$starts = 4;
		$multi = new MultiStartNelderMead($optimizer, starts: $starts, spread: 1.0);
		/** @var array{0: float, 1: int} $ms */
		$ms = async(static function () use ($param, $multi, $config, $path, $workers): array {
			$pool = WorkerPools::likeParent($workers);
			$t0 = \hrtime(true);
			$r = (new ParallelCalibrator($param, $pool, $multi))->calibrate($config, null, $path);
			$elapsed = (\hrtime(true) - $t0) / 1e9;
			$pool->shutdown();
			return [$elapsed, $r['evaluations']];
		})->await();
		$out["parallel_{$workers}w_multistart{$starts}_s"] = $ms[0];
		$out["multistart{$starts}_evaluations"] = (float) $ms[1];
		// evaluations per second relative to the sequential run: the true parallel efficiency
		$out["multistart{$starts}_throughput_speedup"] = ($ms[1] / $ms[0]) / ($seq['evaluations'] / $out['sequential_s']);
		$out['ticks'] = (float) $n;
		return $out;
	}

	private static function cores(): int
	{
		$env = \getenv('NUMBER_OF_PROCESSORS');
		if (\is_string($env) && \ctype_digit($env)) {
			return (int) $env;
		}
		return 4;
	}
}
