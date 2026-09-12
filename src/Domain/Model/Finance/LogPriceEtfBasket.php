<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\NonlinearMotionModel;
use OpenCCK\Kalman\Domain\Contract\NonlinearObservationModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Filter\ExtendedKalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * §2.16 / §3.2 ETF basket in LOG prices with an EKF: the state carries
 * ℓᵢ = ln pᵢ (random walks with the log-return covariance Σ, stationary
 * multiplicative noise) and the premium b; the observations are linear in
 * prices, hence nonlinear in the state:
 *
 *   x = [ℓ₁..ℓ_k, b]           f(x) = x (RW; b ← e^{−θ dt} b),   Q = diag(Σ·dt, q_b)
 *   z_i = e^{ℓᵢ}               ∂/∂ℓᵢ = e^{ℓᵢ}
 *   z_ETF = Σ wᵢ e^{ℓᵢ} + b     ∂/∂ℓᵢ = wᵢ e^{ℓᵢ},  ∂/∂b = 1
 */
final class LogPriceEtfBasket implements NonlinearMotionModel, NonlinearObservationModel
{
	private int $k;
	private int $n;

	/**
	 * @param array<int, float> $weights
	 * @param array<int, float> $sigma k×k log-return covariance per second
	 * @param array<int, float> $quoteVariances r_i (price units²)
	 */
	public function __construct(
		private readonly array $weights,
		private readonly array $sigma,
		private readonly float $premiumTheta,
		private readonly float $premiumSigma,
		private readonly array $quoteVariances,
		private readonly float $etfVariance,
	) {
		$this->k = \count($weights);
		if ($this->k < 1 || \count($sigma) !== $this->k * $this->k || \count($quoteVariances) !== $this->k) {
			throw new InvalidArgument('weights, sigma (k×k) and quoteVariances (k) sizes disagree');
		}
		if (!Cholesky::isPositiveDefinite($sigma, $this->k)) {
			throw new InvalidArgument('sigma must be SPD');
		}
		$this->n = $this->k + 1;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function premiumIndex(): int
	{
		return $this->k;
	}

	public function propagate(array $x, float $dt): array
	{
		$out = $x;
		$out[$this->k] = \exp(-$this->premiumTheta * $dt) * $x[$this->k];
		return $out;
	}

	public function jacobian(array $x, float $dt): array
	{
		$F = Flat::identity($this->n);
		$F[$this->k * $this->n + $this->k] = \exp(-$this->premiumTheta * $dt);
		return $F;
	}

	public function processNoise(float $dt): array
	{
		$n = $this->n;
		$k = $this->k;
		$Q = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $k; $i++) {
			for ($j = 0; $j < $k; $j++) {
				$Q[$i * $n + $j] = $this->sigma[$i * $k + $j] * $dt;
			}
		}
		if ($this->premiumTheta === 0.0) {
			$Q[$k * $n + $k] = $this->premiumSigma * $this->premiumSigma * $dt;
		} else {
			$d = \exp(-$this->premiumTheta * $dt);
			$Q[$k * $n + $k] = $this->premiumSigma * $this->premiumSigma / (2.0 * $this->premiumTheta) * (1.0 - $d * $d);
		}
		return $Q;
	}

	public function channelCount(): int
	{
		return $this->k + 1;
	}

	public function project(array $x): array
	{
		$out = [];
		$etf = $x[$this->k];
		for ($i = 0; $i < $this->k; $i++) {
			$p = \exp($x[$i]);
			$out[] = $p;
			$etf += $this->weights[$i] * $p;
		}
		$out[] = $etf;
		return $out;
	}

	public function projectChannel(int $channel, array $x): float
	{
		if ($channel < $this->k) {
			return \exp($x[$channel]);
		}
		$etf = $x[$this->k];
		for ($i = 0; $i < $this->k; $i++) {
			$etf += $this->weights[$i] * \exp($x[$i]);
		}
		return $etf;
	}

	public function jacobianRow(int $channel, array $x): array
	{
		if ($channel < $this->k) {
			return [$channel => \exp($x[$channel])];
		}
		$row = [];
		for ($i = 0; $i < $this->k; $i++) {
			$row[$i] = $this->weights[$i] * \exp($x[$i]);
		}
		$row[$this->k] = 1.0;
		return $row;
	}

	public function channelVariance(int $channel): float
	{
		return $channel < $this->k ? $this->quoteVariances[$channel] : $this->etfVariance;
	}

	public function channelName(int $channel): string
	{
		return $channel < $this->k ? 'q' . $channel : 'etf';
	}

	/**
	 * @param array<int, float> $firstPrices
	 */
	public function filter(array $firstPrices, ?FilterConfig $config = null, float $logPriceStd = 0.01, float $premiumPriorStd = 0.05): ExtendedKalmanFilter
	{
		if (\count($firstPrices) !== $this->k) {
			throw new InvalidArgument('firstPrices must have k entries');
		}
		$x0 = [];
		$diag = [];
		foreach ($firstPrices as $p) {
			if ($p <= 0.0) {
				throw new InvalidArgument('prices must be > 0 for a log-price model');
			}
			$x0[] = \log($p);
			$diag[] = $logPriceStd * $logPriceStd;
		}
		$x0[] = 0.0;
		$diag[] = $premiumPriorStd * $premiumPriorStd;
		return new ExtendedKalmanFilter($this, $this, $x0, Flat::diagonal($diag), $config);
	}

	/**
	 * Price estimates e^{ℓ̂ᵢ}.
	 *
	 * @return array<int, float>
	 */
	public function prices(ExtendedKalmanFilter $filter): array
	{
		$out = [];
		for ($i = 0; $i < $this->k; $i++) {
			$out[] = \exp($filter->meanAt($i));
		}
		return $out;
	}
}
