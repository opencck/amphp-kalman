<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Covariance;

use OpenCCK\Kalman\Domain\Contract\CovarianceRepresentation;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Dense P with the Joseph stabilised update (§2.5.1), specialised to one
 * scalar channel at a time:
 *
 *   K  = phi / s,   phi = P·hᵀ,   s = h·phi + r
 *   P ← (I − K h) P (I − K h)ᵀ + K r Kᵀ
 *      = P − K·phiᵀ − phi·Kᵀ + s·K·Kᵀ          (expanded, O(n²))
 *
 * Algebraically identical to the sequential rank-1 downdate for an exact K,
 * but symmetric and positive semi-definite for ANY K, so rounding in K
 * cannot make P indefinite. Costs ~2× the sequential update.
 */
final class Joseph implements CovarianceRepresentation
{
	private int $n;

	/** @var array<int, float> n² */
	private array $P;

	/** @var array<int, float> n² scratch F·P */
	private array $T;

	/** @var array<int, float> n */
	private array $phi;

	/** @var array<int, float> n scratch K */
	private array $K;

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
		$this->K = \array_fill(0, $n, 0.0);
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
		$K = &$this->K;
		$s = $this->hPh + $r;
		$inv = 1.0 / $s;
		for ($i = 0; $i < $n; $i++) {
			$K[$i] = $phi[$i] * $inv;
		}
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			$ki = $K[$i];
			$pi = $phi[$i];
			$ski = $s * $ki;
			for ($j = $i; $j < $n; $j++) {
				$v = $P[$in + $j] - $ki * $phi[$j] - $pi * $K[$j] + $ski * $K[$j];
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
