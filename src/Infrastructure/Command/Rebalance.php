<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Command;

use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;

/**
 * Basket / index rebalance: the observation matrix changes (new weights) and
 * the uncertainty of the affected components is inflated. Produces a new
 * KalmanFilter on the same motion model, state and configuration.
 */
final readonly class Rebalance implements CorporateAction
{
	/**
	 * @param array<int, float> $addedVariance state index => variance added to P_ii
	 */
	public function __construct(
		public ObservationModel $observation,
		public array $addedVariance = [],
	) {
	}

	public function applyTo(KalmanFilter $filter): KalmanFilter
	{
		$snapshot = $filter->snapshot();
		$next = KalmanFilter::fromSnapshot($filter->motion(), $this->observation, $snapshot, $filter->config());
		if ($this->addedVariance !== []) {
			$next->addVariance($this->addedVariance);
		}
		return $next;
	}
}
