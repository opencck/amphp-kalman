<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Support;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * Random stable dense linear model for reference tests: F = I + small
 * perturbation (scaled by dt), Q = dt·(AAᵀ + εI), dense H, diagonal R.
 * Deliberately NOT sparse and NOT stationary-flagged so the dense
 * predict path is exercised.
 */
final class RandomLinearModel implements MotionModel
{
	/** @var array<int, float> */
	private array $A;

	/** @var array<int, float> */
	private array $Qc;

	/** @var array<int, float> */
	private array $H;

	/** @var array<int, float> */
	private array $R;

	/** @var array<int, float> */
	private array $u;

	public function __construct(Rng $rng, private readonly int $n, private readonly int $m, bool $withControl = true)
	{
		$this->A = $rng->matrix($n, $n, 0.3);
		$this->Qc = $rng->spdMatrix($n, 0.5, 0.05);
		$this->H = $rng->matrix($m, $n, 1.0);
		$this->R = [];
		for ($i = 0; $i < $m; $i++) {
			$this->R[] = $rng->uniformBetween(0.05, 2.0);
		}
		$this->u = $withControl ? $rng->vector($n, 0.1) : [];
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function transition(float $dt): array
	{
		$n = $this->n;
		$F = Flat::identity($n);
		for ($i = 0; $i < $n * $n; $i++) {
			$F[$i] += $dt * $this->A[$i];
		}
		return $F;
	}

	public function processNoise(float $dt): array
	{
		return Flat::scale($this->Qc, $dt);
	}

	public function control(float $dt): ?array
	{
		if ($this->u === []) {
			return null;
		}
		return Flat::scale($this->u, $dt);
	}

	/** @return list<array<int, float>> */
	public function rows(): array
	{
		$rows = [];
		for ($i = 0; $i < $this->m; $i++) {
			$rows[] = Flat::sparseRow(\array_slice($this->H, $i * $this->n, $this->n));
		}
		return $rows;
	}

	/** @return array<int, float> */
	public function variances(): array
	{
		return $this->R;
	}

	/** @return array<int, float> m×n */
	public function denseH(): array
	{
		return $this->H;
	}
}
