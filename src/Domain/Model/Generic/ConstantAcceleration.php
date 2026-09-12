<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Discretization\ClosedForm;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Constant acceleration (white-noise jerk): x = [p, v, a].
 * Rarely appropriate for prices (accelerations are not persistent), kept
 * for completeness and as a Van Loan cross-check.
 */
final class ConstantAcceleration implements SparseMotionModel, StationaryModel, SerializableModel
{
	public function __construct(private readonly float $sigmaJ)
	{
		if ($sigmaJ <= 0.0) {
			throw new InvalidArgument('sigmaJ must be > 0');
		}
	}

	public function stateSize(): int
	{
		return 3;
	}

	public function transition(float $dt): array
	{
		return ClosedForm::constantAcceleration($this->sigmaJ, $dt)['F'];
	}

	public function processNoise(float $dt): array
	{
		return ClosedForm::constantAcceleration($this->sigmaJ, $dt)['Q'];
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$h = $dt * $dt * 0.5; // constant LAST in reused products (ADR-007)
		$x[0] += $dt * $x[1] + $h * $x[2];
		$x[1] += $dt * $x[2];

		// T = F·P (rows), then P = T·Fᵀ (cols), upper triangle mirrored
		$p00 = $P[0]; $p01 = $P[1]; $p02 = $P[2];
		$p11 = $P[4]; $p12 = $P[5];
		$p22 = $P[8];

		// row operations: r0 += dt r1 + h r2 ; r1 += dt r2  (using original rows)
		$t00 = $p00 + $dt * $p01 + $h * $p02;
		$t01 = $p01 + $dt * $p11 + $h * $p12;
		$t02 = $p02 + $dt * $p12 + $h * $p22;
		$t10 = $p01 + $dt * $p02;
		$t11 = $p11 + $dt * $p12;
		$t12 = $p12 + $dt * $p22;
		$t20 = $p02;
		$t21 = $p12;
		$t22 = $p22;

		// column operations on T: c0 += dt c1 + h c2 ; c1 += dt c2
		$n00 = $t00 + $dt * $t01 + $h * $t02;
		$n01 = $t01 + $dt * $t02;
		$n02 = $t02;
		$n11 = $t11 + $dt * $t12;
		$n12 = $t12;
		$n22 = $t22;
		// (lower entries would be n10 = t10 + dt t11 + h t12 — equal to n01 up to rounding; mirror upper)
		unset($t10, $t20, $t21);

		$P[0] = $n00; $P[1] = $n01; $P[2] = $n02;
		$P[3] = $n01; $P[4] = $n11; $P[5] = $n12;
		$P[6] = $n02; $P[7] = $n12; $P[8] = $n22;
	}

	public static function type(): string
	{
		return 'constant-acceleration';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'sigmaJ' => $this->sigmaJ];
	}

	public static function fromArray(array $config): static
	{
		return new self(ConfigReader::float($config, 'sigmaJ'));
	}
}
