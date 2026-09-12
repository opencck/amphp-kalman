<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Bench\Support\DenseModel;
use OpenCCK\Kalman\Bench\Support\Timer;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Filter\AlphaBetaFilter;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;

/**
 * §6.6 KernelBench: generated loop-free kernels (n = 2, 4) vs the generic
 * DenseSequential loops, on a full step; plus the alpha-beta filter
 * (§8 Phase 3 DoD: ≤ 1 µs per step).
 */
final class KernelBench implements Benchmark
{
	public function name(): string
	{
		return 'kernel';
	}

	public function run(): array
	{
		$out = [];
		foreach ([2, 4] as $n) {
			$m = \max(1, \intdiv($n, 2));
			$out["generic_n$n"] = self::denseStep($n, $m, false);
			$out["unrolled_n$n"] = self::denseStep($n, $m, true);
		}
		$out['llt_generic_n2'] = self::llt(false);
		$out['llt_unrolled_n2'] = self::llt(true);
		$out['alpha_beta'] = self::alphaBeta();
		return $out;
	}

	private static function denseStep(int $n, int $m, bool $unrolled): float
	{
		$model = new DenseModel($n);
		$filter = new KalmanFilter($model, $model->observation($m), \array_fill(0, $n, 0.0), $model->initialCovariance(), FilterConfig::default()->withUnrolledKernels($unrolled));
		$values = [];
		for ($c = 0; $c < $m; $c++) {
			$values[$c] = 0.1 * $c;
		}
		return Timer::microsPerOp(static function (int $it) use ($filter, $values): void {
			$ts = $filter->lastTimestampNs() ?? 0;
			for ($k = 0; $k < $it; $k++) {
				$ts += 100_000_000;
				$filter->stepRaw($ts, $values);
			}
		}, 200_000);
	}

	private static function llt(bool $unrolled): float
	{
		$filter = (new LocalLinearTrend(0.5, 0.05, 0.01))->filter(100.0, FilterConfig::default()->withUnrolledKernels($unrolled));
		return Timer::microsPerOp(static function (int $it) use ($filter): void {
			$ts = $filter->lastTimestampNs() ?? 0;
			$values = [0 => 100.0];
			for ($k = 0; $k < $it; $k++) {
				$ts += 100_000_000;
				$values[0] = 100.0 + 0.001 * ($k % 100);
				$filter->stepRaw($ts, $values);
			}
		}, 300_000);
	}

	private static function alphaBeta(): float
	{
		$ab = new AlphaBetaFilter(0.5, 0.05, 0.1, 100.0);
		return Timer::microsPerOp(static function (int $it) use ($ab): void {
			for ($k = 0; $k < $it; $k++) {
				$ab->step(100.0 + 0.001 * ($k % 100));
			}
		}, 1_000_000);
	}
}
