<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Diagnostics;

use OpenCCK\Kalman\Domain\Exception\NumericalFailure;

/**
 * §8 runtime self-check for the PHP JIT (ADR-007).
 *
 * Two miscompilations were found while benchmarking on PHP 8.2 / 8.3 (fixed in 8.4).
 * Both appear with the function JIT (`opcache.jit=1205`) and, once a loop or
 * function is hot, with the tracing JIT (`1255`):
 *
 *   1. `$a * <const> * $b` (constant first or in the middle) stored in a
 *      temporary that is used twice evaluates as `$b * $b`;
 *   2. accumulating a product into an array element inside a triple loop
 *      (`$C[$i*$m + $j] += $a * $B[$p*$m + $j]`) returns a different matrix.
 *
 * Such bugs silently corrupt Q or F·P·Fᵀ while every invariant (symmetry,
 * positive definiteness, NIS on the model's own simulation) still holds.
 * The library's kernels avoid both shapes (constant LAST in reused products,
 * local accumulators instead of in-place element updates), so a buggy engine
 * is still usable — engineBugs() reports what the running engine miscompiles,
 * while verify() checks the shapes the library actually uses and throws only
 * when THOSE are wrong.
 *
 * verify() runs once per process (filters call it from their constructors)
 * and costs well under a millisecond. Detection needs the check to come from
 * a compiled file — `php -r` snippets are not JIT-compiled the same way.
 *
 * Each canary is called ROUNDS times and only the LAST result is inspected: the
 * repetition exists to push the kernel past the JIT hot thresholds, so the
 * intermediate assignments are deliberately discarded.
 *
 * @psalm-suppress UnusedVariable
 */
final class JitSanity
{
	private const ROUNDS = 96; // > opcache.jit_hot_loop (64) and jit_hot_func defaults so traces/functions get compiled

	private static ?bool $passed = null;

	/** @var array<int, string>|null */
	private static ?array $engineBugs = null;

	private function __construct()
	{
	}

	/** @throws NumericalFailure when the running JIT miscompiles the shapes the library relies on */
	public static function verify(): void
	{
		if (self::$passed === true) {
			return;
		}
		$failures = self::failures();
		self::$passed = $failures === [];
		if (!self::$passed) {
			throw new NumericalFailure(
				'PHP JIT miscompiles floating-point kernels used by this library (' . \implode('; ', $failures) . '). '
				. 'Disable the JIT (opcache.jit=0) or upgrade PHP; configuration: jit=' . (string) \ini_get('opcache.jit') . ', PHP ' . \PHP_VERSION,
			);
		}
	}

	public static function passes(): bool
	{
		if (self::$passed === null) {
			self::$passed = self::failures() === [];
		}
		return self::$passed;
	}

	/**
	 * Library-shaped kernels vs exact literal results. Empty when the engine is usable.
	 *
	 * @return array<int, string>
	 */
	public static function failures(): array
	{
		$out = [];

		$a = self::productConstantLast(2.25, 0.1);
		for ($i = 1; $i < self::ROUNDS; $i++) {
			$a = self::productConstantLast(2.25, 0.1);
		}
		// 2.25 · 0.01 · 0.5 with 0.1·0.1 = 0.010000000000000002 → 0.011250000000000003; compare with tolerance
		if (\abs($a[1] - 0.01125) > 1e-12 || $a[2] !== $a[1]) {
			$out[] = \sprintf('2.25 * 0.01 * 0.5 gave %.17g (expected 0.01125)', $a[1]);
		}

		[$A, $B, $expected] = self::productFixture();
		$dot = self::dotProduct($A, $B, 4);
		for ($i = 1; $i < self::ROUNDS; $i++) {
			$dot = self::dotProduct($A, $B, 4);
		}
		if ($dot !== $expected) {
			$out[] = \sprintf('4×4 product (dot form) differs from the exact result by %.3g', self::maxDiff($dot, $expected));
		}

		$P = self::rank1Downdate(0.5, 0.25, 2.0);
		for ($i = 1; $i < self::ROUNDS; $i++) {
			$P = self::rank1Downdate(0.5, 0.25, 2.0);
		}
		// P = I − φφᵀ/s with φ = (0.5, 0.25), s = 2: [1 − 0.125, −0.0625; −0.0625, 1 − 0.03125]
		if ($P !== [0.875, -0.0625, -0.0625, 0.96875]) {
			$out[] = \sprintf('rank-1 downdate gave [%s] (expected [0.875, -0.0625, -0.0625, 0.96875])', \implode(', ', \array_map(static fn (float $v): string => \sprintf('%.17g', $v), $P)));
		}
		return $out;
	}

