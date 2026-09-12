<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Support;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Turns a tick or trade stream into OHLCV bars on a time, volume or tick
 * clock.
 *
 * Time bars are aligned to absolute epoch boundaries, not to the first tick
 * seen, so two processes fed the same stream cut it in the same places and
 * their bars are comparable. A bar is emitted when the observation that opens
 * the next one arrives, which means the last, still-open bar is only available
 * through `flush()` — a partial bar must never reach an indicator silently.
 */
final class BarAggregator
{
	private int $openNs = 0;

	private int $closeNs = 0;

	private float $open = 0.0;

	private float $high = 0.0;

	private float $low = 0.0;

	private float $close = 0.0;

	private float $volume = 0.0;

	private int $trades = 0;

	private bool $active = false;

	private int $bucket = 0;

	/**
	 * @param float $threshold nanoseconds for BarClock::Time, volume units for
	 *                         BarClock::Volume, trade count for BarClock::Ticks
	 */
	public function __construct(
		public readonly BarClock $clock,
		public readonly float $threshold,
	) {
		if (!($threshold > 0.0)) {
			throw new InvalidArgument('BarAggregator threshold must be > 0');
		}
	}

	public static function timeBars(int $intervalNs): self
	{
		return new self(BarClock::Time, (float) $intervalNs);
	}

	public static function volumeBars(float $volume): self
	{
		return new self(BarClock::Volume, $volume);
	}

	public static function tickBars(int $ticks): self
	{
		return new self(BarClock::Ticks, (float) $ticks);
	}

	public function addTrade(Trade $trade): ?Bar
	{
		return $this->add($trade->timestampNs, $trade->price, $trade->size);
	}

	/** @return Bar|null the bar this observation closed, if any */
	public function add(int $timestampNs, float $price, float $size = 0.0): ?Bar
	{
		if (!\is_finite($price) || $price <= 0.0) {
			throw new InvalidArgument(\sprintf('Bar input price must be finite and > 0, got %s', (string) $price));
		}
		$closed = null;

		if ($this->clock === BarClock::Time) {
			$bucket = (int) \floor((float) $timestampNs / $this->threshold);
			if ($this->active && $bucket !== $this->bucket) {
				$closed = $this->close();
			}
			$this->bucket = $bucket;
		}

		if (!$this->active) {
			$this->start($timestampNs, $price);
		}
		$this->accumulate($timestampNs, $price, $size);

		if ($this->clock === BarClock::Volume && $this->volume >= $this->threshold) {
			$closed = $this->close();
		} elseif ($this->clock === BarClock::Ticks && (float) $this->trades >= $this->threshold) {
			$closed = $this->close();
		}

		return $closed;
	}

	/** Closes and returns the bar in progress, if there is one. */
	public function flush(): ?Bar
	{
		return $this->active ? $this->close() : null;
	}

	public function isActive(): bool
	{
		return $this->active;
	}

	private function start(int $timestampNs, float $price): void
	{
		$this->openNs = $timestampNs;
		$this->open = $price;
		$this->high = $price;
		$this->low = $price;
		$this->volume = 0.0;
		$this->trades = 0;
		$this->active = true;
	}

	private function accumulate(int $timestampNs, float $price, float $size): void
	{
		if ($price > $this->high) {
			$this->high = $price;
		}
		if ($price < $this->low) {
			$this->low = $price;
		}
		$this->close = $price;
		$this->closeNs = $timestampNs;
		$this->volume += $size;
		$this->trades++;
	}

	private function close(): Bar
	{
		$bar = Bar::of(
			$this->openNs,
			$this->closeNs,
			$this->open,
			$this->high,
			$this->low,
			$this->close,
			$this->volume,
			$this->trades,
		);
		$this->active = false;
		return $bar;
	}
}
