<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Covariance;

use OpenCCK\Kalman\Domain\Contract\CovarianceRepresentation;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Householder;

/**
 * Square-root covariance P = S·Sᵀ (§2.5.3): Potter scalar update and a
 * Householder time update.
 *
 * Potter (per channel):
 *   f = Sᵀhᵀ,  s = fᵀf + r,  phi = S·f = P·hᵀ
 *   γ = 1 / (1 + √(r/s)),   S ← S − (γ/s)·phi·fᵀ
 *
 * Time update: S⁻ = lower-triangular factor with S⁻S⁻ᵀ = F S Sᵀ Fᵀ + Q,
 * obtained as Rᵀ from the QR of [F·S | Q^{1/2}]ᵀ (2n × n).
 *
 * P is positive semi-definite by construction; the condition number of S
 * is the square root of that of P, so the effective precision is doubled.
 */
final class SquareRoot implements CovarianceRepresentation
{
	private int $n;

	/** @var array<int, float> n² : S with P = S Sᵀ */
	private array $S;

	/** @var array<int, float> n : f = Sᵀ hᵀ */
	private array $f;

	/** @var array<int, float> n : phi = S f */
	private array $phi;

	/** @var array<int, float> 2n × n : [F·S | L_Q]ᵀ for QR */
	private array $A;

	/** @var array<int, float> n scratch for Householder */
	private array $work;

	/** @var array<int, float> n² scratch (Cholesky of Q, dense P) */
	private array $LQ;

	/** @var array<int, float> n² */
	private array $dense;

	private float $fTf = 0.0;

	public function __construct(int $n)
	{
		if ($n < 1) {
			throw new InvalidArgument('State size must be >= 1');
		}
		$this->n = $n;
		$this->S = \array_fill(0, $n * $n, 0.0);
		$this->f = \array_fill(0, $n, 0.0);
		$this->phi = \array_fill(0, $n, 0.0);
		$this->A = \array_fill(0, 2 * $n * $n, 0.0);
		$this->work = \array_fill(0, $n, 0.0);
		$this->LQ = \array_fill(0, $n * $n, 0.0);
		$this->dense = \array_fill(0, $n * $n, 0.0);
	}

	public function size(): int
	{
		return $this->n;
	}

	public function predictDense(array $F, array $Q): void
	{
		$n = $this->n;
		$S = &$this->S;
		$A = &$this->A;

		// L_Q = chol(Q) (semi-definite allowed)
		$this->LQ = Cholesky::decomposeSemidefinite($Q, $n);
		$LQ = &$this->LQ;

		// A = [F·S | L_Q]ᵀ : rows 0..n-1 = (F·S)ᵀ, rows n..2n-1 = L_Qᵀ ; A is 2n × n
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = 0; $j < $n; $j++) {
				$sum = 0.0;
				for ($k = 0; $k < $n; $k++) {
					$sum += $F[$in + $k] * $S[$k * $n + $j];
				}
				// (F·S)[i][j] goes to Aᵀ position [j][i]
				$A[$j * $n + $i] = $sum;
				$A[($n + $j) * $n + $i] = $LQ[$in + $j];
			}
		}

		Householder::triangularizeInPlace($A, 2 * $n, $n, $this->work);

		// S = Rᵀ (lower triangular)
		for ($i = 0; $i < $n; $i++) {
			for ($j = 0; $j < $n; $j++) {
				$S[$i * $n + $j] = $j <= $i ? $A[$j * $n + $i] : 0.0;
			}
		}
	}

	public function predictSparse(SparseMotionModel $model, float $dt, array &$x, array $Q): void
	{
		$n = $this->n;
		$F = $model->transition($dt);
		$u = $model->control($dt);
		$t = &$this->work;
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			$sum = 0.0;
			for ($j = 0; $j < $n; $j++) {
				$sum += $F[$in + $j] * $x[$j];
			}
			$t[$i] = $sum;
		}
		for ($i = 0; $i < $n; $i++) {
			$x[$i] = $t[$i] + ($u === null ? 0.0 : $u[$i]);
		}
		$this->predictDense($F, $Q);
	}

	public function prepareScalar(array $h, float $r): float
	{
		$n = $this->n;
		$S = &$this->S;
		$f = &$this->f;
		$phi = &$this->phi;

		// f = Sᵀ hᵀ : f_j = Σ_i S[i][j] h_i
		for ($j = 0; $j < $n; $j++) {
			$f[$j] = 0.0;
		}
		foreach ($h as $i => $c) {
			$in = $i * $n;
			for ($j = 0; $j < $n; $j++) {
				$f[$j] += $S[$in + $j] * $c;
			}
		}
		$fTf = 0.0;
		for ($j = 0; $j < $n; $j++) {
			$fTf += $f[$j] * $f[$j];
		}
		// phi = S f
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			$sum = 0.0;
			for ($j = 0; $j < $n; $j++) {
				$sum += $S[$in + $j] * $f[$j];
			}
			$phi[$i] = $sum;
		}
		$this->fTf = $fTf;
		return $fTf + $r;
	}

	public function gain(): array
	{
		return $this->phi;
	}

	public function commitScalar(float $r): void
	{
		$n = $this->n;
		$S = &$this->S;
		$f = &$this->f;
		$phi = &$this->phi;
		$s = $this->fTf + $r;
		$gamma = 1.0 / (1.0 + \sqrt($r / $s));
		$c = $gamma / $s;
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			$pi = $phi[$i] * $c;
			if ($pi === 0.0) {
				continue;
			}
			for ($j = 0; $j < $n; $j++) {
				$S[$in + $j] -= $pi * $f[$j];
			}
		}
	}

	public function toDense(): array
	{
		$n = $this->n;
		$S = &$this->S;
		$P = &$this->dense;
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$jn = $j * $n;
				$sum = 0.0;
				for ($k = 0; $k < $n; $k++) {
					$sum += $S[$in + $k] * $S[$jn + $k];
				}
				$P[$in + $j] = $sum;
				$P[$jn + $i] = $sum;
			}
		}
		return $P;
	}

	public function fromDense(array $P): void
	{
		$n = $this->n;
		if (\count($P) !== $n * $n) {
			throw DimensionMismatch::forMatrix('P', $n * $n, \count($P));
		}
		$this->S = Cholesky::decompose($P, $n);
	}

	public function variance(int $i): float
	{
		$n = $this->n;
		$in = $i * $n;
		$sum = 0.0;
		for ($k = 0; $k < $n; $k++) {
			$s = $this->S[$in + $k];
			$sum += $s * $s;
		}
		return $sum;
	}

	/** @return array<int, float> */
	public function factor(): array
	{
		return $this->S;
	}
}
