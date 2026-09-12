<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Diagnostics;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\UpdateResult;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Filter consistency monitor (§2.9): rolling NIS per channel and total,
 * innovation whiteness, rejection streaks → model-break suspicion,
 * dropped (out-of-sequence) measurements.
 *
 * Feed it every UpdateResult; read the statistics whenever convenient.
 * Synchronous, allocation-free per record().
 */
final class ConsistencyMonitor
{
	/** @var array<int, ChannelHealth> */
	private array $channels = [];

	private float $totalNis = 0.0;
	private int $totalAccepted = 0;
	private int $totalRejected = 0;
	private int $stepsRecorded = 0;
	private int $blindSteps = 0;
	private int $dropped = 0;
	private int $modelBreakEvents = 0;

	/** @var array<int, float> rolling window of per-step NIS */
	private array $stepRing;
	private int $stepPos = 0;
	private int $stepCount = 0;
	private float $stepSum = 0.0;

	/** @var array<int, int> rolling window of per-step accepted counts */
	private array $stepDofRing;
	private float $stepDofSum = 0.0;

	public function __construct(
		int $channelCount,
		private readonly int $window = 100,
		private readonly int $lags = 10,
		private readonly int $modelBreakThreshold = 5,
	) {
		if ($channelCount < 1) {
			throw new InvalidArgument('channelCount must be >= 1');
		}
		if ($window < 1) {
			throw new InvalidArgument('window must be >= 1');
		}
		for ($c = 0; $c < $channelCount; $c++) {
			$this->channels[] = new ChannelHealth($window, $lags);
		}
		$this->stepRing = \array_fill(0, $window, 0.0);
		$this->stepDofRing = \array_fill(0, $window, 0);
	}

	public function record(UpdateResult $result): void
	{
		$this->stepsRecorded++;
		if ($result->outcomes === []) {
			$this->blindSteps++;
			return;
		}

		$stepNis = 0.0;
		$dof = 0;
		foreach ($result->outcomes as $o) {
			$health = $this->channels[$o->channel];
			$health->record($o->innovation, $o->innovationVariance, $o->weight);
			if ($o->weight > 0.0) {
				$nis = $o->innovation * $o->innovation / $o->innovationVariance;
				$stepNis += $nis;
				$dof++;
				$this->totalNis += $nis;
				$this->totalAccepted++;
			} else {
				$this->totalRejected++;
				if ($health->consecutiveRejections() === $this->modelBreakThreshold) {
					$this->modelBreakEvents++;
				}
			}
		}

		$w = $this->window;
		$this->stepSum += $stepNis - $this->stepRing[$this->stepPos];
		$this->stepDofSum += $dof - $this->stepDofRing[$this->stepPos];
		$this->stepRing[$this->stepPos] = $stepNis;
		$this->stepDofRing[$this->stepPos] = $dof;
		$this->stepPos = ($this->stepPos + 1) % $w;
		if ($this->stepCount < $w) {
			$this->stepCount++;
		}
	}

	/** Raw-path variant for stepRaw(): record one channel directly. */
	public function recordChannel(int $channel, float $innovation, float $s, float $weight): void
	{
		$this->channels[$channel]->record($innovation, $s, $weight);
		if ($weight > 0.0) {
			$this->totalNis += $innovation * $innovation / $s;
			$this->totalAccepted++;
		} else {
			$this->totalRejected++;
		}
	}

	public function recordDropped(Measurement $measurement): void
	{
		$this->dropped++;
	}

	public function channel(int $channel): ChannelHealth
	{
		if (!isset($this->channels[$channel])) {
			throw new InvalidArgument(\sprintf('Channel %d out of range', $channel));
		}
		return $this->channels[$channel];
	}

	public function channelCount(): int
	{
		return \count($this->channels);
	}

	/** Mean per-channel NIS over the whole run (E = 1). */
	public function meanNis(): float
	{
		return $this->totalAccepted === 0 ? \NAN : $this->totalNis / $this->totalAccepted;
	}

	/**
	 * Rolling mean NIS normalised by the accepted-channel count in the window
	 * (E = 1). Compare against nisBounds().
	 */
	public function rollingNis(): float
	{
		return $this->stepDofSum === 0.0 ? \NAN : $this->stepSum / $this->stepDofSum;
	}

	/**
	 * 95 % two-sided acceptance interval for rollingNis() given the current
	 * window fill: [χ²_k(0.025), χ²_k(0.975)] / k with k = accepted channels in window.
	 *
	 * @return array{0: float, 1: float}
	 */
	public function nisBounds(float $alpha = 0.05): array
	{
		$k = (int) $this->stepDofSum;
		if ($k < 1) {
			return [0.0, \INF];
		}
		return ChiSquare::meanBounds(1, $k, $alpha);
	}

	/** True when rollingNis() lies outside nisBounds(). */
	public function isInconsistent(float $alpha = 0.05): bool
	{
		$nis = $this->rollingNis();
		if (\is_nan($nis)) {
			return false;
		}
		[$lo, $hi] = $this->nisBounds($alpha);
		return $nis < $lo || $nis > $hi;
	}

	/** Filter is over-confident (P too small: Q or R underestimated). */
	public function isOverconfident(float $alpha = 0.05): bool
	{
		$nis = $this->rollingNis();
		return !\is_nan($nis) && $nis > $this->nisBounds($alpha)[1];
	}

	/** Some channel currently has ≥ threshold consecutive rejections. */
	public function modelBreakSuspected(): bool
	{
		foreach ($this->channels as $h) {
			if ($h->consecutiveRejections() >= $this->modelBreakThreshold) {
				return true;
			}
		}
		return false;
	}

	public function modelBreakEvents(): int
	{
		return $this->modelBreakEvents;
	}

	public function totalAccepted(): int
	{
		return $this->totalAccepted;
	}

	public function totalRejected(): int
	{
		return $this->totalRejected;
	}

	public function rejectionRate(): float
	{
		$t = $this->totalAccepted + $this->totalRejected;
		return $t === 0 ? 0.0 : $this->totalRejected / $t;
	}

	public function stepsRecorded(): int
	{
		return $this->stepsRecorded;
	}

	public function blindSteps(): int
	{
		return $this->blindSteps;
	}

	public function dropped(): int
	{
		return $this->dropped;
	}

	/** @return array<string, mixed> plain report for logging / JSON */
	public function report(): array
	{
		$channels = [];
		foreach ($this->channels as $i => $h) {
			$channels[$i] = [
				'accepted' => $h->accepted(),
				'rejected' => $h->rejected(),
				'downWeighted' => $h->downWeighted(),
				'meanNis' => $h->meanNis(),
				'rollingNis' => $h->rollingNis(),
				'maxConsecutiveRejections' => $h->maxConsecutiveRejections(),
				'autocorrelation' => $h->autocorrelations(),
				'whitenessBand' => $h->whitenessBand(),
			];
		}
		return [
			'steps' => $this->stepsRecorded,
			'blindSteps' => $this->blindSteps,
			'dropped' => $this->dropped,
			'accepted' => $this->totalAccepted,
			'rejected' => $this->totalRejected,
			'rejectionRate' => $this->rejectionRate(),
			'meanNis' => $this->meanNis(),
			'rollingNis' => $this->rollingNis(),
			'nisBounds' => $this->nisBounds(),
			'modelBreakEvents' => $this->modelBreakEvents,
			'channels' => $channels,
		];
	}
}
