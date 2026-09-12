<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Support;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Fixed-capacity circular buffer of floats, fully allocated in the
 * constructor: `push()` performs no allocation, so a metric built on it
 * satisfies the zero-allocation rule of BRIEF §2 p.13.
 *
 * `push()` returns the value it evicted, or NAN while the window is still
 * filling — this is what lets a rolling sum or a windowed Welford update stay
 * O(1) without ever rescanning the window.
 */
final class RingBuffer
{
	/** @var array<int, float> */
	private array $buffer;

	private int $head = 0;

	private int $size = 0;

	public function __construct(public readonly int $capacity)
	{
		if ($capacity < 1) {
			throw new InvalidArgument(\sprintf('RingBuffer capacity must be >= 1, got %d', $capacity));
		}
		$this->buffer = \array_fill(0, $capacity, 0.0);
	}

	/** @return float the evicted value, or NAN while the buffer is not yet full */
	public function push(float $value): float
	{
		$evicted = $this->size === $this->capacity ? $this->buffer[$this->head] : \NAN;
		$this->buffer[$this->head] = $value;
		$head = $this->head + 1;
		$this->head = $head === $this->capacity ? 0 : $head;
		if ($this->size < $this->capacity) {
			$this->size++;
		}
		return $evicted;
	}

	public function count(): int
	{
		return $this->size;
	}

	public function isFull(): bool
	{
		return $this->size === $this->capacity;
	}

	/** @param int $i 0 is the oldest value still in the window, count()−1 the newest */
	public function at(int $i): float
	{
		if ($i < 0 || $i >= $this->size) {
			throw new InvalidArgument(\sprintf('RingBuffer index %d out of range [0, %d)', $i, $this->size));
		}
		$p = $this->head - $this->size + $i;
		if ($p < 0) {
			$p += $this->capacity;
		}
		return $this->buffer[$p];
	}

	public function oldest(): float
	{
		return $this->size === 0 ? \NAN : $this->at(0);
	}

	public function newest(): float
	{
		return $this->size === 0 ? \NAN : $this->at($this->size - 1);
	}

	public function reset(): void
	{
		$this->head = 0;
		$this->size = 0;
	}

	/** @return list<float> oldest first; for tests and serialisation, not for the hot path */
	public function toList(): array
	{
		$out = [];
		for ($i = 0; $i < $this->size; $i++) {
			$out[] = $this->at($i);
		}
		return $out;
	}
}