	/**
	 * Known miscompiled shapes (informational — the library does not use them).
	 * Cached per process.
	 *
	 * @return array<int, string>
	 */
	public static function engineBugs(): array
	{
		if (self::$engineBugs !== null) {
			return self::$engineBugs;
		}
		$out = [];
		$a = [];
		$b = [];
		for ($i = 0; $i < self::ROUNDS; $i++) {
			$a = self::productMiddleConstant(2.25, 0.1);
			$b = self::productLeadingConstant(2.25, 0.1);
		}
		if (\abs($a[1] - 0.01125) > 1e-12 || $a[2] !== $a[1]) {
			$out[] = \sprintf('reused `a * const * b` temporary: 2.25 * 0.5 * 0.01 gave %.17g', $a[1]);
		}
		if (\abs($b[1] - 0.01125) > 1e-12 || $b[2] !== $b[1]) {
			$out[] = \sprintf('reused `const * a * b` temporary: 0.5 * 2.25 * 0.01 gave %.17g', $b[1]);
		}
		[$A, $B, $expected] = self::productFixture();
		$hot = self::accumulateProduct($A, $B, 4);
		for ($i = 1; $i < self::ROUNDS; $i++) {
			$hot = self::accumulateProduct($A, $B, 4);
		}
		if ($hot !== $expected) {
			$out[] = \sprintf('in-place `C[i,j] += a * B[p,j]` product: off by %.3g', self::maxDiff($hot, $expected));
		}
		return self::$engineBugs = $out;
	}

	/** @return array<int, float> */
	private static function productMiddleConstant(float $q, float $dt): array
	{
		$dt2 = $dt * $dt;
		$q12 = $q * 0.5 * $dt2;
		return [$q * $dt2 * $dt / 3.0, $q12, $q12, $q * $dt];
	}

	/** @return array<int, float> */
	private static function productLeadingConstant(float $q, float $dt): array
	{
		$dt2 = $dt * $dt;
		$q12 = 0.5 * $q * $dt2;
		return [$q * $dt2 * $dt / 3.0, $q12, $q12, $q * $dt];
	}

	/**
	 * The library's shape: constant last.
	 *
	 * @return array<int, float>
	 */
	private static function productConstantLast(float $q, float $dt): array
	{
		$dt2 = $dt * $dt;
		$q12 = $q * $dt2 * 0.5;
		return [$q * $dt2 * $dt / 3.0, $q12, $q12, $q * $dt];
	}

	/**
	 * Inputs are multiples of 1/8 with a few exact zeros, so every product and
	 * sum is exact in binary floating point and the expected matrix is a literal.
	 *
	 * @return array{0: array<int, float>, 1: array<int, float>, 2: array<int, float>}
	 */
	private static function productFixture(): array
	{
		$A = [1.0, 0.5, 0.0, -0.25, 0.125, 1.0, 0.75, 0.0, 0.0, -0.5, 1.0, 0.375, 0.25, 0.0, -0.125, 1.0];
		$B = [2.0, -0.5, 0.25, 0.0, 0.5, 1.5, 0.0, -0.75, -0.25, 0.0, 1.25, 0.5, 0.0, 0.625, -0.5, 1.0];
		// A·B, exact in binary floating point:
		$expected = [
			2.25, 0.09375, 0.375, -0.625,
			0.5625, 1.4375, 0.96875, -0.375,
			-0.5, -0.515625, 1.0625, 1.25,
			0.53125, 0.5, -0.59375, 0.9375,
		];
		return [$A, $B, $expected];
	}

	/**
	 * The vulnerable shape: C[i,j] accumulated in place over p.
	 *
	 * @param array<int, float> $A
	 * @param array<int, float> $B
	 * @return array<int, float>
	 */
	private static function accumulateProduct(array $A, array $B, int $n): array
	{
		$C = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($p = 0; $p < $n; $p++) {
				$a = $A[$in + $p];
				if ($a === 0.0) {
					continue;
				}
				$pn = $p * $n;
				for ($j = 0; $j < $n; $j++) {
					$C[$in + $j] += $a * $B[$pn + $j];
				}
			}
		}
		return $C;
	}

	/**
	 * The shape the library uses: a local accumulator per output element.
	 *
	 * @param array<int, float> $A
	 * @param array<int, float> $B
	 * @return array<int, float>
	 */
	private static function dotProduct(array $A, array $B, int $n): array
	{
		$C = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = 0; $j < $n; $j++) {
				$sum = 0.0;
				for ($p = 0; $p < $n; $p++) {
					$sum += $A[$in + $p] * $B[$p * $n + $j];
				}
				$C[$in + $j] = $sum;
			}
		}
		return $C;
	}

	/**
	 * The sequential-form core: upper-triangle rank-1 downdate mirrored to the lower triangle
	 * (the same statement shape as Covariance\DenseSequential::commitScalar).
	 *
	 * @return array<int, float>
	 */
	private static function rank1Downdate(float $phi0, float $phi1, float $s): array
	{
		$n = 2;
		/** @var array<int, float> $phi */
		$phi = [$phi0, $phi1];
		/** @var array<int, float> $P */
		$P = [1.0, 0.0, 0.0, 1.0];
		$inv = 1.0 / $s;
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
		return $P;
	}

	/**
	 * @param array<int, float> $x
	 * @param array<int, float> $y
	 */
	private static function maxDiff(array $x, array $y): float
	{
		$d = 0.0;
		foreach ($x as $i => $v) {
			$e = $v - $y[$i];
			if ($e < 0.0) {
				$e = -$e;
			}
			if ($e > $d) {
				$d = $e;
			}
		}
		return $d;
	}
}
