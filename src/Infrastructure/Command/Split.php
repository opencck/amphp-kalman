<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Command;

use OpenCCK\Kalman\Domain\Filter\KalmanFilter;

/**
 * Stock split / redenomination: x_i ← f_i·x_i and P scaled accordingly.
 * For a 2:1 split on price index i and velocity index v: [i => 0.5, v => 0.5].
 */
final readonly class Split implements CorporateAction
{
	/** @param array<int, float> $factors state index => factor */
	public function __construct(public array $factors)
	{
	}

	public function applyTo(KalmanFilter $filter): KalmanFilter
	{
		$filter->scaleState($this->factors);
		return $filter;
	}
}
