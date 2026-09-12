<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Exception;

final class DimensionMismatch extends \LogicException implements KalmanException
{
	public static function forMatrix(string $name, int $expected, int $actual): self
	{
		return new self(\sprintf('%s: expected %d elements, got %d', $name, $expected, $actual));
	}

	public static function forVector(string $name, int $expected, int $actual): self
	{
		return new self(\sprintf('%s: expected length %d, got %d', $name, $expected, $actual));
	}
}
