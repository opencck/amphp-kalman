<?php declare(strict_types=1);

namespace OpenCCK\Kalman\App\Calibration;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\FilterFactory;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Smoothing\FilterTrajectory;
use OpenCCK\Kalman\Domain\Smoothing\RauchTungStriebel;

/**
 * §2.11.3 EM for the noise matrices of a REGULARLY sampled linear model
 * (Shumway & Stoffer 1982). Each iteration: filter + RTS (E-step), then
 *
 *   Q̂ = (1/N) Σ_k [ P_{k|N} − F P_{k,k−1|N}ᵀ − P_{k,k−1|N} Fᵀ + F P_{k−1|N} Fᵀ
 *                  + (x̂_{k|N} − F x̂_{k−1|N})(x̂_{k|N} − F x̂_{k−1|N})ᵀ ]
 *   R̂_i = (1/N_i) Σ_k [ (z_ik − h_i x̂_{k|N})² + h_i P_{k|N} h_iᵀ ]     (per channel, diagonal R)
 *
 * The log-likelihood is non-decreasing across iterations. Q̂ is a full
 * per-step matrix: the result is a DiscreteLinear motion model (F frozen at
 * the sampling interval) plus per-channel variances. Typical: 20–50 iterations.
 */
final class ExpectationMaximization
{
	public function __construct(
		private readonly int $iterations = 30,
		private readonly float $minVariance = 1e-12,
		private readonly bool $estimateQ = true,
		private readonly bool $estimateR = true,
	) {
		if ($iterations < 1) {
			throw new InvalidArgument('iterations must be >= 1');
		}
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param array<string, mixed> $modelConfig FilterFactory shape; the motion model is frozen at the first dt
	 * @param array<int, array{ts: int, values: array<int, float>}> $ticks regularly spaced
	 * @param int $iterations
	 * @return array{config: array<string, mixed>, logLikelihood: array<int, float>, Q: array<int, float>, R: array<int, float>, iterations: int}
	 */
	public static function fit(array $modelConfig, array $ticks, int $iterations = 30, float $minVariance = 1e-12, bool $estimateQ = true, bool $estimateR = true): array
	{
		return (new self($iterations, $minVariance, $estimateQ, $estimateR))->run($modelConfig, $ticks);
	}

	/**
	 * @param array<string, mixed> $modelConfig
	 * @param array<int, array{ts: int, values: array<int, float>}> $ticks
	 * @return array{config: array<string, mixed>, logLikelihood: array<int, float>, Q: array<int, float>, R: array<int, float>, iterations: int}
	 */
	public function run(array $modelConfig, array $ticks): array
	{
		$N = \count($ticks);
		if ($N < 3) {
			throw new InvalidArgument('EM needs at least 3 ticks');
		}
		$dt = ($ticks[1]['ts'] - $ticks[0]['ts']) / 1e9;
		if ($dt <= 0.0) {
			throw new InvalidArgument('Ticks must be strictly increasing in time');
		}

		// freeze F, Q at the sampling interval → DiscreteLinear config
		$motion = FilterFactory::motion($modelConfig);
		$frozen = \OpenCCK\Kalman\Domain\Model\Generic\DiscreteLinear::fromModel($motion, $dt);
		$config = $modelConfig;
		$config['motion'] = $frozen->toArray();
		$observation = FilterFactory::observation($config);
		$n = $frozen->stateSize();
		$m = $observation->channelCount();
		$filterConfig = FilterConfig::default()->withGating(GatingPolicy::none())->withMatrixCache(false)->toArray();

		$logLikelihoods = [];
		$Q = $frozen->processNoise($dt);
		$R = [];
		for ($c = 0; $c < $m; $c++) {
			$R[] = $observation->channelVariance($c);
		}

		for ($iter = 0; $iter < $this->iterations; $iter++) {
			// ── E-step: filter with trajectory, then smooth ──
			$filter = FilterFactory::fromConfig($config, $filterConfig);
			$trajectory = new FilterTrajectory($n, $N);
			$filter->setRecorder($trajectory);
			foreach ($ticks as $tick) {
				$filter->stepRaw($tick['ts'], $tick['values']);
			}
			$logLikelihoods[] = $filter->logLikelihood();
			$smoothed = RauchTungStriebel::smooth($trajectory, $filter->motion());
			$F = $filter->motion()->transition($dt);
			$u = $filter->motion()->control($dt);

			// ── M-step ──
			if ($this->estimateQ) {
				$Q = self::mStepQ($smoothed['mean'], $smoothed['covariance'], $smoothed['lagOne'], $F, $u, $n);
				for ($i = 0; $i < $n; $i++) {
					if ($Q[$i * $n + $i] < $this->minVariance) {
						$Q[$i * $n + $i] = $this->minVariance;
					}
				}
			}
			if ($this->estimateR) {
				$rows = [];
				for ($c = 0; $c < $m; $c++) {
					$rows[] = $observation->channelRow($c);
				}
				$R = self::mStepR($ticks, $smoothed['mean'], $smoothed['covariance'], $rows, $n, $m, $this->minVariance, $R);
			}

			$config['motion'] = (new \OpenCCK\Kalman\Domain\Model\Generic\DiscreteLinear($F, $Q, $n, $u))->toArray();
			$obsConfig = $observation instanceof \OpenCCK\Kalman\Domain\Contract\SerializableModel ? $observation->toArray() : null;
			if ($obsConfig === null) {
				throw new InvalidArgument('EM requires a serialisable observation model');
			}
			$obsConfig['variances'] = $R;
			$config['observation'] = $obsConfig;
			$observation = FilterFactory::observation($config);
		}

		return ['config' => $config, 'logLikelihood' => $logLikelihoods, 'Q' => $Q, 'R' => $R, 'iterations' => $this->iterations];
	}

	/**
	 * Closed-form Q update.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<int, array<int, float>> $xs smoothed means
	 * @param array<int, array<int, float>> $Ps smoothed covariances
	 * @param array<int, array<int, float>|null> $lagOne P_{k,k−1|N}
	 * @param array<int, float> $F
	 * @param array<int, float>|null $u
	 * @return array<int, float>
	 */
	public static function mStepQ(array $xs, array $Ps, array $lagOne, array $F, ?array $u, int $n): array
	{
		$N = \count($xs);
		$sum = \array_fill(0, $n * $n, 0.0);
		$count = 0;
		for ($k = 1; $k < $N; $k++) {
			$L = $lagOne[$k];
			if ($L === null) {
				continue;
			}
			$FPprev = Flat::sandwich($F, $Ps[$k - 1], $n);                    // F P_{k−1|N} Fᵀ
			$FLt = Flat::multiplyTransposed($F, $L, $n, $n, $n);               // F P_{k,k−1}ᵀ
			$LFt = Flat::transpose($FLt, $n, $n);                              // P_{k,k−1} Fᵀ
			$pred = Flat::matVec($F, $xs[$k - 1], $n, $n);
			if ($u !== null) {
				$pred = Flat::add($pred, $u);
			}
			$e = Flat::subtract($xs[$k], $pred);
			$term = Flat::add(Flat::subtract(Flat::subtract($Ps[$k], $FLt), $LFt), $FPprev);
			$term = Flat::add($term, Flat::outer($e, $e));
			for ($i = 0; $i < $n * $n; $i++) {
				$sum[$i] += $term[$i];
			}
			$count++;
		}
		if ($count === 0) {
			throw new InvalidArgument('Not enough steps for the Q update');
		}
		return Flat::symmetrize(Flat::scale($sum, 1.0 / $count), $n);
	}

	/**
	 * Closed-form diagonal R update per channel.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<int, array{ts: int, values: array<int, float>}> $ticks
	 * @param array<int, array<int, float>> $xs
	 * @param array<int, array<int, float>> $Ps
	 * @param array<int, array<int, float>> $rows sparse rows per channel
	 * @param array<int, float> $fallback previous R (used when a channel has no observations)
	 * @return array<int, float>
	 */
	public static function mStepR(array $ticks, array $xs, array $Ps, array $rows, int $n, int $m, float $minVariance, array $fallback): array
	{
		$sum = \array_fill(0, $m, 0.0);
		$count = \array_fill(0, $m, 0);
		foreach ($ticks as $k => $tick) {
			foreach ($tick['values'] as $c => $z) {
				$h = $rows[$c];
				$pred = 0.0;
				$hPh = 0.0;
				foreach ($h as $i => $hi) {
					$pred += $hi * $xs[$k][$i];
					foreach ($h as $j => $hj) {
						$hPh += $hi * $hj * $Ps[$k][$i * $n + $j];
					}
				}
				$r = $z - $pred;
				$sum[$c] += $r * $r + $hPh;
				$count[$c]++;
			}
		}
		$R = [];
		for ($c = 0; $c < $m; $c++) {
			$R[$c] = $count[$c] > 0 ? \max($minVariance, $sum[$c] / $count[$c]) : $fallback[$c];
		}
		return $R;
	}
}
