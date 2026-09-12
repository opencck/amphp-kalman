<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Simulation;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Tests\Support\Rng;

/**
 * Generates a true trajectory and noisy observations from exactly the
 * model the filter assumes (§7.4). Used for NEES/NIS consistency tests.
 */
final class LinearGaussianSimulator
{
	private Rng $rng;

	/** @var array<int, float> */
	private array $truth;

	private int $n;
	private int $m;

	/**
	 * @param array<int, float> $x0 true initial state
	 */
	public function __construct(
		private readonly MotionModel $motion,
		private readonly ObservationModel $observation,
		array $x0,
		int $seed,
	) {
		$this->rng = new Rng($seed);
		$this->truth = $x0;
		$this->n = $motion->stateSize();
		$this->m = $observation->channelCount();
	}

	/**
	 * Draws the initial state from N(x0, P0) so that the filter prior is
	 * exactly right — required for NEES to be χ²(n) from step one.
	 *
	 * @param array<int, float> $x0
	 * @param array<int, float> $P0
	 */
	public function drawInitialFrom(array $x0, array $P0): void
	{
		$noise = $this->rng->multivariateNormal($P0, $this->n);
		$this->truth = [];
		for ($i = 0; $i < $this->n; $i++) {
			$this->truth[] = $x0[$i] + $noise[$i];
		}
	}

	/** Advances the true state by dt. */
	public function advance(float $dt): void
	{
		if ($dt <= 0.0) {
			return;
		}
		$n = $this->n;
		$F = $this->motion->transition($dt);
		$Q = $this->motion->processNoise($dt);
		$x = Flat::matVec($F, $this->truth, $n, $n);
		$u = $this->motion->control($dt);
		if ($u !== null) {
			for ($i = 0; $i < $n; $i++) {
				$x[$i] += $u[$i];
			}
		}
		$w = $this->rng->multivariateNormal($Q, $n);
		for ($i = 0; $i < $n; $i++) {
			$x[$i] += $w[$i];
		}
		$this->truth = $x;
	}

	/**
	 * Observes the given channels (all when null) at the current true state.
	 *
	 * @param array<int, int>|null $channels
	 */
	public function observe(int $timestampNs, ?array $channels = null): Measurement
	{
		$channels ??= \range(0, $this->m - 1);
		$values = [];
		foreach ($channels as $c) {
			$row = $this->observation->channelRow($c);
			$z = 0.0;
			foreach ($row as $j => $coef) {
				$z += $coef * $this->truth[$j];
			}
			$z += $this->rng->normal() * \sqrt($this->observation->channelVariance($c));
			$values[$c] = $z;
		}
		return Measurement::at($timestampNs, $values);
	}

	/** @return array<int, float> */
	public function truth(): array
	{
		return $this->truth;
	}

	public function rng(): Rng
	{
		return $this->rng;
	}
}
