<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * One execution from the tape.
 *
 * The tape is a different input from the price stream: it carries size and
 * aggressor side, which no sampled gauge can reconstruct. Every flow metric
 * (VPIN, Kyle's lambda, order-flow imbalance, volume delta) is defined on
 * trades, not on price samples — see the ROADMAP note on summing a volume
 * gauge over time (§4.4 M-14).
 */
final readonly class Trade
{
	private function __construct(
		public int $timestampNs,
		public float $price,
		public float $size,
		public TradeSide $side,
	) {
	}

	public static function at(int $timestampNs, float $price, float $size, TradeSide $side = TradeSide::Unknown): self
	{
		if (!\is_finite($price) || $price <= 0.0) {
			throw new InvalidArgument(\sprintf('Trade price must be finite and > 0, got %s', (string) $price));
		}
		if (!\is_finite($size) || $size < 0.0) {
			throw new InvalidArgument(\sprintf('Trade size must be finite and >= 0, got %s', (string) $size));
		}
		return new self($timestampNs, $price, $size, $side);
	}

	/** Traded value: price × size. The unit every liquidity measure normalises by. */
	public function notional(): float
	{
		return $this->price * $this->size;
	}

	/** Size with the aggressor's sign: +size bought, −size sold, 0 unknown. */
	public function signedSize(): float
	{
		return $this->size * $this->side->sign();
	}

	public function withSide(TradeSide $side): self
	{
		return new self($this->timestampNs, $this->price, $this->size, $side);
	}

	/** @return array{ts: int, price: float, size: float, side: string} */
	public function toArray(): array
	{
		return ['ts' => $this->timestampNs, 'price' => $this->price, 'size' => $this->size, 'side' => $this->side->value];
	}

	/** @param array{ts: int, price: float|int, size: float|int, side?: string} $data */
	public static function fromArray(array $data): self
	{
		return self::at(
			$data['ts'],
			(float) $data['price'],
			(float) $data['size'],
			TradeSide::from($data['side'] ?? 'unknown'),
		);
	}
}
