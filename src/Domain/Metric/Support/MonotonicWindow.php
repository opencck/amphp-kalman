<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Support;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Sliding-window maximum or minimum in O(1) amortised, via a monotonic deque
 * of candidates (ROADMAP M4).
 *
 * The naive alternative — rescanning the window on every tick — is O(N) per
 * update, and the stochastic oscillator alone needs both a max and a min over
 * a 14-bar window on every update of every instrument. The deque keeps only
 * the values that can still become the extreme: pushing a new maximum discards
 * every smaller candidate behind it, because none of them can outlive it.
 */
final class MonotonicWindow
{
	/** @var array<int, float> */
	private array $values;

	/** @var array<int, int> */
	private array $positions;

	private int $front = 0;

	private int $back = 0;

	private int $position = 0;

	private int $filled = 0;

	private function __construct(public readonly int $capacity, private readonly bool $isMax)
	{
		if ($capacity < 1) {
			throw new InvalidArgument(\sprintf('MonotonicWindow capacity must be >= 1, got %d', $capacity));
		}
		$this->values = \array_fill(0, $capacity, 0.0);
		$this->positions = \array_fill(0, $capacity, 0);
	}

	public static function max(int $capacity): self
	{
		return new self($capacity, true);
	}

	public static function min(int $capacity): self
	{
		return new self($capacity, false);
	}

	public function push(float $value): void
	{
		$capacity = $this->capacity;
		$isMax = $this->isMax;

		// Expire first, append second. The deque is stored in `capacity` slots
		// and indexed modulo that, so appending while it is full would write
		// over the front entry — which is exactly what happens on a monotone
		// sequence, where nothing is ever popped from the back. Dropping the
		// out-of-window entry before the append leaves at most capacity−1
		// candidates and makes the collision impossible.
		$expired = $this->position - $capacity;
		while ($this->back > $this->front && $this->positions[$this->front % $capacity] <= $expired) {
			$this->front++;
		}

		while ($this->back > $this->front) {
			$p = ($this->back - 1) % $capacity;
			$candidate = $this->values[$p];
			if ($isMax ? $candidate <= $value : $candidate >= $value) {
				$this->back--;
				continue;
			}
			break;
		}

		$p = $this->back % $capacity;
		$this->values[$p] = $value;
		$this->positions[$p] = $this->position;
		$this->back++;

		$this->position++;
		if ($this->filled < $capacity) {
			$this->filled++;
		}
	}

	/** The extreme over the window, or NAN before the first push. */
	public function value(): float
	{
		return $this->filled === 0 ? \NAN : $this->values[$this->front % $this->capacity];
	}

	public function count(): int
	{
		return $this->filled;
	}

	public function isFull(): bool
	{
		return $this->filled === $this->capacity;
	}

	public function reset(): void
	{
		$this->front = 0;
		$this->back = 0;
		$this->position = 0;
		$this->filled = 0;
	}
}
