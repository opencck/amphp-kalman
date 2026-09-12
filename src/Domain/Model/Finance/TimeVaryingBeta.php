<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Observation\MutableObservation;

/**
 * §3.5 Dynamic CAPM: r_asset,t = α_t + β_t·r_market,t + ε_t.
 *
 *   x = [α, β]ᵀ,  F = I,  Q = diag(q_α, q_β)·dt,  H_t = [1, r_market,t],  R = σ²_idio
 *
 * Structurally identical to PairsHedge on returns. Rule of thumb for q_β:
 * "beta moves by 0.1 over a quarter" → q_β = 0.1² / (90 · 86400 s).
 */
final class TimeVaryingBeta implements SparseMotionModel, StationaryModel, SerializableModel
{
	private MutableObservation $observation;

	public function __construct(
		private readonly float $qAlpha,
		private readonly float $qBeta,
		private readonly float $sigmaIdio,
	) {
		if ($qAlpha <= 0.0 || $qBeta <= 0.0 || $sigmaIdio <= 0.0) {
			throw new InvalidArgument('qAlpha, qBeta, sigmaIdio must be > 0');
		}
		$this->observation = new MutableObservation([[0 => 1.0, 1 => 0.0]], [$sigmaIdio * $sigmaIdio], ['r_asset']);
	}

	/** q_β from "β changes by $delta over $horizonSeconds". */
	public static function betaNoiseFromHorizon(float $delta, float $horizonSeconds): float
	{
		return $delta * $delta / $horizonSeconds;
	}

	public function stateSize(): int
	{
		return 2;
	}

	public function transition(float $dt): array
	{
		return [1.0, 0.0, 0.0, 1.0];
	}

	public function processNoise(float $dt): array
	{
		return [$this->qAlpha * $dt, 0.0, 0.0, $this->qBeta * $dt];
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		// F = I
	}

	public function observation(): MutableObservation
	{
		return $this->observation;
	}

	/** Sets the current market return in H_t = [1, r_market]. Call before every step. */
	public function setMarketReturn(float $r): void
	{
		$this->observation->setCoefficient(0, 1, $r);
	}

	public function filter(float $alpha0 = 0.0, float $beta0 = 1.0, ?FilterConfig $config = null, float $alphaPriorStd = 0.01, float $betaPriorStd = 0.5): KalmanFilter
	{
		return new KalmanFilter(
			$this,
			$this->observation,
			[$alpha0, $beta0],
			[$alphaPriorStd * $alphaPriorStd, 0.0, 0.0, $betaPriorStd * $betaPriorStd],
			$config,
		);
	}

	/**
	 * β̂ ± k·√P_ββ.
	 *
	 * @return array{0: float, 1: float}
	 */
	public static function betaBand(KalmanFilter $filter, float $k = 1.0): array
	{
		$b = $filter->meanAt(1);
		$s = $k * \sqrt($filter->variance(1));
		return [$b - $s, $b + $s];
	}

	public static function type(): string
	{
		return 'time-varying-beta';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'qAlpha' => $this->qAlpha, 'qBeta' => $this->qBeta, 'sigmaIdio' => $this->sigmaIdio];
	}

	public static function fromArray(array $config): static
	{
		return new self(
			ConfigReader::float($config, 'qAlpha'),
			ConfigReader::float($config, 'qBeta'),
			ConfigReader::float($config, 'sigmaIdio'),
		);
	}
}
