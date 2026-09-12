<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\NonlinearMotionModel;
use OpenCCK\Kalman\Domain\Contract\NonlinearObservationModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Filter\UnscentedKalmanFilter;

/**
 * §3.6 UKF variant with leverage: the volatility shock is correlated (ρ < 0)
 * with the return shock. Written per bar (dt is the bar interval):
 *
 *   x = [h, ε]         h — log-variance, ε — current standardised return shock
 *   f(x) = [ μ + φ(h − μ) + σ_η·ρ·ε ,  0 ]        Q = diag(σ_η²(1 − ρ²), 1)
 *   z = r = e^{h/2}·ε   (nonlinear, deterministic given the state)   R = r_floor
 *
 * The return itself is the observation (no log r² transform), so the
 * negative correlation between today's shock and tomorrow's variance is
 * captured; the UKF handles the product e^{h/2}·ε without Jacobians.
 */
final class StochasticVolatilityLeverage implements NonlinearMotionModel, NonlinearObservationModel
{
	public function __construct(
		private readonly float $mu,
		private readonly float $phi,
		private readonly float $sigmaEta,
		private readonly float $rho,
		private readonly float $observationFloor = 1e-8,
	) {
		if ($phi <= 0.0 || $phi >= 1.0) {
			throw new InvalidArgument('phi must be in (0, 1)');
		}
		if ($sigmaEta <= 0.0 || $rho <= -1.0 || $rho >= 1.0 || $observationFloor <= 0.0) {
			throw new InvalidArgument('sigmaEta > 0, rho in (-1, 1), observationFloor > 0 required');
		}
	}

	public function stateSize(): int
	{
		return 2;
	}

	public function propagate(array $x, float $dt): array
	{
		return [$this->mu + $this->phi * ($x[0] - $this->mu) + $this->sigmaEta * $this->rho * $x[1], 0.0];
	}

	public function jacobian(array $x, float $dt): array
	{
		return [$this->phi, $this->sigmaEta * $this->rho, 0.0, 0.0];
	}

	public function processNoise(float $dt): array
	{
		return [$this->sigmaEta * $this->sigmaEta * (1.0 - $this->rho * $this->rho), 0.0, 0.0, 1.0];
	}

	public function channelCount(): int
	{
		return 1;
	}

	public function project(array $x): array
	{
		return [\exp(0.5 * $x[0]) * $x[1]];
	}

	public function projectChannel(int $channel, array $x): float
	{
		return \exp(0.5 * $x[0]) * $x[1];
	}

	public function jacobianRow(int $channel, array $x): array
	{
		$e = \exp(0.5 * $x[0]);
		return [0 => $e * $x[1] * 0.5, 1 => $e];
	}

	public function channelVariance(int $channel): float
	{
		return $this->observationFloor;
	}

	public function channelName(int $channel): string
	{
		return 'return';
	}

	public function filter(?FilterConfig $config = null): UnscentedKalmanFilter
	{
		$stationary = $this->sigmaEta * $this->sigmaEta / (1.0 - $this->phi * $this->phi);
		return new UnscentedKalmanFilter($this, $this, [$this->mu, 0.0], [$stationary, 0.0, 0.0, 1.0], $config, alpha: 0.5, beta: 2.0, kappa: 1.0);
	}

	/** Current volatility per bar: e^{ĥ/2}. */
	public static function volatility(UnscentedKalmanFilter $filter): float
	{
		return \exp(0.5 * $filter->meanAt(0));
	}
}
