<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;

/**
 * §3.9 Bid-ask bounce as coloured measurement noise via state augmentation.
 *
 *   x = [p, v, ν]ᵀ      ν — autocorrelated microstructure noise
 *   F : CV block for (p, v);  ν ← ρ^(dt/τ)·ν   (AR(1) in tick time, τ = mean tick interval)
 *   Q : CV block σ_a²;  q_ν = σ_ν²·(1 − ρ^(2dt/τ))  keeps Var(ν) = σ_ν² stationary
 *   z = [trade],  H = [1 0 1],  R = tick²/12   (discreteness only)
 *
 * R is tiny relative to h·P·hᵀ, so the recommended form is UD or SquareRoot
 * (§2.15): the sequential rank-1 downdate can lose positive definiteness.
 */
final class BidAskBounce implements SparseMotionModel, StationaryModel, SerializableModel
{
	private float $qA;
	private float $sigmaNu2;
	private float $logRho;

	/**
	 * @param float $sigmaA velocity noise intensity
	 * @param float $sigmaNu stationary std of the bounce term
	 * @param float $rho lag-1 autocorrelation of the bounce at one tick interval (≈ −0.3..−0.5)
	 * @param float $tickInterval mean interval between trades, seconds
	 * @param float $tick price increment (R = tick²/12)
	 */
	public function __construct(
		private readonly float $sigmaA,
		private readonly float $sigmaNu,
		private readonly float $rho,
		private readonly float $tickInterval,
		private readonly float $tick,
	) {
		if ($sigmaA <= 0.0 || $sigmaNu <= 0.0 || $tickInterval <= 0.0 || $tick <= 0.0) {
			throw new InvalidArgument('sigmaA, sigmaNu, tickInterval and tick must be > 0');
		}
		if ($rho <= -1.0 || $rho >= 1.0 || $rho === 0.0) {
			throw new InvalidArgument('rho must be in (-1, 1) and non-zero');
		}
		$this->qA = $sigmaA * $sigmaA;
		$this->sigmaNu2 = $sigmaNu * $sigmaNu;
		$this->logRho = \log(\abs($rho));
	}

	public function stateSize(): int
	{
		return 3;
	}

	/** ρ_eff(dt) = sign(ρ)·|ρ|^(dt/τ). */
	public function decay(float $dt): float
	{
		$mag = \exp($this->logRho * $dt / $this->tickInterval);
		return $this->rho < 0.0 ? -$mag : $mag;
	}

	public function transition(float $dt): array
	{
		$d = $this->decay($dt);
		return [
			1.0, $dt, 0.0,
			0.0, 1.0, 0.0,
			0.0, 0.0, $d,
		];
	}

	public function processNoise(float $dt): array
	{
		$q = $this->qA;
		$dt2 = $dt * $dt;
		$d = $this->decay($dt);
		$qNu = $this->sigmaNu2 * (1.0 - $d * $d);
		$q12 = $q * $dt2 * 0.5; // constant LAST in reused products (ADR-007)
		return [
			$q * $dt2 * $dt / 3.0, $q12, 0.0,
			$q12, $q * $dt, 0.0,
			0.0, 0.0, $qNu,
		];
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$d = $this->decay($dt);
		$x[0] += $dt * $x[1];
		$x[2] *= $d;

		$p00 = $P[0];
		$p01 = $P[1];
		$p02 = $P[2];
		$p11 = $P[4];
		$p12 = $P[5];
		$p22 = $P[8];

		$p01n = $p01 + $dt * $p11;
		$p00n = $p00 + $dt * ($p01 + $p01n);
		$p02n = $d * ($p02 + $dt * $p12);
		$p12n = $d * $p12;
		$p22n = $d * $d * $p22;

		$P[0] = $p00n;
		$P[1] = $p01n;
		$P[2] = $p02n;
		$P[3] = $p01n;
		$P[4] = $p11;
		$P[5] = $p12n;
		$P[6] = $p02n;
		$P[7] = $p12n;
		$P[8] = $p22n;
	}

	public function observation(): StaticObservation
	{
		return new StaticObservation([[0 => 1.0, 2 => 1.0]], [$this->tick * $this->tick / 12.0], ['trade']);
	}

	public function filter(float $firstPrice, ?FilterConfig $config = null, float $velocityPriorStd = 1.0): KalmanFilter
	{
		$config ??= FilterConfig::default()->withForm(FilterForm::UD);
		return new KalmanFilter(
			$this,
			$this->observation(),
			[$firstPrice, 0.0, 0.0],
			[
				$this->sigmaNu2 * 4.0, 0.0, 0.0,
				0.0, $velocityPriorStd * $velocityPriorStd, 0.0,
				0.0, 0.0, $this->sigmaNu2,
			],
			$config,
		);
	}

	public static function type(): string
	{
		return 'bid-ask-bounce';
	}

	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'sigmaA' => $this->sigmaA,
			'sigmaNu' => $this->sigmaNu,
			'rho' => $this->rho,
			'tickInterval' => $this->tickInterval,
			'tick' => $this->tick,
		];
	}

	public static function fromArray(array $config): static
	{
		return new self(
			ConfigReader::float($config, 'sigmaA'),
			ConfigReader::float($config, 'sigmaNu'),
			ConfigReader::float($config, 'rho'),
			ConfigReader::float($config, 'tickInterval'),
			ConfigReader::float($config, 'tick'),
		);
	}
}
