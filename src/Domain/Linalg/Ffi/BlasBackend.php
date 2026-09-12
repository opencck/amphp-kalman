<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Linalg\Ffi;

use FFI;
use FFI\CData;
use OpenCCK\Kalman\Domain\Exception\UnsupportedOperation;

/**
 * §6.3 thin wrapper over the handful of CBLAS routines the dense kernels
 * need (dgemm, dsymm, dsyr, dsymv, dger, daxpy, dcopy, ddot), loaded through ext-ffi. Only a shared OpenBLAS (or any CBLAS ABI
 * compatible library) is required — no compilation step.
 *
 * Library resolution: $library argument → env KALMAN_BLAS_LIB → platform
 * defaults (libopenblas.so.0, libopenblas.so, libopenblas.dylib,
 * libopenblas.dll, openblas.dll). isAvailable() never throws.
 *
 * Every method maps 1:1 onto a CBLAS call with row-major layout; matrices
 * are FFI double[] buffers created with matrix()/vector(). The wrapper is
 * stateless apart from the FFI handle, so one instance is shared per process.
 */
final class BlasBackend
{
	private const ROW_MAJOR = 101;
	private const NO_TRANS = 111;
	private const TRANS = 112;
	private const UPPER = 121;
	private const RIGHT = 142;

	private const CDEF = <<<'C'
void cblas_dgemm(int Order, int TransA, int TransB, int M, int N, int K, double alpha, const double *A, int lda, const double *B, int ldb, double beta, double *C, int ldc);
void cblas_dger(int Order, int M, int N, double alpha, const double *X, int incX, const double *Y, int incY, double *A, int lda);
void cblas_dsyr(int Order, int Uplo, int N, double alpha, const double *X, int incX, double *A, int lda);
void cblas_dsymm(int Order, int Side, int Uplo, int M, int N, double alpha, const double *A, int lda, const double *B, int ldb, double beta, double *C, int ldc);
void cblas_dsymv(int Order, int Uplo, int N, double alpha, const double *A, int lda, const double *X, int incX, double beta, double *Y, int incY);
void cblas_daxpy(int n, double alpha, const double *x, int incx, double *y, int incy);
void cblas_dcopy(int n, const double *x, int incx, double *y, int incy);
double cblas_ddot(int n, const double *x, int incx, const double *y, int incy);
C;

	private static ?self $shared = null;
	private static ?string $unavailableReason = null;

	private function __construct(
		private readonly FFI $ffi,
		private readonly string $library,
	) {
	}

	/** @return array<int, string> */
	public static function candidateLibraries(): array
	{
		$env = \getenv('KALMAN_BLAS_LIB');
		$candidates = [];
		if (\is_string($env) && $env !== '') {
			$candidates[] = $env;
		}
		return [...$candidates, 'libopenblas.so.0', 'libopenblas.so', 'libopenblas.dylib', 'libopenblas.dll', 'openblas.dll'];
	}

	/** True when ext-ffi is loaded and a CBLAS library could be bound. Cached per process. */
	public static function isAvailable(): bool
	{
		if (self::$shared !== null) {
			return true;
		}
		if (self::$unavailableReason !== null) {
			return false;
		}
		try {
			self::load();
			return true;
		} catch (UnsupportedOperation) {
			return false;
		}
	}

	/** Why isAvailable() is false (null while available or not yet probed). */
	public static function unavailableReason(): ?string
	{
		return self::$unavailableReason;
	}

	/**
	 * Binds the library (once per process unless $library is given explicitly).
	 *
	 * @throws UnsupportedOperation when ext-ffi is missing or no library loads
	 */
	public static function load(?string $library = null): self
	{
		if ($library === null && self::$shared !== null) {
			return self::$shared;
		}
		if (!\extension_loaded('ffi') || !\class_exists(FFI::class)) {
			self::$unavailableReason = 'ext-ffi is not loaded';
			throw new UnsupportedOperation('BLAS backend: ext-ffi is not loaded');
		}
		$candidates = $library !== null ? [$library] : self::candidateLibraries();
		$errors = [];
		foreach ($candidates as $candidate) {
			try {
				$ffi = FFI::cdef(self::CDEF, $candidate);
				$backend = new self($ffi, $candidate);
				if ($library === null) {
					self::$shared = $backend;
					self::$unavailableReason = null;
				}
				return $backend;
			} catch (\Throwable $e) {
				$errors[] = $candidate . ': ' . $e->getMessage();
			}
		}
		$reason = 'no CBLAS library could be loaded (' . \implode('; ', $errors) . ')';
		if ($library === null) {
			self::$unavailableReason = $reason;
		}
		throw new UnsupportedOperation('BLAS backend: ' . $reason);
	}

	public function library(): string
	{
		return $this->library;
	}

	/** Zero-initialised n×n row-major double buffer. */
	public function matrix(int $n): CData
	{
		/** @var CData $m */
		$m = $this->ffi->new("double[" . ($n * $n) . "]");
		return $m;
	}

