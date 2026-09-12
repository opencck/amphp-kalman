<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Bench\Support\Timer;
use OpenCCK\Kalman\Domain\Linalg\Ffi\BlasBackend;

/**
 * §4.4 memory layout: P ← F·P·Fᵀ (the dominant O(n³) kernel) implemented
 * over flat row-major arrays, nested arrays, SplFixedArray, FFI double[]
 * buffers driven by PHP loops (when ext-ffi is loaded) and CBLAS dgemm
 * through the §6.3 backend (when an OpenBLAS library is available).
 * Result → docs/decisions/ADR-001-memory-layout.md, ADR-006 (crossover).
 */
final class LayoutBench implements Benchmark
{
	public function name(): string
	{
		return 'layout';
	}

	public function run(): array
	{
		$out = [];
		$ffi = \extension_loaded('ffi');
		$blas = BlasBackend::isAvailable() ? BlasBackend::load() : null;
		foreach ([2, 4, 8, 16, 32, 64] as $n) {
			$iterations = (int) \max(20, 2_000_000 / ($n * $n * $n));
			$out["flat_n$n"] = Timer::microsPerOp(fn (int $it) => self::flat($n, $it), $iterations);
			$out["nested_n$n"] = Timer::microsPerOp(fn (int $it) => self::nested($n, $it), $iterations);
			$out["splfixed_n$n"] = Timer::microsPerOp(fn (int $it) => self::splFixed($n, $it), $iterations);
			if ($ffi) {
				$out["ffi_n$n"] = Timer::microsPerOp(fn (int $it) => self::ffi($n, $it), $iterations);
			}
			if ($blas !== null) {
				$out["blas_n$n"] = Timer::microsPerOp(fn (int $it) => self::blas($blas, $n, $it), $iterations);
			}
		}
		if ($blas !== null) {
			foreach ([128, 256] as $n) {
				$iterations = 20;
				$out["flat_n$n"] = Timer::microsPerOp(fn (int $it) => self::flat($n, $it), $iterations, repeats: 2);
				$out["blas_n$n"] = Timer::microsPerOp(fn (int $it) => self::blas($blas, $n, $it), $iterations);
			}
		}
		return $out;
	}

