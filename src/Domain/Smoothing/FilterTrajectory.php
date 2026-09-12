<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Smoothing;

use OpenCCK\Kalman\Domain\Contract\StepRecorder;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * §2.12 compact per-step storage of x̂_{k|k}, P_{k|k}, x̂⁻_k, P⁻_k, dt_k, t_k
 * in SplFixedArray<float> blocks (≈ 2n² + 2n floats per step, no per-element
 * zval overhead of nested arrays). Grows by doubling. Optional cap: when
 * maxSteps is reached the oldest steps are dropped (ring) — enough for
 * fixed-lag smoothing; for full-history RTS on millions of steps spill to disk
 * with Infrastructure\Output\TrajectoryFile.
 */
final class FilterTrajectory implements StepRecorder
{
	private int $n;
	private int $n2;
	private int $capacity;
	private int $count = 0;
	private int $start = 0;   // ring start index when capped

	/** @var \SplFixedArray<float> */
	private \SplFixedArray $xPrior;

	/** @var \SplFixedArray<float> */
	private \SplFixedArray $PPrior;

	/** @var \SplFixedArray<float> */
	private \SplFixedArray $xPost;

	/** @var \SplFixedArray<float> */
	private \SplFixedArray $PPost;

	/** @var \SplFixedArray<float> */
	private \SplFixedArray $dt;

	/** @var \SplFixedArray<int> */
	private \SplFixedArray $ts;

	private bool $priorPending = false;

	public function __construct(int $n, int $initialCapacity = 1024, private readonly ?int $maxSteps = null)
	{
		if ($n < 1 || $initialCapacity < 1) {
			throw new InvalidArgument('n and initialCapacity must be >= 1');
		}
		if ($maxSteps !== null && $maxSteps < 1) {
			throw new InvalidArgument('maxSteps must be >= 1');
		}
		$this->n = $n;
		$this->n2 = $n * $n;
		$this->capacity = $maxSteps !== null ? \min($initialCapacity, $maxSteps) : $initialCapacity;
		$this->allocate($this->capacity);
	}

	private function allocate(int $capacity): void
	{
		$this->xPrior = new \SplFixedArray($capacity * $this->n);
		$this->PPrior = new \SplFixedArray($capacity * $this->n2);
		$this->xPost = new \SplFixedArray($capacity * $this->n);
		$this->PPost = new \SplFixedArray($capacity * $this->n2);
		$this->dt = new \SplFixedArray($capacity);
		$this->ts = new \SplFixedArray($capacity);
	}

	public function recordPrior(float $dt, array $xPrior, array $PPrior): void
	{
		if (\count($xPrior) !== $this->n || \count($PPrior) !== $this->n2) {
			throw DimensionMismatch::forVector('prior', $this->n, \count($xPrior));
		}
		$slot = $this->reserve();
		$this->dt[$slot] = $dt;
		$this->write($this->xPrior, $slot * $this->n, $xPrior);
		$this->write($this->PPrior, $slot * $this->n2, $PPrior);
		$this->priorPending = true;
	}

	public function recordPosterior(array $xPost, array $PPost, ?int $timestampNs): void
	{
		if (!$this->priorPending) {
			// posterior without a preceding prior (pure correct()): prior == the state before, unknown → use posterior
			$slot = $this->reserve();
			$this->dt[$slot] = 0.0;
			$this->write($this->xPrior, $slot * $this->n, $xPost);
			$this->write($this->PPrior, $slot * $this->n2, $PPost);
		} else {
			$slot = ($this->start + $this->count - 1) % $this->capacity;
		}
		$this->write($this->xPost, $slot * $this->n, $xPost);
		$this->write($this->PPost, $slot * $this->n2, $PPost);
		$this->ts[$slot] = $timestampNs ?? 0;
		$this->priorPending = false;
	}

	/** Returns the physical slot for a new step, growing or rotating as needed. */
	private function reserve(): int
	{
		if ($this->count === $this->capacity) {
			if ($this->maxSteps !== null && $this->capacity >= $this->maxSteps) {
				// ring: drop the oldest
				$slot = $this->start;
				$this->start = ($this->start + 1) % $this->capacity;
				return $slot;
			}
			$this->grow();
		}
		$slot = ($this->start + $this->count) % $this->capacity;
		$this->count++;
		return $slot;
	}

