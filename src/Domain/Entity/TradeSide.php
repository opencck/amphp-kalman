<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

/**
 * Which side initiated a trade. `Unknown` is a first-class case: most public
 * tapes do not publish the aggressor, and it has to be inferred
 * (`Microstructure\TradeClassifier`, ROADMAP §4.9 M-32) rather than guessed
 * silently as a buy.
 */
enum TradeSide: string
{
	case Buy = 'buy';
	case Sell = 'sell';
	case Unknown = 'unknown';

	/** +1 buyer-initiated, −1 seller-initiated, 0 unknown. */
	public function sign(): float
	{
		return match ($this) {
			self::Buy => 1.0,
			self::Sell => -1.0,
			self::Unknown => 0.0,
		};
	}

	public static function fromSign(float $sign): self
	{
		if ($sign > 0.0) {
			return self::Buy;
		}
		return $sign < 0.0 ? self::Sell : self::Unknown;
	}
}
