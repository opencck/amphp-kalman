<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Covariance;

use FFI\CData;
use OpenCCK\Kalman\Domain\Contract\CovarianceRepresentation;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Linalg\Ffi\BlasBackend;

/**
 * §6.3 dense P in an FFI double[] buffer with the sequential scalar update
 * expressed in CBLAS calls (same algebra as DenseSequential):
 *
 *   predict:  T = F·P (dsymm),  P = T·Fᵀ (dgemm),  P += Q (daxpy); F and Q are copied into
 *             FFI buffers only when the model hands a different array (stationary models: never)
 *   prepare:  φ = P·hᵀ (dsymv; h densified into a buffer)
 *   commit:   P ← P − φφᵀ/s (dsyr)
 *
 * Only the UPPER triangle of P is authoritative: dgemm writes a full matrix
 * whose two halves may differ in the last bit and dger would keep that
 * asymmetry, so every routine that reads or updates P uses the symmetric
 * (Upper) variants; toDense() mirrors the upper half. Exact symmetry by
 * construction and no PHP O(n²) loop per channel. Opt-in via FilterConfig::withBackend(Backend::Blas);
 * the crossover with pure PHP is measured by bench/LayoutBench (blas_n*).
 */
final class BlasDense implements CovarianceRepresentation
{
	private int $n;
	private BlasBackend $blas;
	private CData $P;
	private CData $T;
	private CData $Fbuf;
	private CData $Qbuf;
	private CData $phi;
	private CData $hDense;

	/** @var array<int, float>|null the F last copied into Fbuf (stationary models hand the same array every step) */
	private ?array $lastF = null;

	/** @var array<int, float>|null */
	private ?array $lastQ = null;

	/** scratch double[n] holding the pending h·P·hᵀ vector product */
	private float $hPh = 0.0;

	public function __construct(int $n, ?BlasBackend $blas = null)
	{
		if ($n < 1) {
			throw new InvalidArgument('State size must be >= 1');
		}
		$this->n = $n;
		$this->blas = $blas ?? BlasBackend::load();
		$this->P = $this->blas->matrix($n);
		$this->T = $this->blas->matrix($n);
		$this->Fbuf = $this->blas->matrix($n);
		$this->Qbuf = $this->blas->matrix($n);
		$this->phi = $this->blas->vector($n);
		$this->hDense = $this->blas->vector($n);
	}

	public function size(): int
	{
		return $this->n;
	}

	public function predictDense(array $F, array $Q): void
	{
		$n = $this->n;
		$nn = $n * $n;
		// the PHP → FFI copies are the only O(n²) PHP work per step; skip them while the model hands the same
		// arrays (cached F(dt), Q(dt) of a stationary model) — an array identity check is a C-level compare
		if ($this->lastF !== $F) {
			BlasBackend::fill($this->Fbuf, $F, $nn);
			$this->lastF = $F;
		}
		if ($this->lastQ !== $Q) {
			BlasBackend::fill($this->Qbuf, $Q, $nn);
			$this->lastQ = $Q;
		}
		// T = F·P — dsymm reads only the (authoritative) upper triangle of P
		$this->blas->symmRightUpper($n, $this->P, $this->Fbuf, $this->T);
		// P = T·Fᵀ, then P += Q
		$this->blas->gemm($n, false, true, 1.0, $this->T, $this->Fbuf, 0.0, $this->P);
		$this->blas->axpy($nn, 1.0, $this->Qbuf, 0, $this->P);
	}

	public function predictSparse(SparseMotionModel $model, float $dt, array &$x, array $Q): void
	{
		// sparse models are written against PHP arrays: round-trip through them (O(n²) copies)
		$n = $this->n;
		$P = $this->toDense();
		$model->advanceInPlace($x, $P, $dt);
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$v = $P[$in + $j] + $Q[$in + $j];
				$P[$in + $j] = $v;
				$P[$j * $n + $i] = $v;
			}
		}
		BlasBackend::fill($this->P, $P, $n * $n);
	}

	public function prepareScalar(array $h, float $r): float
	{
		$n = $this->n;
		$hd = $this->hDense;
		for ($i = 0; $i < $n; $i++) {
			$hd[$i] = 0.0;
		}
		foreach ($h as $j => $c) {
			$hd[$j] = $c;
		}
		$this->blas->symvUpper($n, $this->P, $hd, $this->phi);
		$phi = $this->phi;
		$hPh = 0.0;
		foreach ($h as $j => $c) {
			$hPh += $c * BlasBackend::valueAt($phi, $j);
		}
		$this->hPh = $hPh;
		return $hPh + $r;
	}

	public function gain(): array
	{
		return BlasBackend::toArray($this->phi, $this->n);
	}

	public function commitScalar(float $r): void
	{
		$this->blas->syrUpper($this->n, -1.0 / ($this->hPh + $r), $this->phi, $this->P);
	}

	public function toDense(): array
	{
		$n = $this->n;
		$P = $this->P;
		$out = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$v = BlasBackend::valueAt($P, $in + $j);
				$out[$in + $j] = $v;
				$out[$j * $n + $i] = $v;
			}
		}
		return $out;
	}

	public function fromDense(array $P): void
	{
		$n = $this->n;
		if (\count($P) !== $n * $n) {
			throw DimensionMismatch::forMatrix('P', $n * $n, \count($P));
		}
		$dst = $this->P;
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i; $j < $n; $j++) {
				$v = $P[$in + $j];
				$dst[$in + $j] = $v;
				$dst[$j * $n + $i] = $v;
			}
		}
	}

	public function variance(int $i): float
	{
		return BlasBackend::valueAt($this->P, $i * $this->n + $i);
	}
}
