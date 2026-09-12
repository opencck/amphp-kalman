<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Covariance;

use OpenCCK\Kalman\Domain\Contract\CovarianceRepresentation;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Dense P with sequential scalar correction (§2.5.2).
 *
 *   phi = P·hᵀ            O(n·nnz(h))
 *   s   = h·phi + r
 *   P  ← P − phi·phiᵀ/s   symmetric rank-1 downdate on the upper triangle, mirrored
 *
 * No matrix inversion anywhere. Symmetry is exact by construction.
 * Weak spot: r ≪ h·P·hᵀ can drive P indefinite through cancellation —
 * use UD or SquareRoot in that regime.
 *
 * All buffers are allocated in the constructor; predict/correct allocate nothing.
 */
final class DenseSequential implements CovarianceRepresentation
{
	private int $n;

	/** @var array<int, float> n² */
	private array $P;

	/** @var array<int, float> n² scratch for F·P */
	private array $T;

	/** @var array<int, float> n gain numerator of the pending scalar correction */
	private array $phi;

	/** h·P·hᵀ of the pending correction */
	private float $hPh = 0.0;

	public function __construct(int $n)
	{
		if ($n < 1) {
			throw new InvalidArgument('State size must be >= 1');
		}
		$this->n = $n;
		$this->P = \array_fill(0, $n * $n, 0.0);
		$this->T = \array_fill(0, $n * $n, 0.0);
		$this->phi = \array_fill(0, $n, 0.0);
	}

	public function size(): int
	{
		return $this->n;
	}

	public function predictDense(array $F, array $Q): void
	{
		$n = $this->n;
		$P = &$this->P;
		$T = &$this->T;

		// T = F·P
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = 0; $j < $n; $j++) {
				$sum = 0.0;
				for ($k = 0; $k < $n; $k++) {
					$sum += $F[$in + $k] * $P[$k * $n + $j];
				}
				$T[$in + $j] = $sum;
			}
		}
		// P = T·Fᵀ + Q, upper triangle then mirror
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$jn = $j * $n;
				$sum = $Q[$in + $j];
				for ($k = 0; $k < $n; $k++) {
					$sum += $T[$in + $k] * $F[$jn + $k];
				}
				$P[$in + $j] = $sum;
				$P[$jn + $i] = $sum;
			}
		}
	}

	public function predictSparse(SparseMotionModel $model, float $dt, array &$x, array $Q): void
	{
		$model->advanceInPlace($x, $this->P, $dt);
		$n = $this->n;
		$P = &$this->P;
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$v = $P[$in + $j] + $Q[$in + $j];
				$P[$in + $j] = $v;
				$P[$j * $n + $i] = $v;
			}
		}
	}

	public function prepareScalar(array $h, float $r): float
	{
		$n = $this->n;
		$P = &$this->P;
		$phi = &$this->phi;

		// phi = P·hᵀ (P symmetric → row i of P dotted with sparse h)
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			$sum = 0.0;
			foreach ($h as $j => $c) {
				$sum += $P[$in + $j] * $c;
			}
			$phi[$i] = $sum;
		}
		$hPh = 0.0;
		foreach ($h as $j => $c) {
			$hPh += $c * $phi[$j];
		}
		$this->hPh = $hPh;
		return $hPh + $r;
	}

	public function gain(): array
	{
		return $this->phi;
	}

	public function commitScalar(float $r): void
	{
		$n = $this->n;
		$P = &$this->P;
		$phi = &$this->phi;
		$inv = 1.0 / ($this->hPh + $r);

		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			$pi = $phi[$i] * $inv;
			if ($pi === 0.0) {
				continue;
			}
			for ($j = $i; $j < $n; $j++) {
				$v = $P[$in + $j] - $pi * $phi[$j];
				$P[$in + $j] = $v;
				$P[$j * $n + $i] = $v;
			}
		}
	}

	public function toDense(): array
	{
		return $this->P;
	}

	public function fromDense(array $P): void
	{
		$n = $this->n;
		if (\count($P) !== $n * $n) {
			throw DimensionMismatch::forMatrix('P', $n * $n, \count($P));
		}
		// copy with exact symmetrisation from the upper triangle
		$dst = &$this->P;
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$v = (float) $P[$in + $j];
				$dst[$in + $j] = $v;
				$dst[$j * $n + $i] = $v;
			}
		}
	}

	public function variance(int $i): float
	{
		return $this->P[$i * $this->n + $i];
	}
}
