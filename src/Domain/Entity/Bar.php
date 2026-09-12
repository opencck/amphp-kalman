<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * An OHLCV bar.
 *
 * The high and the low are the whole point: they carry the path the price took
 * inside the interval, and the range-based volatility estimators that use them
 * are five to eight times more efficient than close-to-close (ROADMAP §4.6
 * M-22). A `min_over_time` / `max_over_time` over sampled quotes is not the
 * same thing — it sees only the points the scraper happened to catch, and
 * systematically understates the range.
 */
final readonly class Bar
{
	private function __construct(
		public int $openNs,
		public int $closeNs,
		public float $open,
		public float $high,
		public float $low,
		public float $close,
		public float $volume,
		public int $trades,
	) {
	}

	public static function of(
		int $openNs,
		int $closeNs,
		float $open,
		float $high,
		float $low,
		float $close,
		float $volume = 0.0,
		int $trades = 0,
	): self {
		foreach (['open' => $open, 'high' => $high, 'low' => $low, 'close' => $close] as $name => $value) {
			if (!\is_finite($value) || $value <= 0.0) {
				throw new InvalidArgument(\sprintf('Bar %s must be finite and > 0, got %s', $name, (string) $value));
			}
		}
		if ($high < $low) {
			throw new InvalidArgument(\sprintf('Bar high %s is below low %s', (string) $high, (string) $low));
		}
		if ($high < $open || $high < $close || $low > $open || $low > $close) {
			throw new InvalidArgument('Bar high/low do not bracket open/close');
		}
		if (!\is_finite($volume) || $volume < 0.0) {
			throw new InvalidArgument('Bar volume must be finite and >= 0');
		}
		if ($closeNs < $openNs) {
			throw new InvalidArgument('Bar close timestamp precedes its open timestamp');
		}
		return new self($openNs, $closeNs, $open, $high, $low, $close, $volume, $trades);
	}

	/** (H + L + C) / 3 — the CCI and the bar form of VWAP are defined on it. */
	public function typicalPrice(): float
	{
		return ($this->high + $this->low + $this->close) / 3.0;
	}

	/** (H + L + 2C) / 4 — weighted close. */
	public function weightedClose(): float
	{
		return ($this->high + $this->low + 2.0 * $this->close) / 4.0;
	}

	public function range(): float
	{
		return $this->high - $this->low;
	}

	/**
	 * Wilder's true range: the largest of the bar range and the two gaps to the
	 * previous close. Pass NAN for the first bar, where the gap is undefined.
	 */
	public function trueRange(float $previousClose): float
	{
		$range = $this->high - $this->low;
		if (\is_nan($previousClose)) {
			return $range;
		}
		$up = \abs($this->high - $previousClose);
		$down = \abs($this->low - $previousClose);
		$max = $range;
		if ($up > $max) {
			$max = $up;
		}
		return $down > $max ? $down : $max;
	}

	public function durationNs(): int
	{
		return $this->closeNs - $this->openNs;
	}

	/** @return array{openNs: int, closeNs: int, o: float, h: float, l: float, c: float, v: float, n: int} */
	public function toArray(): array
	{
		return [
			'openNs' => $this->openNs,
			'closeNs' => $this->closeNs,
			'o' => $this->open,
			'h' => $this->high,
			'l' => $this->low,
			'c' => $this->close,
			'v' => $this->volume,
			'n' => $this->trades,
		];
	}

	/** @param array{openNs: int, closeNs: int, o: float|int, h: float|int, l: float|int, c: float|int, v?: float|int, n?: int} $data */
	public static function fromArray(array $data): self
	{
		return self::of(
			$data['openNs'],
			$data['closeNs'],
			(float) $data['o'],
			(float) $data['h'],
			(float) $data['l'],
			(float) $data['c'],
			(float) ($data['v'] ?? 0.0),
			$data['n'] ?? 0,
		);
	}
}
