<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;

/**
 * §3.6 Stochastic volatility, quasi-maximum-likelihood linearisation
 * (Harvey–Ruiz–Shephard 1994).
 *
 *   r_t = e^{h_t/2} ε_t,   h_t = μ + φ(h_{t−1} − μ) + η_t
 *   z_t = ln r_t² = h_t + ln ε_t²,   E[ln ε_t²] = −1.2704,  Var = π²/2 ≈ 4.9348
 *
 * State: x = [h] (log-variance per bar). The AR(1) is defined per bar of
 * barSeconds; for an arbitrary dt the equivalent OU is used:
 *   F = φ^{dt/bar},  u = μ(1 − F),  Q = σ_η²·(1 − F²)/(1 − φ²)
 * so that the stationary variance σ_η²/(1−φ²) is preserved.
 *
 * Observation: z = ln(r² + floor) + 1.2704  (centred), H = [1], R = π²/2.
 * The noise is not Gaussian; the KF is still the best linear estimator (QML).
 */
final class StochasticVolatility implements SparseMotionModel, StationaryModel, SerializableModel
{
	public const LOG_CHI2_MEAN = -1.2704;
	public const LOG_CHI2_VARIANCE = 4.9348022005446793; // π²/2

	private float $logPhi;
	private float $stationaryVariance;

	public function __construct(
		private readonly float $mu,
		private readonly float $phi,
		private readonly float $sigmaEta,
		private readonly float $barSeconds,
	) {
		if ($phi <= 0.0 || $phi >= 1.0) {
			throw new InvalidArgument('phi must be in (0, 1)');
		}
		if ($sigmaEta <= 0.0 || $barSeconds <= 0.0) {
			throw new InvalidArgument('sigmaEta and barSeconds must be > 0');
		}
		$this->logPhi = \log($phi);
		$this->stationaryVariance = $sigmaEta * $sigmaEta / (1.0 - $phi * $phi);
	}

	public function stateSize(): int
	{
		return 1;
	}

	private function decay(float $dt): float
	{
		return \exp($this->logPhi * $dt / $this->barSeconds);
	}

	public function transition(float $dt): array
	{
		return [$this->decay($dt)];
	}

	public function processNoise(float $dt): array
	{
		$f = $this->decay($dt);
		return [$this->stationaryVariance * (1.0 - $f * $f)];
	}

	public function control(float $dt): array
	{
		return [$this->mu * (1.0 - $this->decay($dt))];
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$f = $this->decay($dt);
		$x[0] = $f * $x[0] + $this->mu * (1.0 - $f);
		$P[0] *= $f * $f;
	}

	public function observation(): StaticObservation
	{
		return new StaticObservation([[0 => 1.0]], [self::LOG_CHI2_VARIANCE], ['log_r2']);
	}

	/** Transforms a return into the centred observation ln(r² + floor) − E[ln ε²]. */
	public static function observe(float $return, float $floor = 1e-12): float
	{
		return \log($return * $return + $floor) - self::LOG_CHI2_MEAN;
	}

	/** Annualised-free: current volatility estimate per bar, e^{ĥ/2}. */
	public static function volatility(KalmanFilter $filter): float
	{
		return \exp(0.5 * $filter->meanAt(0));
	}

	public function filter(?FilterConfig $config = null): KalmanFilter
	{
		return new KalmanFilter($this, $this->observation(), [$this->mu], [$this->stationaryVariance], $config);
	}

	public static function type(): string
	{
		return 'stochastic-volatility';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'mu' => $this->mu, 'phi' => $this->phi, 'sigmaEta' => $this->sigmaEta, 'barSeconds' => $this->barSeconds];
	}

	public static function fromArray(array $config): static
	{
		return new self(
			ConfigReader::float($config, 'mu'),
			ConfigReader::float($config, 'phi'),
			ConfigReader::float($config, 'sigmaEta'),
			ConfigReader::float($config, 'barSeconds'),
		);
	}
}
