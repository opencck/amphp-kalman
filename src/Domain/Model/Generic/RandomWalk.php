<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Random walk (Brownian motion) in one dimension: dx = σ dβ.
 *
 *   F = [1],   Q = σ²·dt
 */
final class RandomWalk implements SparseMotionModel, StationaryModel, SerializableModel
{
	private float $variance;

	public function __construct(private readonly float $sigma)
	{
		if ($sigma <= 0.0) {
			throw new InvalidArgument('sigma must be > 0');
		}
		$this->variance = $sigma * $sigma;
	}

	public function sigma(): float
	{
		return $this->sigma;
	}

	public function stateSize(): int
	{
		return 1;
	}

	public function transition(float $dt): array
	{
		return [1.0];
	}

	public function processNoise(float $dt): array
	{
		return [$this->variance * $dt];
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		// F = I: nothing to do
	}

	public static function type(): string
	{
		return 'random-walk';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'sigma' => $this->sigma];
	}

	public static function fromArray(array $config): static
	{
		return new self(ConfigReader::float($config, 'sigma'));
	}
}
