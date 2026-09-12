<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Cross;

/**
 * How a basket divides its weight between constituents (ROADMAP §4.7 M-24).
 *
 *  - `Equal`             w_i = 1/N;
 *  - `Volume`            w_i ∝ mean traded size of i over the window;
 *  - `InverseVolatility` w_i ∝ 1/σ_i, σ_i realised over the window.
 *
 * The three answer different questions and are not interchangeable. Equal
 * weights measure the average constituent; they are the reference's choice and
 * they hand the most influence to the smallest and noisiest names, because a
 * 1/N weight on an asset with three times the volatility contributes three
 * times the variance. Volume weights measure where the money actually traded —
 * the closest thing to a capitalisation proxy available from a tape, and the
 * one that tracks what a liquidity-constrained book could really hold.
 * Inverse-volatility weights equalise the risk contribution of each
 * constituent instead of its notional, which is what "the market factor"
 * usually means in a risk model.
 *
 * The value of the enum is the string used in serialised configs and in the
 * offloadable kernels, which may only take scalars across a process boundary
 * (ADR-005) and therefore take the weighting by name rather than by case.
 */
enum BasketWeighting: string
{
	case Equal = 'equal';
	case Volume = 'volume';
	case InverseVolatility = 'inverse-volatility';

	/** True when the mode needs a traded size next to every price. */
	public function needsSizes(): bool
	{
		return $this === self::Volume;
	}

	/** True when the mode needs a volatility estimate per constituent. */
	public function needsVolatility(): bool
	{
		return $this === self::InverseVolatility;
	}

	public function labelEn(): string
	{
		return match ($this) {
			self::Equal => 'equal weights',
			self::Volume => 'volume weighted',
			self::InverseVolatility => 'inverse volatility',
		};
	}

	public function labelRu(): string
	{
		return match ($this) {
			self::Equal => 'равные веса',
			self::Volume => 'по объёму',
			self::InverseVolatility => 'по обратной волатильности',
		};
	}
}
