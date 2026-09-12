<?php declare(strict_types=1);

namespace OpenCCK\Kalman\App\Backtest;

use OpenCCK\Kalman\Domain\Diagnostics\ConsistencyMonitor;
use OpenCCK\Kalman\Domain\Diagnostics\Nees;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Smoothing\FilterTrajectory;
use OpenCCK\Kalman\Domain\Smoothing\RauchTungStriebel;

/**
 * §8 Phase 6 backtest engine: ticks → filter (recording the trajectory) →
 * RTS smoother → Report. Pure application logic: the caller streams ticks
 * from a file with Infrastructure\Ingest\HistoryReader (or anything else).
 *
 * Truth (optional) is supplied per tick as `truth` => state vector to get
 * NEES, RMSE of filter vs smoother and the filter's lag.
 */
final class Engine
{
	public function __construct(
		private readonly KalmanFilter $filter,
		private readonly bool $smooth = true,
		private readonly int $trajectoryCapacity = 4096,
	) {
	}

	/**
	 * @param iterable<array{ts: int, values: array<int, float>, truth?: array<int, float>}> $ticks
	 * @return array{report: Report, trajectory: FilterTrajectory, smoothed: array{mean: array<int, array<int, float>>, covariance: array<int, array<int, float>>}|null}
	 */
	public function run(iterable $ticks): array
	{
		$filter = $this->filter;
		$n = $filter->stateSize();
		$trajectory = new FilterTrajectory($n, $this->trajectoryCapacity);
		$filter->setRecorder($trajectory);
		$monitor = new ConsistencyMonitor($filter->channelCount());

		$t0 = \hrtime(true);
		$truths = [];
		$neesSum = 0.0;
		$neesCount = 0;
		$filterSq = 0.0;
		$steps = 0;
		try {
			foreach ($ticks as $tick) {
				$result = $filter->step(Measurement::at($tick['ts'], $tick['values']));
				$monitor->record($result);
				if (isset($tick['truth'])) {
					$truth = $tick['truth'];
					if (\count($truth) !== $n) {
						throw new InvalidArgument('truth must have n entries');
					}
					$truths[$steps] = $truth;
					$neesSum += Nees::compute($truth, $filter->mean(), $filter->covariance(), $n);
					$neesCount++;
					$filterSq += self::squaredError($truth, $filter->mean());
				}
				$steps++;
			}
		} finally {
			$filter->setRecorder(null);
		}
		if ($steps === 0) {
			throw new InvalidArgument('No ticks');
		}

		$smoothed = null;
		$smootherRmse = null;
		$lag = null;
		if ($this->smooth) {
			$smoothed = RauchTungStriebel::smooth($trajectory, $filter->motion());
			if ($truths !== []) {
				$sq = 0.0;
				foreach ($truths as $k => $truth) {
					$sq += self::squaredError($truth, $smoothed['mean'][$k]);
				}
				$smootherRmse = \sqrt($sq / \count($truths));
				$lag = self::estimateLag($trajectory, $smoothed['mean']);
			}
		}

		$nisPerChannel = [];
		$rejPerChannel = [];
		for ($c = 0; $c < $filter->channelCount(); $c++) {
			$nisPerChannel[$c] = $monitor->channel($c)->meanNis();
			$rejPerChannel[$c] = $monitor->channel($c)->rejectionRate();
		}
		$report = new Report(
			steps: $steps,
			logLikelihood: $filter->logLikelihood(),
			meanNis: $monitor->meanNis(),
			nisPerChannel: $nisPerChannel,
			rejectionRatePerChannel: $rejPerChannel,
			meanNees: $neesCount > 0 ? $neesSum / $neesCount : null,
			filterRmse: $neesCount > 0 ? \sqrt($filterSq / $neesCount) : null,
			smootherRmse: $smootherRmse,
			filterLagSteps: $lag,
			seconds: (\hrtime(true) - $t0) / 1e9,
			extra: ['modelBreakEvents' => $monitor->modelBreakEvents(), 'trajectoryBytes' => $trajectory->bytes()],
		);
		return ['report' => $report, 'trajectory' => $trajectory, 'smoothed' => $smoothed === null ? null : ['mean' => $smoothed['mean'], 'covariance' => $smoothed['covariance']]];
	}

	/**
	 * @param array<int, float> $a
	 * @param array<int, float> $b
	 */
	private static function squaredError(array $a, array $b): float
	{
		$s = 0.0;
		foreach ($a as $i => $v) {
			$d = $v - $b[$i];
			$s += $d * $d;
		}
		return $s;
	}

	/**
	 * Filter lag in steps: the shift l that minimises Σ‖x̂_{k|k} − x̂_{k−l|N}‖²
	 * for the first state component (how far behind the smoothed path the
	 * filtered path runs), searched over 0..20.
	 *
	 * @param array<int, array<int, float>> $smoothed
	 */
	private static function estimateLag(FilterTrajectory $trajectory, array $smoothed): ?float
	{
		$N = $trajectory->count();
		if ($N < 50) {
			return null;
		}
		$best = 0;
		$bestErr = \INF;
		for ($l = 0; $l <= 20; $l++) {
			$err = 0.0;
			$count = 0;
			for ($k = $l + 1; $k < $N; $k++) {
				$d = $trajectory->posteriorMean($k)[0] - $smoothed[$k - $l][0];
				$err += $d * $d;
				$count++;
			}
			$err /= \max(1, $count);
			if ($err < $bestErr) {
				$bestErr = $err;
				$best = $l;
			}
		}
		return (float) $best;
	}
}
