<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Exception;

/**
 * Thrown by Cholesky when a pivot is not strictly positive: the matrix is
 * not positive definite. In the filter this means the covariance has
 * degenerated; the caller should switch to a UD / square-root form.
 */
final class NotPositiveDefinite extends \RuntimeException implements KalmanException
{
	public static function atPivot(int $index, float $value): self
	{
		return new self(\sprintf('Matrix is not positive definite: pivot %d is %.6e', $index, $value));
	}
}
