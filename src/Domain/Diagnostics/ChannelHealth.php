<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Diagnostics;

/**
 * Per-channel running statistics: rolling NIS, rejection streaks,
 * innovation autocorrelation at lags 1..L. Allocation-free after construction.
 */
final class ChannelHealth
{
	/** @var array<int, float> ring buffer of NIS values */
	private array $nisRing;
	private int $nisPos = 0;
	private int $nisCount = 0;
	private float $nisSum = 0.0;

	private float $nisTotal = 0.0;
	private int $accepted = 0;
	private int $rejected = 0;
	private int $downWeighted = 0;
	private int $consecutiveRejections = 0;
	private int $maxConsecutiveRejections = 0;

	/** @var array<int, float> ring buffer of the last L standardised innovations */
	private array $lagRing;
	private int $lagPos = 0;
	private int $lagCount = 0;

	/** @var array<int, float> Σ u_k·u_{k−τ} for τ = 1..L */
	private array $lagSums;
	private float $squareSum = 0.0;
	private int $squareCount = 0;

	public function __construct(
		private readonly int $window = 100,
		private readonly int $lags = 10,
	) {
		$this->nisRing = \array_fill(0, $window, 0.0);
		$this->lagRing = \array_fill(0, $lags, 0.0);
		$this->lagSums = \array_fill(0, $lags, 0.0);
	}

	public function record(float $innovation, float $s, float $weight): void
	{
		if ($weight <= 0.0) {
			$this->rejected++;
			$this->consecutiveRejections++;
			if ($this->consecutiveRejections > $this->maxConsecutiveRejections) {
				$this->maxConsecutiveRejections = $this->consecutiveRejections;
			}
			return;
		}
		$this->consecutiveRejections = 0;
		$this->accepted++;
		if ($weight < 1.0) {
			$this->downWeighted++;
		}

		$nis = $innovation * $innovation / $s;
		$this->nisTotal += $nis;

		$w = $this->window;
		$this->nisSum += $nis - $this->nisRing[$this->nisPos];
		$this->nisRing[$this->nisPos] = $nis;
		$this->nisPos = ($this->nisPos + 1) % $w;
		if ($this->nisCount < $w) {
			$this->nisCount++;
		}

		$u = $innovation / \sqrt($s);
		$L = $this->lags;
		$ring = &$this->lagRing;
		$sums = &$this->lagSums;
		$available = $this->lagCount;
		for ($tau = 1; $tau <= $L && $tau <= $available; $tau++) {
			$idx = $this->lagPos - $tau;
			if ($idx < 0) {
				$idx += $L;
			}
			$sums[$tau - 1] += $u * $ring[$idx];
		}
		$ring[$this->lagPos] = $u;
		$this->lagPos = ($this->lagPos + 1) % $L;
		if ($this->lagCount < $L) {
			$this->lagCount++;
		}
		$this->squareSum += $u * $u;
		$this->squareCount++;
	}

	/** Mean NIS over the rolling window (E = 1 for a consistent filter). */
	public function rollingNis(): float
	{
		return $this->nisCount === 0 ? \NAN : $this->nisSum / $this->nisCount;
	}

	/** Mean NIS over the whole run. */
	public function meanNis(): float
	{
		return $this->accepted === 0 ? \NAN : $this->nisTotal / $this->accepted;
	}

	/**
	 * Sample autocorrelation of standardised innovations at lag τ (1..L).
	 * Should lie within ±1.96/√N for white innovations.
	 */
	public function autocorrelation(int $lag): float
	{
		if ($lag < 1 || $lag > $this->lags) {
			return \NAN;
		}
		if ($this->squareSum === 0.0) {
			return \NAN;
		}
		return $this->lagSums[$lag - 1] / $this->squareSum;
	}

	/** @return array<int, float> autocorrelations for lags 1..L */
	public function autocorrelations(): array
	{
		$out = [];
		for ($tau = 1; $tau <= $this->lags; $tau++) {
			$out[] = $this->autocorrelation($tau);
		}
		return $out;
	}

	/** ±1.96/√N whiteness band. */
	public function whitenessBand(): float
	{
		return $this->squareCount === 0 ? \INF : 1.96 / \sqrt($this->squareCount);
	}

	public function accepted(): int
	{
		return $this->accepted;
	}

	public function rejected(): int
	{
		return $this->rejected;
	}

	public function downWeighted(): int
	{
		return $this->downWeighted;
	}

	public function total(): int
	{
		return $this->accepted + $this->rejected;
	}

	public function rejectionRate(): float
	{
		$t = $this->total();
		return $t === 0 ? 0.0 : $this->rejected / $t;
	}

	public function consecutiveRejections(): int
	{
		return $this->consecutiveRejections;
	}

	public function maxConsecutiveRejections(): int
	{
		return $this->maxConsecutiveRejections;
	}

	public function window(): int
	{
		return $this->window;
	}

	public function windowFill(): int
	{
		return $this->nisCount;
	}
}
