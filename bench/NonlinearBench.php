<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Bench\Support\DenseModel;
use OpenCCK\Kalman\Bench\Support\Timer;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Filter\ExtendedKalmanFilter;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Filter\UnscentedKalmanFilter;
use OpenCCK\Kalman\Domain\Model\Generic\LinearMotionAdapter;
use OpenCCK\Kalman\Domain\Model\Observation\LinearObservationAdapter;

/**
 * §8 Phase 7 DoD: UKF ≤ 3× the cost of the KF at the same n. Same linear
 * model through KF, EKF (Jacobian path) and UKF (sigma points), n ∈ {2, 4, 8}.
 */
final class NonlinearBench implements Benchmark
{
	public function name(): string
	{
		return 'nonlinear';
	}

	public function run(): array
	{
		$out = [];
		foreach ([2, 4, 8] as $n) {
			$m = \max(1, \intdiv($n, 2));
			$model = new DenseModel($n);
			$obs = $model->observation($m);
			$x0 = \array_fill(0, $n, 0.0);
			$P0 = $model->initialCovariance();
			$cfg = FilterConfig::default()->withMatrixCache(false);
			$values = [];
			for ($c = 0; $c < $m; $c++) {
				$values[$c] = 0.1 * $c;
			}
			$iterations = (int) \max(500, 200_000 / ($n * $n));

			$kf = new KalmanFilter($model, $obs, $x0, $P0, $cfg);
			$ekf = new ExtendedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg);
			$ukf = new UnscentedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg);

			$out["kf_n$n"] = $kfUs = Timer::microsPerOp(static function (int $it) use ($kf, $values): void {
				$ts = $kf->lastTimestampNs() ?? 0;
				for ($k = 0; $k < $it; $k++) {
					$ts += 100_000_000;
					$kf->step(Measurement::at($ts, $values));
				}
			}, $iterations);
			$out["ekf_n$n"] = Timer::microsPerOp(static function (int $it) use ($ekf, $values): void {
				$ts = $ekf->lastTimestampNs() ?? 0;
				for ($k = 0; $k < $it; $k++) {
					$ts += 100_000_000;
					$ekf->step(Measurement::at($ts, $values));
				}
			}, $iterations);
			$out["ukf_n$n"] = $ukfUs = Timer::microsPerOp(static function (int $it) use ($ukf, $values): void {
				$ts = $ukf->lastTimestampNs() ?? 0;
				for ($k = 0; $k < $it; $k++) {
					$ts += 100_000_000;
					$ukf->step(Measurement::at($ts, $values));
				}
			}, $iterations);
			$out["ukf_over_kf_n$n"] = $ukfUs / $kfUs;
		}
		return $out;
	}
}
