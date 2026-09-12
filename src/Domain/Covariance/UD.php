<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Covariance;

use OpenCCK\Kalman\Domain\Contract\CovarianceRepresentation;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Exception\NotPositiveDefinite;

/**
 * UD factorisation P = U·D·Uᵀ (U upper unit-triangular, D diagonal),
 * Bierman observational update + Thornton (MWGS) temporal update (§2.5.4).
 * Grewal & Andrews, "Kalman Filtering: Theory and Practice", ch. 6.
 *
 * No square roots, positive semi-definite by construction. The two-phase
 * protocol keeps f = Uᵀhᵀ and v = D·f between prepareScalar() and
 * commitScalar(); phi = P·hᵀ = U·v is the gain numerator.
 *
 * U is stored as a full n² row-major array with an explicit unit diagonal
 * (the strictly lower part is zero and never read).
 */
final class UD implements CovarianceRepresentation
{
	private int $n;

	/** @var array<int, float> n² upper unit-triangular */
	private array $U;

	/** @var array<int, float> n */
	private array $D;

	/** @var array<int, float> n: f = Uᵀ hᵀ */
	private array $f;

	/** @var array<int, float> n: v = D f (immutable between phases) */
	private array $v;

	/** @var array<int, float> n: working copy of v for Bierman */
	private array $b;

	/** @var array<int, float> n: phi = U v */
	private array $phi;

	/** @var array<int, float> n × 2n: W = [F·U | U_Q] for Thornton */
	private array $W;

	/** @var array<int, float> 2n weights [D ; D_Q] */
	private array $Dw;

	/** @var array<int, float> n² scratch: UD factor of Q */
	private array $UQ;

	/** @var array<int, float> n scratch */
	private array $DQ;

	/** @var array<int, float> n² scratch for dense reconstruction */
	private array $dense;

