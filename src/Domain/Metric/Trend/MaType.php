<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Trend;

/**
 * The four smoothings the catalogue is built on. They differ only in how the
 * weight decays, but the difference decides what an indicator means:
 *
 *  - `Sma`  equal weights, hard cut-off at N;
 *  - `Ema`  α = 2/(N+1), centre of mass (N−1)/2 — matches the SMA's;
 *  - `Rma`  α = 1/N (Wilder), centre of mass N−1, i.e. an EMA of period 2N−1;
 *  - `Wma`  linearly rising weights, centre of mass (N−1)/3.
 *
 * Substituting one for another is the single most common way to get a
 * standard indicator wrong: the reference RSI in the ROADMAP uses a simple
 * average where Wilder's definition uses `Rma`, and its values differ by
 * several points for many bars after any spike (§4.2 M-05).
 */
enum MaType: string
{
	case Sma = 'sma';
	case Ema = 'ema';
	case Rma = 'rma';
	case Wma = 'wma';

	/** Smoothing factor for the recursive forms; NAN for the windowed ones. */
	public function alpha(int $period): float
	{
		return match ($this) {
			self::Ema => 2.0 / ($period + 1),
			self::Rma => 1.0 / $period,
			self::Sma, self::Wma => \NAN,
		};
	}

	/**
	 * Centre of mass in observations — the honest way to compare two
	 * smoothings of different families, and the right way to translate a
	 * window length between them.
	 */
	public function centreOfMass(int $period): float
	{
		return match ($this) {
			self::Sma => ($period - 1) / 2.0,
			self::Ema => ($period - 1) / 2.0,
			self::Rma => (float) ($period - 1),
			self::Wma => ($period - 1) / 3.0,
		};
	}
}
