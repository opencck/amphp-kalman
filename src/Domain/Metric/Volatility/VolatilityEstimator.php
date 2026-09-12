<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Volatility;

/**
 * The five OHLC variance estimators (ROADMAP §4.6 M-22), with what each one
 * assumes and what it buys.
 *
 * Their efficiencies are relative to close-to-close on the same number of
 * bars: roughly 5× for Parkinson, 7.4× for Garman–Klass, 8× for
 * Rogers–Satchell. "Efficiency" here means the variance of the estimator
 * itself, so 7× efficiency is the same accuracy from one seventh of the
 * history — the difference between a risk limit that reacts within the hour
 * and one that reacts the next day.
 *
 * These are the published figures under the assumptions each estimator was
 * derived with, and `efficiency()` returns them as stated constants. What a
 * given market delivers depends on whether those assumptions hold: Yang–Zhang's
 * 14× comes largely from handling the gap between one bar's close and the next
 * bar's open, so on a continuously traded instrument, where there is no gap, it
 * measures closer to the others — `examples/volatility-ohlc.php` shows about
 * 6.6× on gapless synthetic bars. Treat the constant as a ranking, not as a
 * promise.
 *
 * The catch is what each assumes:
 *
 *  - `CloseToClose` assumes nothing, and throws away the intrabar path.
 *  - `Parkinson` uses only the range and assumes zero drift; under a trend it
 *    reads high, because a directional move inflates the range.
 *  - `GarmanKlass` adds the open-to-close term, and still assumes zero drift.
 *  - `RogersSatchell` is drift-independent by construction — the one to use
 *    on a trending instrument.
 *  - `YangZhang` combines overnight, open-to-close and Rogers–Satchell terms;
 *    it handles gaps and has the smallest variance of the five, at the cost of
 *    needing a longer window to estimate its own components.
 */
enum VolatilityEstimator: string
{
	case CloseToClose = 'close-to-close';
	case Parkinson = 'parkinson';
	case GarmanKlass = 'garman-klass';
	case RogersSatchell = 'rogers-satchell';
	case YangZhang = 'yang-zhang';

	/** Approximate efficiency relative to close-to-close on the same bars. */
	public function efficiency(): float
	{
		return match ($this) {
			self::CloseToClose => 1.0,
			self::Parkinson => 5.2,
			self::GarmanKlass => 7.4,
			self::RogersSatchell => 8.0,
			self::YangZhang => 14.0,
		};
	}

	/** Whether the estimator is unbiased in the presence of a drift. */
	public function isDriftIndependent(): bool
	{
		return $this === self::RogersSatchell || $this === self::YangZhang || $this === self::CloseToClose;
	}

	/** Whether it accounts for the gap between one bar's close and the next bar's open. */
	public function handlesGaps(): bool
	{
		return $this === self::YangZhang || $this === self::CloseToClose;
	}
}
