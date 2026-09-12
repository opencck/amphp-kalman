<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Adaptive;

use OpenCCK\Kalman\Domain\Entity\UpdateResult;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Model\Generic\HeteroscedasticMotionModel;
use OpenCCK\Kalman\Domain\Model\Observation\MutableObservation;

/**
 * Closes the loop: feeds every UpdateResult to a Sage–Husa estimator and
 * writes damped estimates back into the models the filter reads on the next
 * step — R into a MutableObservation, the Q scale into a
 * HeteroscedasticMotionModel multiplier. Damping (0..1) limits the per-step
 * relative change; bounds keep the multiplier inside [qMin, qMax].
 */
final class AdaptiveNoise
{
	private SageHusa $estimator;
	private float $multiplier = 1.0;

	public function __construct(
		private readonly MutableObservation $observation,
		private readonly ?HeteroscedasticMotionModel $motion = null,
		float $forgetting = 0.98,
		private readonly float $damping = 0.1,
		private readonly float $qMin = 0.1,
		private readonly float $qMax = 10.0,
		private readonly int $warmup = 20,
	) {
		if ($damping <= 0.0 || $damping > 1.0) {
			throw new InvalidArgument('damping must be in (0, 1]');
		}
		$initial = [];
		for ($c = 0; $c < $observation->channelCount(); $c++) {
			$initial[] = $observation->channelVariance($c);
		}
		$this->estimator = new SageHusa($initial, $forgetting);
	}

	public function record(UpdateResult $result): void
	{
		$used = [];
		foreach ($result->outcomes as $o) {
			$used[$o->channel] = $this->observation->channelVariance($o->channel);
		}
		$this->estimator->record($result, $used);

		foreach ($result->outcomes as $o) {
			$c = $o->channel;
			if ($this->estimator->updates($c) < $this->warmup) {
				continue;
			}
			$current = $this->observation->channelVariance($c);
			$target = $this->estimator->estimatedR($c);
			$next = $current + $this->damping * ($target - $current);
			if ($next > 0.0) {
				$this->observation->setVariance($c, $next);
			}
		}

		if ($this->motion !== null && $result->outcomes !== []) {
			$target = $this->estimator->suggestedQScale();
			$next = $this->multiplier + $this->damping * ($target - $this->multiplier);
			$next = \min($this->qMax, \max($this->qMin, $next));
			$this->multiplier = $next;
			$this->motion->setMultiplier($next);
		}
	}

	public function estimator(): SageHusa
	{
		return $this->estimator;
	}

	public function multiplier(): float
	{
		return $this->multiplier;
	}
}
