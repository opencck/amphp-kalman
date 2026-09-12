<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Discrete white-noise acceleration (DWNA, Bar-Shalom): the acceleration is
 * constant over each sampling interval and independent between intervals.
 *
 *   x = [p, v],  F = [1 dt; 0 1],  Q = σ_a²·[dt⁴/4  dt³/2; dt³/2  dt²]
 *
 * This is the model for which the Kalata alpha-beta gains are EXACT
 * (tracking index λ = σ_a·dt²/σ_r). Prefer ConstantVelocity (continuous
 * white-noise acceleration) for irregular tick data; DWNA is for fixed bars.
 */
final class DiscreteWhiteNoiseAcceleration implements SparseMotionModel, StationaryModel, SerializableModel
{
	private float $q;

	public function __construct(private readonly float $sigmaA)
	{
		if ($sigmaA <= 0.0) {
			throw new InvalidArgument('sigmaA must be > 0');
		}
		$this->q = $sigmaA * $sigmaA;
	}

	public function stateSize(): int
	{
		return 2;
	}

	public function transition(float $dt): array
	{
		return [1.0, $dt, 0.0, 1.0];
	}

	public function processNoise(float $dt): array
	{
		$dt2 = $dt * $dt;
		$q12 = 0.5 * $this->q * $dt2 * $dt;
		return [0.25 * $this->q * $dt2 * $dt2, $q12, $q12, $this->q * $dt2];
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$x[0] += $dt * $x[1];
		$p01 = $P[1];
		$p11 = $P[3];
		$p01n = $p01 + $dt * $p11;
		$P[0] += $dt * ($p01 + $p01n);
		$P[1] = $p01n;
		$P[2] = $p01n;
	}

	public static function type(): string
	{
		return 'discrete-white-noise-acceleration';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'sigmaA' => $this->sigmaA];
	}

	public static function fromArray(array $config): static
	{
		return new self(ConfigReader::float($config, 'sigmaA'));
	}
}
