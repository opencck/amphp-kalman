<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Observation;

use OpenCCK\Kalman\Domain\Contract\CorrelatedObservationModel;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * Plain value holder for a dense H (m×n) and a full R (m×m). Feed it to
 * {@see DecorrelatedObservation} to obtain a channel-wise model.
 */
final class CorrelatedObservation implements CorrelatedObservationModel
{
	/**
	 * @param array<int, float> $H m×n row-major
	 * @param array<int, float> $R m×m row-major SPD
	 */
	public function __construct(
		private readonly array $H,
		private readonly array $R,
		private readonly int $m,
		private readonly int $n,
	) {
		if (\count($H) !== $m * $n) {
			throw DimensionMismatch::forMatrix('H', $m * $n, \count($H));
		}
		if (\count($R) !== $m * $m) {
			throw DimensionMismatch::forMatrix('R', $m * $m, \count($R));
		}
		if (!Flat::isSymmetric($R, $m, 1e-12) || !Cholesky::isPositiveDefinite($R, $m)) {
			throw new InvalidArgument('R must be symmetric positive definite');
		}
	}

	public function channelCount(): int
	{
		return $this->m;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function observationMatrix(): array
	{
		return $this->H;
	}

	public function measurementNoise(): array
	{
		return $this->R;
	}
}
