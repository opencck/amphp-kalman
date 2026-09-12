<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Discretization\ClosedForm;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Singer model: position with an exponentially correlated (fading) velocity,
 * dv = −(1/τ) v dt + σ dβ. Describes intraday impulses that die out;
 * τ is the trend memory in seconds. τ → ∞ recovers ConstantVelocity.
 */
final class Singer implements SparseMotionModel, StationaryModel, SerializableModel
{
	public function __construct(private readonly float $sigma, private readonly float $tau)
	{
		if ($sigma <= 0.0 || $tau <= 0.0) {
			throw new InvalidArgument('sigma and tau must be > 0');
		}
	}

	public function stateSize(): int
	{
		return 2;
	}

	public function transition(float $dt): array
	{
		return ClosedForm::singer($this->sigma, $this->tau, $dt)['F'];
	}

	public function processNoise(float $dt): array
	{
		return ClosedForm::singer($this->sigma, $this->tau, $dt)['Q'];
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$e = \exp(-$dt / $this->tau);
		$g = $this->tau * (1.0 - $e);   // F = [1 g; 0 e]
		$x[0] += $g * $x[1];
		$x[1] *= $e;

		$p00 = $P[0];
		$p01 = $P[1];
		$p11 = $P[3];
		// F P Fᵀ for F = [1 g; 0 e]
		$n00 = $p00 + $g * (2.0 * $p01 + $g * $p11);
		$n01 = $e * ($p01 + $g * $p11);
		$n11 = $e * $e * $p11;
		$P[0] = $n00;
		$P[1] = $n01;
		$P[2] = $n01;
		$P[3] = $n11;
	}

	public static function type(): string
	{
		return 'singer';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'sigma' => $this->sigma, 'tau' => $this->tau];
	}

	public static function fromArray(array $config): static
	{
		return new self(ConfigReader::float($config, 'sigma'), ConfigReader::float($config, 'tau'));
	}
}
