<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Bench\Support\DenseModel;
use OpenCCK\Kalman\Bench\Support\Timer;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Filter\AlphaBetaFilter;
use OpenCCK\Kalman\Domain\Filter\ExtendedKalmanFilter;
use OpenCCK\Kalman\Domain\Filter\InformationFilter;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Filter\SteadyStateFilter;
use OpenCCK\Kalman\Domain\Filter\UnscentedKalmanFilter;
use OpenCCK\Kalman\Domain\Model\Generic\LinearMotionAdapter;
use OpenCCK\Kalman\Domain\Model\Observation\LinearObservationAdapter;

/**
 * §6.6 AllocBench: memory_get_usage(false) delta over 100 000 stepRaw() calls
 * per form and per filter type (§8: "stepRaw in every filter; AllocBench = 0").
 * Must be 0 bytes (§1.3). Metric unit: bytes.
 */
final class AllocBench implements Benchmark
{
	private const STEPS = 100_000;

	public function name(): string
	{
		return 'alloc';
	}

	public function run(): array
	{
		$out = [];
		foreach ([2, 8] as $n) {
			$m = \max(1, \intdiv($n, 2));
			foreach (FilterForm::cases() as $form) {
				$model = new DenseModel($n);
				$filter = new KalmanFilter($model, $model->observation($m), \array_fill(0, $n, 0.0), $model->initialCovariance(), FilterConfig::default()->withForm($form));
				$out["{$form->value}_n{$n}_bytes"] = self::measure(static fn (int $ts, array $values) => $filter->stepRaw($ts, $values), $m);
			}
		}

		$n = 4;
		$m = 2;
		$model = new DenseModel($n);
		$obs = $model->observation($m);
		$x0 = \array_fill(0, $n, 0.0);
		$P0 = $model->initialCovariance();
		$cfg = FilterConfig::default();

		$ekf = new ExtendedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg);
		$out["ekf_n{$n}_bytes"] = self::measure(static fn (int $ts, array $values) => $ekf->stepRaw($ts, $values), $m);

		$ukf = new UnscentedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg, alpha: 1.0, kappa: 3.0 - $n);
		$out["ukf_n{$n}_bytes"] = self::measure(static fn (int $ts, array $values) => $ukf->stepRaw($ts, $values), $m, 20_000);

		$info = InformationFilter::fromCovariance($model, $obs, $x0, $P0);
		$out["information_n{$n}_bytes"] = self::measure(static fn (int $ts, array $values) => $info->stepRaw($ts, $values), $m, 20_000);

		$steady = SteadyStateFilter::fromModels($model, $obs, 0.1, $x0);
		$out["steady_state_n{$n}_bytes"] = self::measure(static fn (int $ts, array $values) => $steady->step($values), $m);

		$ab = AlphaBetaFilter::forConstantVelocity(0.5, 0.1, 0.1);
		$out['alpha_beta_bytes'] = self::measure(static fn (int $ts, array $values) => $ab->step($values[0]), 1);

		return $out;
	}

	/**
	 * @param callable(int, array<int, float>): void $step
	 */
	private static function measure(callable $step, int $m, int $steps = self::STEPS): float
	{
		$values = [];
		for ($c = 0; $c < $m; $c++) {
			$values[$c] = 0.1 * $c;
		}
		// warm up: fills the dt cache and any lazily-sized buffers
		$ts = 0;
		for ($k = 0; $k < 1000; $k++) {
			$ts += 100_000_000;
			$step($ts, $values);
		}
		$delta = Timer::allocationDelta(static function () use ($step, $values, &$ts, $steps): void {
			for ($k = 0; $k < $steps; $k++) {
				$ts += 100_000_000;
				$step($ts, $values);
			}
		});
		return (float) $delta;
	}
}
