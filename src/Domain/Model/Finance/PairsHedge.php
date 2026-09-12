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
use OpenCCK\Kalman\Domain\Model\Observation\MutableObservation;

/**
 * §3.3 Pairs trading — time-varying cointegration regression y_t = α_t + β_t x_t (+ s_t) + ε_t.
 *
 *   x = [α, β]ᵀ  (n = 2)  or  [α, β, s]ᵀ with an OU spread s (n = 3)
 *   F = I (α, β random walks),  s ← e^{−θ dt} s
 *   Q = diag(q_α·dt, q_β·dt [, σ_s²/(2θ)(1 − e^{−2θdt})])
 *   z = [y_t],  H_t = [1, x_t, (1)]  ← time-varying: call setRegressor(x_t) BEFORE each step
 *   R = σ_ε²
 *
 * The innovation ỹ = y − α̂ − β̂x is the spread; √S its live scale; z-score = ỹ/√S.
 * q_β is tiny relative to α, so P is badly conditioned → UD form by default.
 */
final class PairsHedge implements SparseMotionModel, StationaryModel, SerializableModel
{
	private int $n;
	private MutableObservation $observation;

	public function __construct(
		private readonly float $qAlpha,
		private readonly float $qBeta,
		private readonly float $sigmaEps,
		private readonly ?float $spreadTheta = null,
		private readonly ?float $spreadSigma = null,
	) {
		if ($qAlpha <= 0.0 || $qBeta <= 0.0 || $sigmaEps <= 0.0) {
			throw new InvalidArgument('qAlpha, qBeta, sigmaEps must be > 0');
		}
		if (($spreadTheta === null) !== ($spreadSigma === null)) {
			throw new InvalidArgument('spreadTheta and spreadSigma must be given together');
		}
		if ($spreadTheta !== null && ($spreadTheta <= 0.0 || ($spreadSigma ?? 0.0) <= 0.0)) {
			throw new InvalidArgument('spreadTheta and spreadSigma must be > 0');
		}
		$this->n = $spreadTheta === null ? 2 : 3;
		$row = [0 => 1.0, 1 => 0.0];
		if ($this->n === 3) {
			$row[2] = 1.0;
		}
		$this->observation = new MutableObservation([$row], [$sigmaEps * $sigmaEps], ['y']);
	}

	public function hasSpread(): bool
	{
		return $this->n === 3;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function transition(float $dt): array
	{
		if ($this->n === 2) {
			return [1.0, 0.0, 0.0, 1.0];
		}
		$e = \exp(-($this->spreadTheta ?? 0.0) * $dt);
		return [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, $e];
	}

	public function processNoise(float $dt): array
	{
		if ($this->n === 2) {
			return [$this->qAlpha * $dt, 0.0, 0.0, $this->qBeta * $dt];
		}
		$theta = $this->spreadTheta ?? 0.0;
		$sigma = $this->spreadSigma ?? 0.0;
		$e = \exp(-$theta * $dt);
		$qs = $sigma * $sigma / (2.0 * $theta) * (1.0 - $e * $e);
		return [$this->qAlpha * $dt, 0.0, 0.0, 0.0, $this->qBeta * $dt, 0.0, 0.0, 0.0, $qs];
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		if ($this->n === 2) {
			return; // F = I
		}
		$e = \exp(-($this->spreadTheta ?? 0.0) * $dt);
		$x[2] *= $e;
		$P[2] *= $e;
		$P[5] *= $e;
		$P[6] = $P[2];
		$P[7] = $P[5];
		$P[8] *= $e * $e;
	}

	/** The time-varying observation model shared with the filter. */
	public function observation(): MutableObservation
	{
		return $this->observation;
	}

	/** Sets the current regressor x_t in H_t = [1, x_t, (1)]. Call before every step. */
	public function setRegressor(float $x): void
	{
		$this->observation->setCoefficient(0, 1, $x);
	}

	/**
	 * @param float $alpha0 initial intercept
	 * @param float $beta0 initial hedge ratio
	 */
	public function filter(float $alpha0, float $beta0, ?FilterConfig $config = null, float $alphaPriorStd = 1.0, float $betaPriorStd = 0.5): KalmanFilter
	{
		$config ??= FilterConfig::default()->withForm(FilterForm::UD);
		if ($this->n === 2) {
			$x0 = [$alpha0, $beta0];
			$P0 = [$alphaPriorStd * $alphaPriorStd, 0.0, 0.0, $betaPriorStd * $betaPriorStd];
		} else {
			$theta = $this->spreadTheta ?? 1.0;
			$sigma = $this->spreadSigma ?? 1.0;
			$x0 = [$alpha0, $beta0, 0.0];
			$P0 = [
				$alphaPriorStd * $alphaPriorStd, 0.0, 0.0,
				0.0, $betaPriorStd * $betaPriorStd, 0.0,
				0.0, 0.0, $sigma * $sigma / (2.0 * $theta),
			];
		}
		return new KalmanFilter($this, $this->observation, $x0, $P0, $config);
	}

	/**
	 * Raw tick for FilterBatch / workers: the regressor travels as a per-tick
	 * observation row so that H_t = [1, x_t] crosses process boundaries.
	 *
	 * @return array{ts: int, values: array<int, float>, rows: array<int, array<int, float>>}
	 */
	public function tick(int $timestampNs, float $y, float $x): array
	{
		$row = [0 => 1.0, 1 => $x];
		if ($this->n === 3) {
			$row[2] = 1.0;
		}
		return ['ts' => $timestampNs, 'values' => [0 => $y], 'rows' => [0 => $row]];
	}

	/**
	 * Serialisable model description for FilterFactory::fromConfig().
	 *
	 * @return array<string, mixed>
	 */
	public function config(float $alpha0, float $beta0, float $alphaPriorStd = 1.0, float $betaPriorStd = 0.5): array
	{
		$filter = $this->filter($alpha0, $beta0, null, $alphaPriorStd, $betaPriorStd);
		return [
			'motion' => $this->toArray(),
			'observation' => $this->observation->toArray(),
			'x0' => $filter->mean(),
			'P0' => $filter->covariance(),
		];
	}

	/** z-score of the last innovation: ỹ/√S — the trading signal. */
	public static function zScore(KalmanFilter $filter): float
	{
		$s = $filter->lastInnovationVariance(0);
		return $s > 0.0 ? $filter->lastInnovation(0) / \sqrt($s) : \NAN;
	}

	/** Spread half-life ln2/θ in seconds (OU variant only). */
	public function spreadHalfLife(): ?float
	{
		return $this->spreadTheta === null ? null : \M_LN2 / $this->spreadTheta;
	}

	public static function type(): string
	{
		return 'pairs-hedge';
	}

	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'qAlpha' => $this->qAlpha,
			'qBeta' => $this->qBeta,
			'sigmaEps' => $this->sigmaEps,
			'spreadTheta' => $this->spreadTheta,
			'spreadSigma' => $this->spreadSigma,
		];
	}

	public static function fromArray(array $config): static
	{
		$theta = isset($config['spreadTheta']) ? ConfigReader::float($config, 'spreadTheta') : null;
		$sigma = isset($config['spreadSigma']) ? ConfigReader::float($config, 'spreadSigma') : null;
		return new self(
			ConfigReader::float($config, 'qAlpha'),
			ConfigReader::float($config, 'qBeta'),
			ConfigReader::float($config, 'sigmaEps'),
			$theta,
			$sigma,
		);
	}
}
