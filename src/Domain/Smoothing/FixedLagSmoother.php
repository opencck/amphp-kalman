<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Smoothing;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\StepRecorder;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * §2.12 online fixed-lag smoother: keeps the last L+1 filter steps in a ring
 * trajectory and, after every step, runs the RTS backward pass over the
 * window. lagged() returns x̂_{k−L|k}, P_{k−L|k} — the estimate L steps ago
 * refined with everything seen since. Cost O(L·n³) per step; L = 5..50.
 */
final class FixedLagSmoother implements StepRecorder
{
	private FilterTrajectory $window;

	/** @var array<int, float>|null */
	private ?array $laggedMean = null;

	/** @var array<int, float>|null */
	private ?array $laggedCovariance = null;

	private ?int $laggedTimestampNs = null;
	private int $steps = 0;

	public function __construct(private readonly MotionModel $motion, private readonly int $lag)
	{
		if ($lag < 1) {
			throw new InvalidArgument('lag must be >= 1');
		}
		$this->window = new FilterTrajectory($motion->stateSize(), $lag + 1, maxSteps: $lag + 1);
	}

	public function recordPrior(float $dt, array $xPrior, array $PPrior): void
	{
		$this->window->recordPrior($dt, $xPrior, $PPrior);
	}

	public function recordPosterior(array $xPost, array $PPost, ?int $timestampNs): void
	{
		$this->window->recordPosterior($xPost, $PPost, $timestampNs);
		$this->steps++;
		if ($this->window->count() < $this->lag + 1) {
			return;   // not enough history yet
		}
		$smoothed = RauchTungStriebel::smooth($this->window, $this->motion);
		$this->laggedMean = $smoothed['mean'][0];
		$this->laggedCovariance = $smoothed['covariance'][0];
		$this->laggedTimestampNs = $this->window->timestampNs(0);
	}

	public function isReady(): bool
	{
		return $this->laggedMean !== null;
	}

	/** @return array<int, float> x̂_{k−L|k} */
	public function laggedMean(): array
	{
		if ($this->laggedMean === null) {
			throw new InvalidArgument('Smoother needs lag+1 steps before it is ready');
		}
		return $this->laggedMean;
	}

	/** @return array<int, float> P_{k−L|k} */
	public function laggedCovariance(): array
	{
		if ($this->laggedCovariance === null) {
			throw new InvalidArgument('Smoother needs lag+1 steps before it is ready');
		}
		return $this->laggedCovariance;
	}

	public function laggedTimestampNs(): ?int
	{
		return $this->laggedTimestampNs;
	}

	public function lag(): int
	{
		return $this->lag;
	}

	public function steps(): int
	{
		return $this->steps;
	}
}