	/** @return float checksum of the result — returned so the measured work cannot be optimised away */
	private static function flat(int $n, int $iterations): float
	{
		/** @var array<int, float> $F */
		$F = [];
		/** @var array<int, float> $P */
		$P = [];
		for ($i = 0; $i < $n * $n; $i++) {
			$F[] = ($i % ($n + 1) === 0) ? 1.0 : 0.01 * ($i % 7);
			$P[] = ($i % ($n + 1) === 0) ? 1.0 : 0.001 * ($i % 5);
		}
		$T = \array_fill(0, $n * $n, 0.0);
		for ($it = 0; $it < $iterations; $it++) {
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
					$sum = 0.0;
					for ($k = 0; $k < $n; $k++) {
						$sum += $T[$in + $k] * $F[$jn + $k];
					}
					$P[$in + $j] = $sum;
					$P[$jn + $i] = $sum;
				}
			}
		}
		return $P[0];
	}

	/** @return float checksum (see flat()) */
	private static function nested(int $n, int $iterations): float
	{
		/** @var array<int, array<int, float>> $F */
		$F = [];
		/** @var array<int, array<int, float>> $P */
		$P = [];
		/** @var array<int, array<int, float>> $T */
		$T = [];
		for ($i = 0; $i < $n; $i++) {
			$F[$i] = [];
			$P[$i] = [];
			$T[$i] = \array_fill(0, $n, 0.0);
			for ($j = 0; $j < $n; $j++) {
				$idx = $i * $n + $j;
				$F[$i][$j] = ($i === $j) ? 1.0 : 0.01 * ($idx % 7);
				$P[$i][$j] = ($i === $j) ? 1.0 : 0.001 * ($idx % 5);
			}
		}
		for ($it = 0; $it < $iterations; $it++) {
			for ($i = 0; $i < $n; $i++) {
				$Fi = $F[$i];
				for ($j = 0; $j < $n; $j++) {
					$sum = 0.0;
					for ($k = 0; $k < $n; $k++) {
						$sum += $Fi[$k] * $P[$k][$j];
					}
					$T[$i][$j] = $sum;
				}
			}
			for ($i = 0; $i < $n; $i++) {
				$Ti = $T[$i];
				for ($j = $i; $j < $n; $j++) {
					$Fj = $F[$j];
					$sum = 0.0;
					for ($k = 0; $k < $n; $k++) {
						$sum += $Ti[$k] * $Fj[$k];
					}
					$P[$i][$j] = $sum;
					$P[$j][$i] = $sum;
				}
			}
		}
		return $P[0][0];
	}

	/** @return float checksum (see flat()) */
	private static function splFixed(int $n, int $iterations): float
	{
		/** @var \SplFixedArray<float> $F */
		$F = new \SplFixedArray($n * $n);
		/** @var \SplFixedArray<float> $P */
		$P = new \SplFixedArray($n * $n);
		/** @var \SplFixedArray<float> $T */
		$T = new \SplFixedArray($n * $n);
		for ($i = 0; $i < $n * $n; $i++) {
			$F[$i] = ($i % ($n + 1) === 0) ? 1.0 : 0.01 * ($i % 7);
			$P[$i] = ($i % ($n + 1) === 0) ? 1.0 : 0.001 * ($i % 5);
			$T[$i] = 0.0;
		}
		for ($it = 0; $it < $iterations; $it++) {
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
					$sum = 0.0;
					for ($k = 0; $k < $n; $k++) {
						$sum += $T[$in + $k] * $F[$jn + $k];
					}
					$P[$in + $j] = $sum;
					$P[$jn + $i] = $sum;
				}
			}
		}
		return (float) $P[0];
	}

	/**
	 * FFI double[] buffers with the same PHP loops: measures CData element access cost.
	 *
	 * @return float checksum (see flat())
	 *
	 * The element reads are deliberately raw: FFI exposes them through the extension
	 * rather than ArrayAccess, so they are untyped to an analyser, and routing them
	 * through a typed helper would change exactly what this benchmark measures.
	 *
	 * @psalm-suppress MixedAssignment, MixedOperand, PossiblyNullOperand
	 */
	private static function ffi(int $n, int $iterations): float
	{
		$nn = $n * $n;
		$ffi = \FFI::cdef('');
		/** @var \FFI\CData $F */
		$F = $ffi->new("double[$nn]");
		/** @var \FFI\CData $P */
		$P = $ffi->new("double[$nn]");
		/** @var \FFI\CData $T */
		$T = $ffi->new("double[$nn]");
		for ($i = 0; $i < $nn; $i++) {
			$F[$i] = ($i % ($n + 1) === 0) ? 1.0 : 0.01 * ($i % 7);
			$P[$i] = ($i % ($n + 1) === 0) ? 1.0 : 0.001 * ($i % 5);
			$T[$i] = 0.0;
		}
		for ($it = 0; $it < $iterations; $it++) {
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
					$sum = 0.0;
					for ($k = 0; $k < $n; $k++) {
						$sum += $T[$in + $k] * $F[$jn + $k];
					}
					$P[$in + $j] = $sum;
					$P[$jn + $i] = $sum;
				}
			}
		}
		return (float) $P[0];
	}

	/**
	 * Two dgemm calls per iteration through the §6.3 backend (T = F·P, P = T·Fᵀ).
	 *
	 * @return float checksum (see flat())
	 */
	private static function blas(BlasBackend $blas, int $n, int $iterations): float
	{
		$nn = $n * $n;
		$F = $blas->matrix($n);
		$P = $blas->matrix($n);
		$T = $blas->matrix($n);
		for ($i = 0; $i < $nn; $i++) {
			$F[$i] = ($i % ($n + 1) === 0) ? 1.0 : 0.01 * ($i % 7);
			$P[$i] = ($i % ($n + 1) === 0) ? 1.0 : 0.001 * ($i % 5);
		}
		for ($it = 0; $it < $iterations; $it++) {
			$blas->gemm($n, false, false, 1.0, $F, $P, 0.0, $T);
			$blas->gemm($n, false, true, 1.0, $T, $F, 0.0, $P);
		}
		return BlasBackend::valueAt($P, 0);
	}
}
