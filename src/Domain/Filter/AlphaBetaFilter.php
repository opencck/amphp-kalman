<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Filter;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * §2.10 alpha-beta filter: the steady-state Kalman filter for a constant
 * velocity model with one position channel at a fixed interval dt.
 *
 *   p⁻ = p + dt·v
 *   p  = p⁻ + α(z − p⁻),   v = v + (β/dt)(z − p⁻)
 *
 * Closed form (Kalata 1984), exact for the DISCRETE white-noise acceleration
 * model {@see \OpenCCK\Kalman\Domain\Model\Generic\DiscreteWhiteNoiseAcceleration}:
 *   λ = σ_a·dt²/σ_r  (tracking index),  r = (4 + λ − √(8λ + λ²))/4,
 *   α = 1 − r²,  β = 2(2 − α) − 4√(1 − α)
 *
 * For the continuous white-noise acceleration model (ConstantVelocity, Q with
 * dt³/3) the exact steady-state gains come from the Riccati equation:
 * use forConstantVelocity(). Either way the step is ~10 flops, no covariance.
 */
final class AlphaBetaFilter
{
	private float $alpha;
	private float $beta;
	private float $lambda;
	private float $position;
	private float $velocity;
	private float $lastInnovation = 0.0;
	private int $steps = 0;

	public function __construct(
		private readonly float $sigmaA,
		private readonly float $sigmaR,
		private readonly float $dt,
		float $position0 = 0.0,
		float $velocity0 = 0.0,
	) {
		if ($sigmaA <= 0.0 || $sigmaR <= 0.0 || $dt <= 0.0) {
			throw new InvalidArgument('sigmaA, sigmaR and dt must be > 0');
		}
		$lambda = $sigmaA * $dt * $dt / $sigmaR;
		$r = (4.0 + $lambda - \sqrt(8.0 * $lambda + $lambda * $lambda)) / 4.0;
		$this->lambda = $lambda;
		$this->alpha = 1.0 - $r * $r;
		$this->beta = 2.0 * (2.0 - $this->alpha) - 4.0 * \sqrt(1.0 - $this->alpha);
		$this->position = $position0;
		$this->velocity = $velocity0;
	}

	/**
	 * Exact steady-state gains for the continuous white-noise acceleration
	 * model (ConstantVelocity) at a fixed dt, from the Riccati equation.
	 */
	public static function forConstantVelocity(float $sigmaA, float $sigmaR, float $dt, float $position0 = 0.0, float $velocity0 = 0.0): self
	{
		if ($sigmaA <= 0.0 || $sigmaR <= 0.0 || $dt <= 0.0) {
			throw new InvalidArgument('sigmaA, sigmaR and dt must be > 0');
		}
		$dare = \OpenCCK\Kalman\Domain\Diagnostics\SteadyStateSolver::forModels(
			new \OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity($sigmaA),
			new \OpenCCK\Kalman\Domain\Model\Observation\StaticObservation([[0 => 1.0]], [$sigmaR * $sigmaR]),
			$dt,
		);
		$f = self::fromGains($dare['K'][0], $dare['K'][1] * $dt, $dt, $position0, $velocity0);
		$f->lambda = $sigmaA * $dt * $dt / $sigmaR;
		return $f;
	}

	/** Directly from gains (e.g. tuned by hand). */
	public static function fromGains(float $alpha, float $beta, float $dt, float $position0 = 0.0, float $velocity0 = 0.0): self
	{
		if ($alpha <= 0.0 || $alpha >= 1.0 || $beta <= 0.0 || $dt <= 0.0) {
			throw new InvalidArgument('0 < alpha < 1, beta > 0, dt > 0 required');
		}
		$f = new self(1.0, 1.0, $dt, $position0, $velocity0);
		$f->alpha = $alpha;
		$f->beta = $beta;
		$f->lambda = \NAN;
		return $f;
	}

	public function step(float $z): void
	{
		$dt = $this->dt;
		$predicted = $this->position + $dt * $this->velocity;
		$innovation = $z - $predicted;
		$this->position = $predicted + $this->alpha * $innovation;
		$this->velocity += $this->beta / $dt * $innovation;
		$this->lastInnovation = $innovation;
		$this->steps++;
	}

	public function position(): float
	{
		return $this->position;
	}

	public function velocity(): float
	{
		return $this->velocity;
	}

	public function alpha(): float
	{
		return $this->alpha;
	}

	public function beta(): float
	{
		return $this->beta;
	}

	public function trackingIndex(): float
	{
		return $this->lambda;
	}

	public function lastInnovation(): float
	{
		return $this->lastInnovation;
	}

	public function steps(): int
	{
		return $this->steps;
	}

	/**
	 * Equivalent steady-state Kalman gain [α, β/dt].
	 *
	 * @return array{0: float, 1: float}
	 */
	public function kalmanGain(): array
	{
		return [$this->alpha, $this->beta / $this->dt];
	}

	public function reset(float $position, float $velocity = 0.0): void
	{
		$this->position = $position;
		$this->velocity = $velocity;
		$this->steps = 0;
		$this->lastInnovation = 0.0;
	}
}
