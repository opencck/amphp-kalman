<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;

/**
 * §3.2 ETF basket — the library's reference model.
 *
 *   x = [p₁..p_k | v₁..v_k | b]ᵀ,  n = 2k + 1
 *   F : pᵢ ← pᵢ + dt·vᵢ,  vᵢ ← vᵢ,  b ← e^{−θ_b dt}·b
 *   Q : price block  Σ·dt + σ_a²·dt³/3·I,  p–v cross σ_a²·dt²/2·I,  v block σ_a²·dt·I,
 *       premium σ_b²/(2θ_b)·(1 − e^{−2θ_b dt})   (σ_b²·dt when θ_b = 0)
 *   z = [q₁..q_k, q_ETF],  H rows 1..k = eᵢᵀ,  row k+1 = [w₁..w_k | 0 | 1]
 *   R = diag(r₁..r_k, r_ETF)
 *
 * Σ is the FULL return covariance per second — the off-diagonal terms let
 * the filter track a non-trading constituent through its neighbours and
 * through the fund quote. The sparse kernel applies F·P·Fᵀ in O(k·n) + mirror.
 *
 * Signals: b̂ (premium / discount), p̂ᵢ for illiquid i, NAV = Σ wᵢ p̂ᵢ with
 * variance wᵀ·P·w (StateSnapshot::linearVariance).
 */
final class EtfBasket implements SparseMotionModel, StationaryModel, SerializableModel
{
	private int $k;
	private int $n;
	private float $qA;
	private float $sigmaB2;

	/**
	 * @param array<int, float> $weights basket weights w₁..w_k (shares per unit)
	 * @param array<int, float> $sigma k×k return covariance per second (SPD)
	 * @param float $sigmaA velocity (drift change) intensity
	 * @param float $premiumTheta mean-reversion rate of the premium (0 = random walk)
	 * @param float $premiumSigma premium diffusion
	 * @param array<int, float> $quoteVariances r₁..r_k
	 * @param float $etfVariance r_ETF
	 */
	public function __construct(
		private readonly array $weights,
		private readonly array $sigma,
		private readonly float $sigmaA,
		private readonly float $premiumTheta,
		private readonly float $premiumSigma,
		private readonly array $quoteVariances,
		private readonly float $etfVariance,
	) {
		$k = \count($weights);
		if ($k < 1) {
			throw new InvalidArgument('At least one constituent is required');
		}
		if (\count($sigma) !== $k * $k) {
			throw new InvalidArgument('sigma must be k×k');
		}
		if (\count($quoteVariances) !== $k) {
			throw new InvalidArgument('quoteVariances must have k entries');
		}
		if (!Flat::isSymmetric($sigma, $k, 1e-12) || !Cholesky::isPositiveDefinite($sigma, $k)) {
			throw new InvalidArgument('sigma must be symmetric positive definite');
		}
		if ($sigmaA <= 0.0 || $premiumSigma <= 0.0 || $premiumTheta < 0.0 || $etfVariance <= 0.0) {
			throw new InvalidArgument('sigmaA, premiumSigma, etfVariance must be > 0; premiumTheta >= 0');
		}
		$this->k = $k;
		$this->n = 2 * $k + 1;
		$this->qA = $sigmaA * $sigmaA;
		$this->sigmaB2 = $premiumSigma * $premiumSigma;
	}

