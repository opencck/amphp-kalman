<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Command;

use OpenCCK\Kalman\Domain\Filter\KalmanFilter;

/**
 * A discrete, known change of the estimated quantity (split, dividend,
 * basket rebalance). Applied by the owner fiber between two ticks. May
 * return a NEW filter instance when the observation model has to change
 * (rebalance) — the session adopts the returned filter.
 */
interface CorporateAction extends Command
{
	public function applyTo(KalmanFilter $filter): KalmanFilter;
}
