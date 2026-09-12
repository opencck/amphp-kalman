<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Support;

/**
 * Mean and variance over a sliding window, updated in O(1) by the windowed
 * Welford recurrence rather than by subtracting from a running sum of squares
 * (ROADMAP M4).
 *
 * With a full window the update is algebraically exact:
 *
 *   μ' = μ + (x_new − x_old) / N
 *   M2' = M2 + (x_new − x_old)·(x_new − μ' + x_old − μ)
 *
 * Subtracting `x_old²` from a running `Σx²` instead loses all significance
 * once the window mean is large compared with its spread — exactly the regime
 * a price series lives in.
 *
 * Two things keep the floating-point error far below that of the naive form.
 *
 * **A shifted origin.** Everything is accumulated relative to the first value
 * the window ever saw. A price series around 60 000 with a spread of 5 carries
 * four decimal digits of magnitude that contribute nothing to its variance but
 * cost four digits of precision in every product; working on `x − offset`
 * gives those digits back, and the offset cancels out of the variance exactly.
 * It is re-anchored on each exact rescan, so a series that drifts away from
 * where it started does not slowly lose the benefit.
 *
 * **A periodic exact rescan.** Rounding still accumulates over millions of
 * updates, so the window is recomputed from its contents every `refreshEvery`
 * pushes. The default of 65 536 costs a sub-microsecond rescan roughly once a
 * minute at 1 kHz.
 *
 * A window of sixteen values or fewer is recomputed on every push instead. At
 * that size the rescan is a handful of operations — no more than the branchy
 * recurrence it replaces — and the recurrence's one real weakness disappears
 * with it: it never returns exactly to zero, so a window whose values have
 * become identical would keep reporting a variance of 1e-7 where the answer is
 * 0. Short windows are also where that matters most, since a three-bar signal
 * line sits on top of them.
 */
final class RollingMoments
{
	private RingBuffer $window;

	/**
	 * Origin of the accumulators. Every stored moment is relative to it, so
	 * the arithmetic works on the spread of the data rather than on its level.
	 */
	private float $offset = 0.0;

	private bool $anchored = false;

	private float $mean = 0.0;

	private float $m2 = 0.0;

	private int $sinceRefresh = 0;

	/** At or below this window length the moments are recomputed exactly every push. */
	private const EXACT_BELOW = 16;

	private readonly bool $exact;

	public function __construct(int $period, private readonly int $refreshEvery = 65536)
	{
		$this->window = new RingBuffer($period);
		$this->exact = $period <= self::EXACT_BELOW;
	}

	public function push(float $value): void
	{
		if ($this->exact) {
			$this->window->push($value);
			$this->refresh();
			return;
		}
		if (!$this->anchored) {
			$this->offset = $value;
			$this->anchored = true;
		}
		$relative = $value - $this->offset;
		$evicted = $this->window->push($value);
		$n = $this->window->count();
		if (\is_nan($evicted)) {
			$delta = $relative - $this->mean;
			$this->mean += $delta / $n;
			$this->m2 += $delta * ($relative - $this->mean);
		} else {
			$out = $evicted - $this->offset;
			$previous = $this->mean;
			$this->mean = $previous + ($relative - $out) / $n;
			$this->m2 += ($relative - $out) * ($relative - $this->mean + $out - $previous);
		}
		if (++$this->sinceRefresh >= $this->refreshEvery) {
			$this->refresh();
		}
	}

	public function count(): int
	{
		return $this->window->count();
	}

	public function isFull(): bool
	{
		return $this->window->isFull();
	}

	public function mean(): float
	{
		return $this->window->count() === 0 ? \NAN : $this->offset + $this->mean;
	}

	/** Unbiased (N−1) variance; NAN with fewer than two values. */
	public function variance(): float
	{
		$n = $this->window->count();
		if ($n < 2) {
			return \NAN;
		}
		$m2 = $this->m2 < 0.0 ? 0.0 : $this->m2;
		return $m2 / ($n - 1);
	}

	/** Population (N) variance; NAN on an empty window. */
	public function populationVariance(): float
	{
		$n = $this->window->count();
		if ($n < 1) {
			return \NAN;
		}
		$m2 = $this->m2 < 0.0 ? 0.0 : $this->m2;
		return $m2 / $n;
	}

	public function stdDev(): float
	{
		$v = $this->variance();
		return \is_nan($v) ? \NAN : \sqrt($v);
	}

	public function populationStdDev(): float
	{
		$v = $this->populationVariance();
		return \is_nan($v) ? \NAN : \sqrt($v);
	}

	/**
	 * Mean absolute deviation from the window mean — the denominator of the CCI
	 * (ROADMAP §4.2 M-08). O(N) by construction: unlike the variance it has no
	 * O(1) update, and substituting the standard deviation (the usual shortcut)
	 * inflates the CCI by 1/0.7979 ≈ 1.25 and breaks the ±100 calibration.
	 */
	public function meanAbsoluteDeviation(): float
	{
		$n = $this->window->count();
		if ($n === 0) {
			return \NAN;
		}
		$mean = $this->offset + $this->mean;
		$sum = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$sum += \abs($this->window->at($i) - $mean);
		}
		return $sum / $n;
	}

	public function oldest(): float
	{
		return $this->window->oldest();
	}

	public function newest(): float
	{
		return $this->window->newest();
	}

	public function reset(): void
	{
		$this->window->reset();
		$this->offset = 0.0;
		$this->anchored = false;
		$this->mean = 0.0;
		$this->m2 = 0.0;
		$this->sinceRefresh = 0;
	}

	/**
	 * Exact recomputation from the window contents. Bounds the drift of the
	 * O(1) updates and re-anchors the origin on the current data.
	 */
	private function refresh(): void
	{
		$this->sinceRefresh = 0;
		$n = $this->window->count();
		if ($n === 0) {
			$this->offset = 0.0;
			$this->anchored = false;
			$this->mean = 0.0;
			$this->m2 = 0.0;
			return;
		}
		$offset = $this->window->at(0);
		$sum = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$sum += $this->window->at($i) - $offset;
		}
		$mean = $sum / $n;
		$m2 = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$d = $this->window->at($i) - $offset - $mean;
			$m2 += $d * $d;
		}
		$this->offset = $offset;
		$this->anchored = true;
		$this->mean = $mean;
		$this->m2 = $m2;
	}
}
