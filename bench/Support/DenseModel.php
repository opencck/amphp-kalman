<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench\Support;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;

/**
 * Deterministic dense n-state model for benchmarks: stable F = I + 0.1·dt·A,
 * SPD Q, m channels each observing 1–3 states. No RNG dependency.
 */
final class DenseModel implements MotionModel, StationaryModel
{
	/** @var array<int, float> */
	private array $A;

	/** @var array<int, float> */
	private array $Qc;

	public function __construct(private readonly int $n)
	{
		$A = [];
		for ($i = 0; $i < $n * $n; $i++) {
			$A[] = \sin(0.7 * $i + 0.3) * 0.5;
		}
		$this->A = $A;
		$B = [];
		for ($i = 0; $i < $n * $n; $i++) {
			$B[] = \cos(0.37 * $i) * 0.3;
		}
		$Q = Flat::multiplyTransposed($B, $B, $n, $n, $n);
		for ($i = 0; $i < $n; $i++) {
			$Q[$i * $n + $i] += 0.05;
		}
		$this->Qc = Flat::symmetrize($Q, $n);
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
			$F[$i] += 0.1 * $dt * $this->A[$i];
		}
		return $F;
	}

	public function processNoise(float $dt): array
	{
		return Flat::scale($this->Qc, $dt);
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function observation(int $m): StaticObservation
	{
		$rows = [];
		$variances = [];
		for ($c = 0; $c < $m; $c++) {
			$row = [$c % $this->n => 1.0];
			if ($this->n > 1) {
				$row[($c + 1) % $this->n] = 0.5;
			}
			if ($this->n > 2) {
				$row[($c + 2) % $this->n] = -0.25;
			}
			$rows[] = $row;
			$variances[] = 0.1 + 0.05 * $c;
		}
		return new StaticObservation($rows, $variances);
	}

	/** @return array<int, float> */
	public function initialCovariance(): array
	{
		$n = $this->n;
		$P = Flat::scale($this->Qc, 10.0);
		for ($i = 0; $i < $n; $i++) {
			$P[$i * $n + $i] += 1.0;
		}
		return $P;
	}
}