	/** Zero-initialised double[n] buffer. */
	public function vector(int $n): CData
	{
		/** @var CData $v */
		$v = $this->ffi->new("double[$n]");
		return $v;
	}

	/**
	 * Address of element $offset of a double[] buffer.
	 *
	 * CData exposes element access through the extension rather than ArrayAccess,
	 * so a static analyser types `$buffer[$i]` as mixed|null. Every buffer here is a
	 * `double[]` created by matrix()/vector() and every offset is inside it, so the
	 * element always exists; this is the one place that states it.
	 */
	private static function addr(CData $buffer, int $offset = 0): CData
	{
		// The dim-fetch MUST stay inline: assigning $buffer[$offset] to a variable first
		// converts the element to a PHP float and FFI::addr() then fails with a TypeError.
		/** @psalm-suppress MixedArgument, PossiblyNullArgument */
		return FFI::addr($buffer[$offset]);
	}

	/** C = alpha·op(A)·op(B) + beta·C for row-major n×n matrices. */
	public function gemm(int $n, bool $transA, bool $transB, float $alpha, CData $A, CData $B, float $beta, CData $C): void
	{
		$this->call(
			'cblas_dgemm',
			self::ROW_MAJOR,
			$transA ? self::TRANS : self::NO_TRANS,
			$transB ? self::TRANS : self::NO_TRANS,
			$n,
			$n,
			$n,
			$alpha,
			self::addr($A),
			$n,
			self::addr($B),
			$n,
			$beta,
			self::addr($C),
			$n,
		);
	}

	/** A += alpha·x·yᵀ over the full n×n matrix (exactly symmetric when x = y). */
	public function ger(int $n, float $alpha, CData $x, CData $y, CData $A): void
	{
		$this->call('cblas_dger', self::ROW_MAJOR, $n, $n, $alpha, self::addr($x), 1, self::addr($y), 1, self::addr($A), $n);
	}

	/** C = B·A for a symmetric n×n A stored in its upper triangle (B, C general n×n). */
	public function symmRightUpper(int $n, CData $A, CData $B, CData $C): void
	{
		$this->call('cblas_dsymm', self::ROW_MAJOR, self::RIGHT, self::UPPER, $n, $n, 1.0, self::addr($A), $n, self::addr($B), $n, 0.0, self::addr($C), $n);
	}

	/** Upper triangle of A += alpha·x·xᵀ (the lower triangle is not touched). */
	public function syrUpper(int $n, float $alpha, CData $x, CData $A): void
	{
		$this->call('cblas_dsyr', self::ROW_MAJOR, self::UPPER, $n, $alpha, self::addr($x), 1, self::addr($A), $n);
	}

	/** y = A·x for a symmetric A stored in its upper triangle. */
	public function symvUpper(int $n, CData $A, CData $x, CData $y): void
	{
		$this->call('cblas_dsymv', self::ROW_MAJOR, self::UPPER, $n, 1.0, self::addr($A), $n, self::addr($x), 1, 0.0, self::addr($y), 1);
	}

	/** y += alpha·x over n entries; $xOffset selects a row inside a matrix buffer. */
	public function axpy(int $n, float $alpha, CData $x, int $xOffset, CData $y): void
	{
		$this->call('cblas_daxpy', $n, $alpha, self::addr($x, $xOffset), 1, self::addr($y), 1);
	}

	/** y = x over n entries. */
	public function copy(int $n, CData $x, CData $y): void
	{
		$this->call('cblas_dcopy', $n, self::addr($x), 1, self::addr($y), 1);
	}

	public function dot(int $n, CData $x, CData $y): float
	{
		$r = $this->call('cblas_ddot', $n, self::addr($x), 1, self::addr($y), 1);
		if (!\is_float($r)) {
			throw new UnsupportedOperation('cblas_ddot returned a non-float');
		}
		return $r;
	}

	/** C symbols are late-bound on the FFI object; one dispatch point keeps the wrapper static-analysis clean. */
	private function call(string $symbol, mixed ...$args): mixed
	{
		return $this->ffi->$symbol(...$args);
	}

	/**
	 * PHP array → buffer.
	 *
	 * @param array<int, float> $src
	 */
	public static function fill(CData $dst, array $src, int $count): void
	{
		for ($i = 0; $i < $count; $i++) {
			$dst[$i] = $src[$i];
		}
	}

	/** Reads element $i of a double[] buffer (see addr() for why the annotation is needed). */
	public static function valueAt(CData $buffer, int $i): float
	{
		/** @var float $v */
		$v = $buffer[$i];
		return $v;
	}

	/**
	 * Buffer → PHP array.
	 *
	 * @return array<int, float>
	 */
	public static function toArray(CData $src, int $count): array
	{
		$out = \array_fill(0, $count, 0.0);
		for ($i = 0; $i < $count; $i++) {
			$out[$i] = self::valueAt($src, $i);
		}
		return $out;
	}
}
