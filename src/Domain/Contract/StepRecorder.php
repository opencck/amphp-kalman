<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Receives the prior (after predict) and posterior (after correct) state of
 * every filter step — the input of RTS smoothing, EM and backtest reports.
 * Attached to a KalmanFilter with setRecorder(); costs a dense copy of P per
 * call, so it is meant for backtests and calibration, not the live hot path.
 */
interface StepRecorder
{
	/**
	 * @param array<int, float> $xPrior
	 * @param array<int, float> $PPrior n² row-major
	 */
	public function recordPrior(float $dt, array $xPrior, array $PPrior): void;

	/**
	 * @param array<int, float> $xPost
	 * @param array<int, float> $PPost n² row-major
	 */
	public function recordPosterior(array $xPost, array $PPost, ?int $timestampNs): void;
}
