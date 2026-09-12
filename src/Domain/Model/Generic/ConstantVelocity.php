<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Constant velocity / local linear trend: p̈ = white noise with intensity σ_a².
 *
 *   x = [p, v]ᵀ
 *   F = [1 dt; 0 1]
 *   Q = σ_a² · [dt³/3  dt²/2; dt²/2  dt]      (exact continuous-time discretisation, §2.4)
 *
 * The sparse kernel applies F·P·Fᵀ in closed form (5 multiplications) and
 * keeps P exactly symmetric.
 */
final class ConstantVelocity implements SparseMotionModel, StationaryModel, SerializableModel
{
	private float $q;

	public function __construct(private readonly float $sigmaA)
	{
		if ($sigmaA <= 0.0) {
			throw new InvalidArgument('sigmaA must be > 0');
		}
		$this->q = $sigmaA * $sigmaA;
	}

	public function sigmaA(): float
	{
		return $this->sigmaA;
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
		$q = $this->q;
		$dt2 = $dt * $dt;
		$q12 = $q * $dt2 * 0.5; // NOT `$q * 0.5 * $dt2`: PHP < 8.4 function JIT miscompiles it (Diagnostics\JitSanity)
		return [
			$q * $dt2 * $dt / 3.0, $q12,
			$q12, $q * $dt,
		];
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$x[0] += $dt * $x[1];

		$p00 = $P[0];
		$p01 = $P[1];
		$p11 = $P[3];
		$p01n = $p01 + $dt * $p11;
		$P[0] = $p00 + $dt * ($p01 + $p01n);   // p00 + 2 dt p01 + dt² p11
		$P[1] = $p01n;
		$P[2] = $p01n;
		// P[3] unchanged
	}

	public static function type(): string
	{
		return 'constant-velocity';
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
