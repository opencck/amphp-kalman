<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Contract;

use OpenCCK\Kalman\Domain\Entity\Bar;

/**
 * A metric that needs the intrabar range: ATR, ADX, CCI, the stochastic
 * oscillator and every range-based volatility estimator.
 *
 * Bars come from `Infrastructure\Ingest\BarAggregator` (time, volume or tick
 * bars) or from a history file.
 */
interface BarMetric extends Metric
{
	public function updateBar(Bar $bar): void;
}
