<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Bench\Support\DenseModel;
use OpenCCK\Kalman\Bench\Support\Timer;
use OpenCCK\Kalman\Domain\Entity\Backend;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Ffi\BlasBackend;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;

/**
 * §6.6 FormBench: full step (predict + correct of m channels) per covariance
 * form, dense model, n ∈ {4, 8, 16, 32}, m = n/2; plus the sparse LLT (n=2, m=1)
 * which is the §1.3 "n=2 ≤ 3 µs" target. Where OpenBLAS is available the
 * Sequential form is also measured with Backend::Blas for n ∈ {8, 16, 32, 64, 128}
 * (and pure PHP at n = 64 / 128 for the crossover, ADR-006).
 */
final class FormBench implements Benchmark
{
	public function name(): string
	{
		return 'form';
	}

	public function run(): array
	{
		$out = [];
		foreach ([4, 8, 16, 32] as $n) {
			$m = \intdiv($n, 2);
			foreach (FilterForm::cases() as $form) {
				$out["{$form->value}_n{$n}_m{$m}"] = self::stepMicros($form, $n, $m);
			}
		}
		foreach (FilterForm::cases() as $form) {
			$out["{$form->value}_llt_n2_m1"] = self::lltMicros($form);
		}
		if (BlasBackend::isAvailable()) {
			foreach ([8, 16, 32, 64, 128] as $n) {
				$m = \intdiv($n, 2);
				$out["blas_n{$n}_m{$m}"] = self::stepMicros(FilterForm::Sequential, $n, $m, Backend::Blas);
				if ($n >= 64) {
					$out["sequential_n{$n}_m{$m}"] = self::stepMicros(FilterForm::Sequential, $n, $m);
				}
			}
		}
		return $out;
	}

	private static function stepMicros(FilterForm $form, int $n, int $m, Backend $backend = Backend::Php): float
	{
		$model = new DenseModel($n);
		$obs = $model->observation($m);
		$x0 = \array_fill(0, $n, 0.0);
		$filter = new KalmanFilter($model, $obs, $x0, $model->initialCovariance(), FilterConfig::default()->withForm($form)->withMatrixCache(true)->withBackend($backend));
		$values = [];
		for ($c = 0; $c < $m; $c++) {
			$values[$c] = 0.1 * $c;
		}
		$iterations = (int) \max(20, 400_000 / ($n * $n));
		return Timer::microsPerOp(static function (int $it) use ($filter, $values): void {
			$ts = $filter->lastTimestampNs() ?? 0;
			for ($k = 0; $k < $it; $k++) {
				$ts += 100_000_000;
				$filter->stepRaw($ts, $values);
			}
		}, $iterations);
	}

	private static function lltMicros(FilterForm $form): float
	{
		$llt = new LocalLinearTrend(0.5, 0.05, 0.01);
		$filter = $llt->filter(100.0, FilterConfig::default()->withForm($form));
		return Timer::microsPerOp(static function (int $it) use ($filter): void {
			$ts = $filter->lastTimestampNs() ?? 0;
			$values = [0 => 100.0];
			for ($k = 0; $k < $it; $k++) {
				$ts += 100_000_000;
				$values[0] = 100.0 + 0.001 * ($k % 100);
				$filter->stepRaw($ts, $values);
			}
		}, 200_000);
	}
}