	public function constituents(): int
	{
		return $this->k;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function priceIndex(int $i): int
	{
		return $i;
	}

	public function velocityIndex(int $i): int
	{
		return $this->k + $i;
	}

	public function premiumIndex(): int
	{
		return 2 * $this->k;
	}

	/** @return array<int, float> */
	public function weights(): array
	{
		return $this->weights;
	}

	private function premiumDecay(float $dt): float
	{
		return \exp(-$this->premiumTheta * $dt);
	}

	public function transition(float $dt): array
	{
		$n = $this->n;
		$k = $this->k;
		$F = Flat::identity($n);
		for ($i = 0; $i < $k; $i++) {
			$F[$i * $n + $k + $i] = $dt;
		}
		$F[($n - 1) * $n + $n - 1] = $this->premiumDecay($dt);
		return $F;
	}

	public function processNoise(float $dt): array
	{
		$n = $this->n;
		$k = $this->k;
		$Q = \array_fill(0, $n * $n, 0.0);
		$dt2 = $dt * $dt;
		$qpp = $this->qA * $dt2 * $dt / 3.0;
		$qpv = 0.5 * $this->qA * $dt2;
		$qvv = $this->qA * $dt;
		for ($i = 0; $i < $k; $i++) {
			for ($j = 0; $j < $k; $j++) {
				$Q[$i * $n + $j] = $this->sigma[$i * $k + $j] * $dt;
			}
			$Q[$i * $n + $i] += $qpp;
			$Q[$i * $n + $k + $i] = $qpv;
			$Q[($k + $i) * $n + $i] = $qpv;
			$Q[($k + $i) * $n + $k + $i] = $qvv;
		}
		$b = $n - 1;
		if ($this->premiumTheta === 0.0) {
			$Q[$b * $n + $b] = $this->sigmaB2 * $dt;
		} else {
			$d = $this->premiumDecay($dt);
			$Q[$b * $n + $b] = $this->sigmaB2 / (2.0 * $this->premiumTheta) * (1.0 - $d * $d);
		}
		return $Q;
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$n = $this->n;
		$k = $this->k;
		$b = $n - 1;
		$d = $this->premiumDecay($dt);

		for ($i = 0; $i < $k; $i++) {
			$x[$i] += $dt * $x[$k + $i];
		}
		$x[$b] *= $d;

		// row op: row(pᵢ) += dt·row(vᵢ);  row(b) *= d
		for ($i = 0; $i < $k; $i++) {
			$pr = $i * $n;
			$vr = ($k + $i) * $n;
			for ($j = 0; $j < $n; $j++) {
				$P[$pr + $j] += $dt * $P[$vr + $j];
			}
		}
		$br = $b * $n;
		for ($j = 0; $j < $n; $j++) {
			$P[$br + $j] *= $d;
		}
		// column op: col(pᵢ) += dt·col(vᵢ);  col(b) *= d
		for ($r = 0; $r < $n; $r++) {
			$rn = $r * $n;
			for ($i = 0; $i < $k; $i++) {
				$P[$rn + $i] += $dt * $P[$rn + $k + $i];
			}
			$P[$rn + $b] *= $d;
		}
		// restore exact symmetry from the upper triangle
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i + 1; $j < $n; $j++) {
				$P[$j * $n + $i] = $P[$in + $j];
			}
		}
	}

	public function observation(): StaticObservation
	{
		return $this->observationFor($this->weights);
	}

	/**
	 * Observation for a (possibly rebalanced) weight vector.
	 *
	 * @param array<int, float> $weights
	 */
	public function observationFor(array $weights): StaticObservation
	{
		$k = $this->k;
		if (\count($weights) !== $k) {
			throw new InvalidArgument('weights must have k entries');
		}
		$rows = [];
		$variances = [];
		$names = [];
		for ($i = 0; $i < $k; $i++) {
			$rows[] = [$i => 1.0];
			$variances[] = $this->quoteVariances[$i];
			$names[] = 'q' . $i;
		}
		$etf = [];
		for ($i = 0; $i < $k; $i++) {
			if ($weights[$i] !== 0.0) {
				$etf[$i] = $weights[$i];
			}
		}
		$etf[2 * $k] = 1.0;
		$rows[] = $etf;
		$variances[] = $this->etfVariance;
		$names[] = 'etf';
		return new StaticObservation($rows, $variances, $names);
	}

	/**
	 * Filter initialised from first quotes, zero drifts and zero premium.
	 *
	 * @param array<int, float> $firstPrices
	 */
	public function filter(array $firstPrices, ?FilterConfig $config = null, float $velocityPriorStd = 0.1, float $premiumPriorStd = 0.05): KalmanFilter
	{
		$k = $this->k;
		$n = $this->n;
		if (\count($firstPrices) !== $k) {
			throw new InvalidArgument('firstPrices must have k entries');
		}
		$x0 = \array_fill(0, $n, 0.0);
		$diag = [];
		for ($i = 0; $i < $k; $i++) {
			$x0[$i] = $firstPrices[$i];
			$diag[] = $this->quoteVariances[$i] * 4.0;
		}
		for ($i = 0; $i < $k; $i++) {
			$diag[] = $velocityPriorStd * $velocityPriorStd;
		}
		$diag[] = $premiumPriorStd * $premiumPriorStd;
		return new KalmanFilter($this, $this->observation(), $x0, Flat::diagonal($diag), $config);
	}

	/** NAV estimate Σ wᵢ p̂ᵢ. */
	public function nav(KalmanFilter $filter): float
	{
		$x = $filter->mean();
		$nav = 0.0;
		for ($i = 0; $i < $this->k; $i++) {
			$nav += $this->weights[$i] * $x[$i];
		}
		return $nav;
	}

	public static function type(): string
	{
		return 'etf-basket';
	}

	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'weights' => $this->weights,
			'sigma' => $this->sigma,
			'sigmaA' => $this->sigmaA,
			'premiumTheta' => $this->premiumTheta,
			'premiumSigma' => $this->premiumSigma,
			'quoteVariances' => $this->quoteVariances,
			'etfVariance' => $this->etfVariance,
		];
	}

	public static function fromArray(array $config): static
	{
		$weights = ConfigReader::floatList($config, 'weights');
		$k = \count($weights);
		return new self(
			$weights,
			ConfigReader::floatList($config, 'sigma', $k * $k),
			ConfigReader::float($config, 'sigmaA'),
			ConfigReader::float($config, 'premiumTheta', 0.0),
			ConfigReader::float($config, 'premiumSigma'),
			ConfigReader::floatList($config, 'quoteVariances', $k),
			ConfigReader::float($config, 'etfVariance'),
		);
	}
}
