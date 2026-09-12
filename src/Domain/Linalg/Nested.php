<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Linalg;

use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Conversion between flat row-major matrices and nested PHP arrays.
 * Boundary helper for model construction, tests and JSON — nested arrays
 * never enter the filter hot path (§0.2 p.13).
 */
final class Nested
{
	private function __construct()
	{
	}

	/**
	 * @param array<int, array<int, float|int>> $rows
	 * @return array<int, float>
	 */
	public static function toFlat(array $rows): array
	{
		$flat = [];
		$width = null;
		foreach ($rows as $row) {
			$width ??= \count($row);
			if (\count($row) !== $width) {
				throw new InvalidArgument('All rows must have the same length');
			}
			foreach ($row as $v) {
				$flat[] = (float) $v;
			}
		}
		return $flat;
	}

	/**
	 * @param array<int, float> $A
	 * @return array<int, array<int, float>>
	 */
	public static function fromFlat(array $A, int $rows, int $cols): array
	{
		if (\count($A) !== $rows * $cols) {
			throw DimensionMismatch::forMatrix('A', $rows * $cols, \count($A));
		}
		$out = [];
		for ($i = 0; $i < $rows; $i++) {
			$out[] = \array_slice($A, $i * $cols, $cols);
		}
		return $out;
	}
}
