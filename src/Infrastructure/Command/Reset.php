<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Command;

use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;

/**
 * Replaces the filter state from a snapshot (restart after a model break,
 * restore from persistence while running).
 */
final readonly class Reset implements CorporateAction
{
	public function __construct(public StateSnapshot $snapshot)
	{
	}

	public function applyTo(KalmanFilter $filter): KalmanFilter
	{
		$filter->reset($this->snapshot->mean, $this->snapshot->covariance, $this->snapshot->timestampNs);
		return $filter;
	}
}