	private function grow(): void
	{
		$newCapacity = $this->capacity * 2;
		if ($this->maxSteps !== null) {
			$newCapacity = \min($newCapacity, $this->maxSteps);
		}
		$old = [$this->xPrior, $this->PPrior, $this->xPost, $this->PPost, $this->dt, $this->ts];
		$oldStart = $this->start;
		$oldCapacity = $this->capacity;
		$this->allocate($newCapacity);
		for ($k = 0; $k < $this->count; $k++) {
			$from = ($oldStart + $k) % $oldCapacity;
			$this->copySlot($old, $from, $k);
		}
		$this->start = 0;
		$this->capacity = $newCapacity;
	}

	/** @param array{0: \SplFixedArray<float>, 1: \SplFixedArray<float>, 2: \SplFixedArray<float>, 3: \SplFixedArray<float>, 4: \SplFixedArray<float>, 5: \SplFixedArray<int>} $old */
	private function copySlot(array $old, int $from, int $to): void
	{
		$n = $this->n;
		$n2 = $this->n2;
		for ($i = 0; $i < $n; $i++) {
			$this->xPrior[$to * $n + $i] = $old[0][$from * $n + $i];
			$this->xPost[$to * $n + $i] = $old[2][$from * $n + $i];
		}
		for ($i = 0; $i < $n2; $i++) {
			$this->PPrior[$to * $n2 + $i] = $old[1][$from * $n2 + $i];
			$this->PPost[$to * $n2 + $i] = $old[3][$from * $n2 + $i];
		}
		$this->dt[$to] = $old[4][$from];
		$this->ts[$to] = $old[5][$from];
	}

	/**
	 * @param \SplFixedArray<float> $target
	 * @param array<int, float> $values
	 */
	private function write(\SplFixedArray $target, int $offset, array $values): void
	{
		$i = 0;
		foreach ($values as $v) {
			$target[$offset + $i] = $v;
			$i++;
		}
	}

	/**
	 * @param \SplFixedArray<float> $source
	 * @return array<int, float>
	 */
	private function read(\SplFixedArray $source, int $offset, int $length): array
	{
		$out = [];
		for ($i = 0; $i < $length; $i++) {
			$out[] = (float) $source[$offset + $i];
		}
		return $out;
	}

	private function slot(int $k): int
	{
		if ($k < 0 || $k >= $this->count) {
			throw new InvalidArgument(\sprintf('Step %d out of range [0, %d)', $k, $this->count));
		}
		return ($this->start + $k) % $this->capacity;
	}

	public function count(): int
	{
		return $this->count;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	/** @return array<int, float> */
	public function priorMean(int $k): array
	{
		return $this->read($this->xPrior, $this->slot($k) * $this->n, $this->n);
	}

	/** @return array<int, float> */
	public function priorCovariance(int $k): array
	{
		return $this->read($this->PPrior, $this->slot($k) * $this->n2, $this->n2);
	}

	/** @return array<int, float> */
	public function posteriorMean(int $k): array
	{
		return $this->read($this->xPost, $this->slot($k) * $this->n, $this->n);
	}

	/** @return array<int, float> */
	public function posteriorCovariance(int $k): array
	{
		return $this->read($this->PPost, $this->slot($k) * $this->n2, $this->n2);
	}

	/** dt used to predict INTO step k (0 for the first step). */
	public function dt(int $k): float
	{
		return (float) $this->dt[$this->slot($k)];
	}

	public function timestampNs(int $k): int
	{
		return (int) $this->ts[$this->slot($k)];
	}

	/**
	 * Plain-array export for the offloadable smoothers.
	 *
	 * @return array{n: int, dt: array<int, float>, ts: array<int, int>, xPrior: array<int, array<int, float>>, PPrior: array<int, array<int, float>>, xPost: array<int, array<int, float>>, PPost: array<int, array<int, float>>}
	 */
	public function toArrays(): array
	{
		$out = ['n' => $this->n, 'dt' => [], 'ts' => [], 'xPrior' => [], 'PPrior' => [], 'xPost' => [], 'PPost' => []];
		for ($k = 0; $k < $this->count; $k++) {
			$out['dt'][] = $this->dt($k);
			$out['ts'][] = $this->timestampNs($k);
			$out['xPrior'][] = $this->priorMean($k);
			$out['PPrior'][] = $this->priorCovariance($k);
			$out['xPost'][] = $this->posteriorMean($k);
			$out['PPost'][] = $this->posteriorCovariance($k);
		}
		return $out;
	}

	/** Approximate memory footprint in bytes (8 bytes per stored float). */
	public function bytes(): int
	{
		return 8 * $this->capacity * (2 * $this->n2 + 2 * $this->n + 2);
	}
}
