<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

/**
 * §6.3 linear-algebra backend for the dense covariance kernels.
 *
 *   Php   pure PHP over flat arrays (default; fastest for n ≤ 16 under JIT)
 *   Blas  OpenBLAS through ext-ffi (Domain\Linalg\Ffi\BlasBackend); opt-in,
 *         only pays off for large n where the FFI call overhead (~0.5 µs per
 *         call) is amortised by O(n³) work. Sequential form only.
 */
enum Backend: string
{
	case Php = 'php';
	case Blas = 'blas';
}
