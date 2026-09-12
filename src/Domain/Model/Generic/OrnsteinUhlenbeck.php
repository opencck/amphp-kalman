<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Ornstein–Uhlenbeck: dx = −θ(x − μ)dt + σ dβ — a mean-reverting scalar
 * (pair spread, ETF premium, futures basis).
 *
 *   F = e^{−θdt},  u = μ(1 − e^{−θdt}),  Q = σ²/(2θ)·(1 − e^{−2θdt})
 *
 * Half-life = ln 2 / θ. θ = 0 degenerates to a random walk.
 */
final class OrnsteinUhlenbeck implements SparseMotionModel, StationaryModel, SerializableModel
{
	public function __construct(
		private readonly float $theta,
		private readonly float $mu,
		private readonly float $sigma,
	) {
		if ($theta < 0.0) {
			throw new InvalidArgument('theta must be >= 0');
		}
		if ($sigma <= 0.0) {
			throw new InvalidArgument('sigma must be > 0');
		}
	}

	public static function fromHalfLife(float $halfLifeSeconds, float $mu, float $sigma): self
	{
		if ($halfLifeSeconds <= 0.0) {
			throw new InvalidArgument('half-life must be > 0');
		}
		return new self(\M_LN2 / $halfLifeSeconds, $mu, $sigma);
	}

	public function theta(): float
	{
		return $this->theta;
	}

	public function mu(): float
	{
		return $this->mu;
	}

	public function sigma(): float
	{
		return $this->sigma;
	}

	public function halfLife(): float
	{
		return $this->theta > 0.0 ? \M_LN2 / $this->theta : \INF;
	}

	/** Stationary variance σ²/(2θ). */
	public function stationaryVariance(): float
	{
		return $this->theta > 0.0 ? $this->sigma * $this->sigma / (2.0 * $this->theta) : \INF;
	}

	public function stateSize(): int
	{
		return 1;
	}

	public function transition(float $dt): array
	{
		return [\exp(-$this->theta * $dt)];
	}

	public function processNoise(float $dt): array
	{
		if ($this->theta === 0.0) {
			return [$this->sigma * $this->sigma * $dt];
		}
		$e = \exp(-$this->theta * $dt);
		return [$this->sigma * $this->sigma / (2.0 * $this->theta) * (1.0 - $e * $e)];
	}

	public function control(float $dt): ?array
	{
		if ($this->mu === 0.0) {
			return null;
		}
		return [$this->mu * (1.0 - \exp(-$this->theta * $dt))];
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$e = \exp(-$this->theta * $dt);
		$x[0] = $e * $x[0] + $this->mu * (1.0 - $e);
		$P[0] *= $e * $e;
	}

	public static function type(): string
	{
		return 'ornstein-uhlenbeck';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'theta' => $this->theta, 'mu' => $this->mu, 'sigma' => $this->sigma];
	}

	public static function fromArray(array $config): static
	{
		return new self(
			ConfigReader::float($config, 'theta'),
			ConfigReader::float($config, 'mu', 0.0),
			ConfigReader::float($config, 'sigma'),
		);
	}
}
