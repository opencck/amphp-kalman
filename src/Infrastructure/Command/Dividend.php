<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Command;

use OpenCCK\Kalman\Domain\Filter\KalmanFilter;

/**
 * Known cash event (dividend, coupon): the price level drops by the amount
 * on the ex-date, x_i ← x_i − amount_i. Covariance unchanged.
 */
final readonly class Dividend implements CorporateAction
{
	/** @param array<int, float> $amounts state index => amount subtracted */
	public function __construct(public array $amounts)
	{
	}

	public function applyTo(KalmanFilter $filter): KalmanFilter
	{
		$shifts = [];
		foreach ($this->amounts as $i => $a) {
			$shifts[$i] = -$a;
		}
		$filter->shiftState($shifts);
		return $filter;
	}
}