	public function __construct(int $n)
	{
		if ($n < 1) {
			throw new InvalidArgument('State size must be >= 1');
		}
		$this->n = $n;
		$this->U = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$this->U[$i * $n + $i] = 1.0;
		}
		$this->D = \array_fill(0, $n, 0.0);
		$this->f = \array_fill(0, $n, 0.0);
		$this->v = \array_fill(0, $n, 0.0);
		$this->b = \array_fill(0, $n, 0.0);
		$this->phi = \array_fill(0, $n, 0.0);
		$this->W = \array_fill(0, 2 * $n * $n, 0.0);
		$this->Dw = \array_fill(0, 2 * $n, 0.0);
		$this->UQ = \array_fill(0, $n * $n, 0.0);
		$this->DQ = \array_fill(0, $n, 0.0);
		$this->dense = \array_fill(0, $n * $n, 0.0);
	}

	public function size(): int
	{
		return $this->n;
	}

	// ───────────────────────────────────────────────────────── Thornton predict

	public function predictDense(array $F, array $Q): void
	{
		$n = $this->n;
		$N = 2 * $n;
		$U = &$this->U;
		$D = &$this->D;
		$W = &$this->W;
		$Dw = &$this->Dw;

		// UD factor of Q (semi-definite allowed) → columns n..2n-1 of W with weights D_Q
		self::factorInto($Q, $n, $this->UQ, $this->DQ);
		$UQ = &$this->UQ;
		$DQ = &$this->DQ;

		// W = [F·U | U_Q]
		for ($i = 0; $i < $n; $i++) {
			$iN = $i * $N;
			$in = $i * $n;
			for ($j = 0; $j < $n; $j++) {
				// (F·U)[i][j] = Σ_{k≤j} F[i][k] U[k][j]
				$sum = 0.0;
				for ($k = 0; $k <= $j; $k++) {
					$sum += $F[$in + $k] * $U[$k * $n + $j];
				}
				$W[$iN + $j] = $sum;
				$W[$iN + $n + $j] = $UQ[$in + $j];
			}
		}
		for ($k = 0; $k < $n; $k++) {
			$Dw[$k] = $D[$k];
			$Dw[$n + $k] = $DQ[$k];
		}

		$this->mwgs();
	}

	public function predictSparse(SparseMotionModel $model, float $dt, array &$x, array $Q): void
	{
		// UD cannot apply the dense in-place kernel; use F explicitly.
		$n = $this->n;
		$F = $model->transition($dt);
		$u = $model->control($dt);
		$t = $this->b; // reuse scratch
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

	/**
	 * Modified weighted Gram–Schmidt on W (n × 2n) with weights Dw:
	 * finds U, D with U·D·Uᵀ = W·diag(Dw)·Wᵀ. Rows processed from last to first.
	 */
	private function mwgs(): void
	{
		$n = $this->n;
		$N = 2 * $n;
		$U = &$this->U;
		$D = &$this->D;
		$W = &$this->W;
		$Dw = &$this->Dw;

		for ($j = $n - 1; $j >= 0; $j--) {
			$jN = $j * $N;
			$sigma = 0.0;
			for ($k = 0; $k < $N; $k++) {
				$w = $W[$jN + $k];
				$sigma += $w * $w * $Dw[$k];
			}
			$D[$j] = $sigma;
			$U[$j * $n + $j] = 1.0;
			if ($sigma <= 0.0) {
				for ($i = 0; $i < $j; $i++) {
					$U[$i * $n + $j] = 0.0;
				}
				continue;
			}
			$inv = 1.0 / $sigma;
			for ($i = 0; $i < $j; $i++) {
				$iN = $i * $N;
				$dot = 0.0;
				for ($k = 0; $k < $N; $k++) {
					$dot += $W[$iN + $k] * $W[$jN + $k] * $Dw[$k];
				}
				$uij = $dot * $inv;
				$U[$i * $n + $j] = $uij;
				if ($uij !== 0.0) {
					for ($k = 0; $k < $N; $k++) {
						$W[$iN + $k] -= $uij * $W[$jN + $k];
					}
				}
			}
		}
	}

	// ─────────────────────────────────────────────────────────── Bierman correct

	public function prepareScalar(array $h, float $r): float
	{
		$n = $this->n;
		$U = &$this->U;
		$D = &$this->D;
		$f = &$this->f;
		$v = &$this->v;
		$phi = &$this->phi;

		// f = Uᵀ hᵀ : f_j = Σ_{i≤j} U[i][j] h_i  (h sparse)
		for ($j = 0; $j < $n; $j++) {
			$f[$j] = 0.0;
		}
		foreach ($h as $i => $c) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$f[$j] += $U[$in + $j] * $c;
			}
		}
		// v = D f, hPh = Σ f_j v_j
		$hPh = 0.0;
		for ($j = 0; $j < $n; $j++) {
			$vj = $D[$j] * $f[$j];
			$v[$j] = $vj;
			$hPh += $f[$j] * $vj;
		}
		// phi = U v : phi_i = Σ_{j≥i} U[i][j] v_j
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			$sum = 0.0;
			for ($j = $i; $j < $n; $j++) {
				$sum += $U[$in + $j] * $v[$j];
			}
			$phi[$i] = $sum;
		}
		return $hPh + $r;
	}

	public function gain(): array
	{
		return $this->phi;
	}

	public function commitScalar(float $r): void
	{
		$n = $this->n;
		$U = &$this->U;
		$D = &$this->D;
		$f = &$this->f;
		$b = &$this->b;
		$v = &$this->v;

		for ($j = 0; $j < $n; $j++) {
			$b[$j] = $v[$j];
		}

		$alpha = $r + $b[0] * $f[0];
		$gamma = 1.0 / $alpha;
		$D[0] = $D[0] * $r * $gamma;
		for ($j = 1; $j < $n; $j++) {
			$beta = $alpha;
			$alpha += $b[$j] * $f[$j];
			$lambda = -$f[$j] * $gamma;
			$gamma = 1.0 / $alpha;
			$D[$j] = $beta * $gamma * $D[$j];
			$bj = $b[$j];
			for ($i = 0; $i < $j; $i++) {
				$idx = $i * $n + $j;
				$uij = $U[$idx];
				$U[$idx] = $uij + $b[$i] * $lambda;
				$b[$i] += $bj * $uij;
			}
		}
	}

	// ───────────────────────────────────────────────────────────── conversions

	public function toDense(): array
	{
		$n = $this->n;
		$U = &$this->U;
		$D = &$this->D;
		$P = &$this->dense;
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$jn = $j * $n;
				// P[i][j] = Σ_{k≥j} U[i][k] D[k] U[j][k]   (k ≥ max(i,j) = j)
				$sum = 0.0;
				for ($k = $j; $k < $n; $k++) {
					$sum += $U[$in + $k] * $D[$k] * $U[$jn + $k];
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
		self::factorInto($P, $n, $this->U, $this->D);
		for ($i = 0; $i < $n; $i++) {
			if ($this->D[$i] < 0.0) {
				throw NotPositiveDefinite::atPivot($i, $this->D[$i]);
			}
		}
	}

	public function variance(int $i): float
	{
		$n = $this->n;
		$in = $i * $n;
		$sum = 0.0;
		for ($k = $i; $k < $n; $k++) {
			$u = $this->U[$in + $k];
			$sum += $u * $u * $this->D[$k];
		}
		return $sum;
	}

	/** @return array<int, float> */
	public function factorU(): array
	{
		return $this->U;
	}

	/** @return array<int, float> */
	public function factorD(): array
	{
		return $this->D;
	}

	/**
	 * UD factorisation of a symmetric positive semi-definite matrix into
	 * preallocated buffers (Grewal & Andrews `udu`). Zero pivots produce
	 * zero columns (singular directions, e.g. noise-free states in Q).
	 *
	 * @param array<int, float> $P n² row-major
	 * @param array<int, float> $U n² out
	 * @param array<int, float> $D n out
	 */
	public static function factorInto(array $P, int $n, array &$U, array &$D): void
	{
		$scale = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$d = $P[$i * $n + $i];
			if ($d > $scale) {
				$scale = $d;
			}
		}
		$eps = 1e-14 * ($scale > 0.0 ? $scale : 1.0);

		for ($j = $n - 1; $j >= 0; $j--) {
			$jn = $j * $n;
			$sum = $P[$jn + $j];
			for ($k = $j + 1; $k < $n; $k++) {
				$u = $U[$jn + $k];
				$sum -= $u * $u * $D[$k];
			}
			if ($sum < -$eps) {
				throw NotPositiveDefinite::atPivot($j, $sum);
			}
			if ($sum <= $eps) {
				$sum = 0.0;
			}
			$D[$j] = $sum;
			$U[$jn + $j] = 1.0;
			for ($i = 0; $i < $j; $i++) {
				$U[$jn + $i] = 0.0; // strictly lower part stays zero
			}
			if ($sum === 0.0) {
				for ($i = 0; $i < $j; $i++) {
					$U[$i * $n + $j] = 0.0;
				}
				continue;
			}
			$inv = 1.0 / $sum;
			for ($i = 0; $i < $j; $i++) {
				$in = $i * $n;
				$s = $P[$in + $j];
				for ($k = $j + 1; $k < $n; $k++) {
					$s -= $U[$in + $k] * $U[$jn + $k] * $D[$k];
				}
				$U[$in + $j] = $s * $inv;
			}
		}
	}
}
