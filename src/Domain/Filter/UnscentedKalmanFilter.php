<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Filter;

use OpenCCK\Kalman\Domain\Contract\NonlinearMotionModel;
use OpenCCK\Kalman\Domain\Contract\NonlinearObservationModel;
use OpenCCK\Kalman\Domain\Diagnostics\JitSanity;
use OpenCCK\Kalman\Domain\Entity\ChannelOutcome;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Entity\UpdateResult;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Exception\NumericalFailure;
use OpenCCK\Kalman\Domain\Exception\OutOfSequenceMeasurement;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * §2.13 unscented Kalman filter (Julier & Uhlmann; Wan & van der Merwe):
 * 2n+1 sigma points χ_i = x̂ ± (√((n+λ)P))_i propagated through f and h,
 * moments recovered with weights W_m, W_c; no Jacobians, exact to second
 * order. Channels are processed one at a time (diagonal R): for each channel
 * the sigma points are regenerated from the current P.
 *
 * Parameters α (spread, 1e-3..1), β (prior knowledge, 2 for Gaussian),
 * κ (secondary scaling, 0 or 3−n). Dense P; positive definiteness is kept by
 * the symmetric form P ← P − K S Kᵀ with S > 0; a Cholesky failure surfaces as
 * NumericalFailure (use a log parametrisation for strictly positive states).
 */
final class UnscentedKalmanFilter
{
	private const LN_2PI = 1.8378770664093453;

	private int $n;
	private int $m;

	/** @var array<int, float> */
	private array $x;

	/** @var array<int, float> n² */
	private array $P;

	/** @var array<int, float> 2n+1 */
	private array $wm;

	/** @var array<int, float> 2n+1 */
	private array $wc;
	private float $scale;   // n + λ
	private int $gateMode;
	private float $gateParam;
	private float $logLikelihood = 0.0;
	private int $steps = 0;
	private ?int $lastTimestampNs = null;
	private float $lastDt = 0.0;
	private FilterConfig $config;

	/** @var array<int, float> m — last innovation per channel (raw path) */
	private array $lastInnovation;

	/** @var array<int, float> m — last innovation variance per channel */
	private array $lastS;

	/** @var array<int, float> m — last weight per channel (−1 = not observed in the last step) */
	private array $lastWeight;

	/** @var array<int, int> channels touched by the last step */
	private array $lastTouched = [];

	/**
	 * @param array<int, float> $x0
	 * @param array<int, float> $P0
	 */
	public function __construct(
		private readonly NonlinearMotionModel $motion,
		private readonly NonlinearObservationModel $observation,
		array $x0,
		array $P0,
		?FilterConfig $config = null,
		float $alpha = 1e-3,
		float $beta = 2.0,
		float $kappa = 0.0,
	) {
		JitSanity::verify();
		$this->config = $config ?? FilterConfig::default();
		$this->n = $motion->stateSize();
		$this->m = $observation->channelCount();
		$n = $this->n;
		if (\count($x0) !== $n) {
			throw DimensionMismatch::forVector('x0', $n, \count($x0));
		}
		if (\count($P0) !== $n * $n) {
			throw DimensionMismatch::forMatrix('P0', $n * $n, \count($P0));
		}
		if ($alpha <= 0.0 || $alpha > 1.0) {
			throw new InvalidArgument('alpha must be in (0, 1]');
		}
		$this->x = \array_values(\array_map('floatval', $x0));
		$this->P = Flat::symmetrize(\array_values(\array_map('floatval', $P0)), $n);
		if (!Cholesky::isPositiveDefinite($this->P, $n)) {
			throw new InvalidArgument('Initial covariance must be SPD');
		}
		$lambda = $alpha * $alpha * ($n + $kappa) - $n;
		$this->scale = $n + $lambda;
		$w = 1.0 / (2.0 * $this->scale);
		$this->wm = \array_fill(0, 2 * $n + 1, $w);
		$this->wc = \array_fill(0, 2 * $n + 1, $w);
		$this->wm[0] = $lambda / $this->scale;
		$this->wc[0] = $lambda / $this->scale + (1.0 - $alpha * $alpha + $beta);
		$this->gateMode = $this->config->gating->mode;
		$this->gateParam = $this->config->gating->parameter;
		$this->lastInnovation = \array_fill(0, $this->m, 0.0);
		$this->lastS = \array_fill(0, $this->m, 0.0);
		$this->lastWeight = \array_fill(0, $this->m, -1.0);
	}

	/**
	 * Sigma points from the current (x, P) as a flat row-major (2n+1)×n block:
	 * row 0 = x, rows 2i+1 / 2i+2 = x ± column i of √((n+λ)P).
	 *
	 * @return array<int, float>
	 */
	private function sigmaPoints(): array
	{
		$n = $this->n;
		$L = Cholesky::decompose(Flat::scale($this->P, $this->scale), $n);
		$points = \array_fill(0, (2 * $n + 1) * $n, 0.0);
		for ($r = 0; $r < $n; $r++) {
			$points[$r] = $this->x[$r];
		}
		for ($i = 0; $i < $n; $i++) {
			$plus = (2 * $i + 1) * $n;
			$minus = $plus + $n;
			for ($r = 0; $r < $n; $r++) {
				$v = $L[$r * $n + $i];
				$points[$plus + $r] = $this->x[$r] + $v;
				$points[$minus + $r] = $this->x[$r] - $v;
			}
		}
		return $points;
	}

	/**
	 * Row i of the sigma-point block as a state vector.
	 *
	 * @param array<int, float> $points
	 * @return array<int, float>
	 */
	private function sigmaRow(array $points, int $i): array
	{
		$n = $this->n;
		return \array_slice($points, $i * $n, $n);
	}

	public function predict(float $dt): void
	{
		if ($dt < 0.0 || !\is_finite($dt)) {
			throw new InvalidArgument('dt must be finite and >= 0');
		}
		if ($dt === 0.0) {
			return;
		}
		$n = $this->n;
		$count = 2 * $n + 1;
		$points = $this->sigmaPoints();
		$propagated = $points; // same layout, overwritten row by row
		$mean = \array_fill(0, $n, 0.0);
		for ($i = 0; $i < $count; $i++) {
			$y = \array_values($this->motion->propagate($this->sigmaRow($points, $i), $dt));
			if (\count($y) !== $n) {
				throw new NumericalFailure('propagate() returned a vector of the wrong size');
			}
			$base = $i * $n;
			$w = $this->wm[$i];
			for ($r = 0; $r < $n; $r++) {
				$propagated[$base + $r] = $y[$r];
				$mean[$r] += $w * $y[$r];
			}
		}
		$P = $this->motion->processNoise($dt);
		for ($i = 0; $i < $count; $i++) {
			$w = $this->wc[$i];
			$base = $i * $n;
			for ($r = 0; $r < $n; $r++) {
				$dr = $w * ($propagated[$base + $r] - $mean[$r]);
				if ($dr === 0.0) {
					continue;
				}
				$rn = $r * $n;
				for ($c = $r; $c < $n; $c++) {
					$P[$rn + $c] += $dr * ($propagated[$base + $c] - $mean[$c]);
				}
			}
		}
		for ($r = 0; $r < $n; $r++) {
			for ($c = $r + 1; $c < $n; $c++) {
				$P[$c * $n + $r] = $P[$r * $n + $c];
			}
		}
		$this->x = $mean;
		$this->P = $P;
		assert($this->invariantsHold(), 'UKF covariance invariants violated after predict');
	}

	public function correct(Measurement $measurement): UpdateResult
	{
		return $this->applyCorrection($measurement->values, 0.0, $measurement->timestampNs);
	}

	public function step(Measurement $measurement): UpdateResult
	{
		$dt = $this->advanceClock($measurement->timestampNs);
		if ($dt > 0.0) {
			$this->predict($dt);
		}
		return $this->applyCorrection($measurement->values, $dt, $measurement->timestampNs);
	}

	/**
	 * Object-free step for workers and backtests (§6.2 p.9). Results are read
	 * via lastInnovation() / lastInnovationVariance() / lastWeight().
	 *
	 * @param array<int, float> $values channel => value
	 */
	public function stepRaw(int $timestampNs, array $values): void
	{
		$dt = $this->advanceClock($timestampNs);
		if ($dt > 0.0) {
			$this->predict($dt);
		}
		$this->correctRaw($values);
		$this->steps++;
	}

	/** @param array<int, float> $values */
	private function applyCorrection(array $values, float $dt, ?int $ts): UpdateResult
	{
		$ll = $this->correctRaw($values);
		$this->steps++;
		$outcomes = [];
		foreach ($this->lastTouched as $ch) {
			$outcomes[] = new ChannelOutcome($ch, $this->lastInnovation[$ch], $this->lastS[$ch], $this->lastWeight[$ch]);
		}
		return new UpdateResult($outcomes, $ll, $dt, $ts);
	}

	/**
	 * Sequential sigma-point correction; returns the log-likelihood increment.
	 *
	 * @param array<int, float> $values
	 */
	private function correctRaw(array $values): float
	{
		$n = $this->n;
		$ll = 0.0;
		foreach ($this->lastTouched as $ch) {
			$this->lastWeight[$ch] = -1.0;
		}
		$this->lastTouched = [];
		foreach ($values as $channel => $z) {
			if ($channel < 0 || $channel >= $this->m) {
				throw new InvalidArgument(\sprintf('Channel %d out of range', $channel));
			}
			$r = $this->observation->channelVariance($channel);
			if (!($r > 0.0)) {
				throw new InvalidArgument('channel variance must be > 0');
			}
			$count = 2 * $n + 1;
			$points = $this->sigmaPoints();
			$zs = \array_fill(0, $count, 0.0);
			$zMean = 0.0;
			for ($i = 0; $i < $count; $i++) {
				$zi = $this->observation->projectChannel($channel, $this->sigmaRow($points, $i));
				$zs[$i] = $zi;
				$zMean += $this->wm[$i] * $zi;
			}
			$pzz = $r;
			$pxz = \array_fill(0, $n, 0.0);
			for ($i = 0; $i < $count; $i++) {
				$dz = $zs[$i] - $zMean;
				$w = $this->wc[$i];
				$pzz += $w * $dz * $dz;
				$base = $i * $n;
				for ($k = 0; $k < $n; $k++) {
					$pxz[$k] += $w * ($points[$base + $k] - $this->x[$k]) * $dz;
				}
			}
			$innovation = $z - $zMean;
			$s = $pzz;
			if (!($s > 0.0) || !\is_finite($innovation)) {
				throw new NumericalFailure(\sprintf('Channel %d: s=%g innovation=%g', $channel, $s, $innovation));
			}
			$weight = 1.0;
			$sEff = $s;
			if ($this->gateMode !== GatingPolicy::MODE_NONE) {
				$d2 = $innovation * $innovation / $s;
				if ($this->gateMode === GatingPolicy::MODE_THRESHOLD) {
					if ($d2 > $this->gateParam) {
						$weight = 0.0;
					}
				} else {
					$u = \sqrt($d2);
					if ($u > $this->gateParam) {
						$weight = $this->gateParam / $u;
						$sEff = $s - $r + $r * $u / $this->gateParam;
					}
				}
			}
			$this->lastInnovation[$channel] = $innovation;
			$this->lastS[$channel] = $s;
			$this->lastWeight[$channel] = $weight;
			$this->lastTouched[] = $channel;
			if ($weight === 0.0) {
				continue;
			}
			// K = Pxz / s ;  x += K·ỹ ;  P −= K s Kᵀ  (upper triangle, mirrored)
			$K = Flat::scale($pxz, 1.0 / $sEff);
			for ($k = 0; $k < $n; $k++) {
				$this->x[$k] += $K[$k] * $innovation;
			}
			for ($a = 0; $a < $n; $a++) {
				for ($b = $a; $b < $n; $b++) {
					$v = $this->P[$a * $n + $b] - $sEff * $K[$a] * $K[$b];
					$this->P[$a * $n + $b] = $v;
					$this->P[$b * $n + $a] = $v;
				}
			}
			$ll -= 0.5 * ($innovation * $innovation / $sEff + \log($sEff) + self::LN_2PI);
		}
		$this->logLikelihood += $ll;
		assert($values === [] || $this->invariantsHold(), 'UKF covariance invariants violated after correct');
		return $ll;
	}

	/** Innovation of the last step on this channel (raw path). */
	public function lastInnovation(int $channel): float
	{
		return $this->lastInnovation[$channel];
	}

	/** Innovation variance s of the last step on this channel. */
	public function lastInnovationVariance(int $channel): float
	{
		return $this->lastS[$channel];
	}

	/** Weight applied on this channel in the last step; −1 when the channel was not observed. */
	public function lastWeight(int $channel): float
	{
		return $this->lastWeight[$channel];
	}

	/** @return array<int, int> channels observed in the last step, in processing order */
	public function lastChannels(): array
	{
		return $this->lastTouched;
	}

	public function lastDt(): float
	{
		return $this->lastDt;
	}

	private function advanceClock(int $timestampNs): float
	{
		if ($this->lastTimestampNs === null) {
			$this->lastTimestampNs = $timestampNs;
			$this->lastDt = 0.0;
			return 0.0;
		}
		$dtNs = $timestampNs - $this->lastTimestampNs;
		if ($dtNs < 0) {
			throw new OutOfSequenceMeasurement($timestampNs, $this->lastTimestampNs);
		}
		$this->lastTimestampNs = $timestampNs;
		$this->lastDt = $dtNs / 1e9;
		return $this->lastDt;
	}

	/** @return array<int, float> */
	public function mean(): array
	{
		return $this->x;
	}

	public function meanAt(int $i): float
	{
		return $this->x[$i];
	}

	/** @return array<int, float> */
	public function covariance(): array
	{
		return $this->P;
	}

	public function variance(int $i): float
	{
		return $this->P[$i * $this->n + $i];
	}

	public function snapshot(): StateSnapshot
	{
		return new StateSnapshot($this->x, $this->P, $this->n, $this->lastTimestampNs, $this->logLikelihood, $this->steps);
	}

	public function logLikelihood(): float
	{
		return $this->logLikelihood;
	}

	public function steps(): int
	{
		return $this->steps;
	}

	public function lastTimestampNs(): ?int
	{
		return $this->lastTimestampNs;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function channelCount(): int
	{
		return $this->m;
	}

	public function invariantsHold(): bool
	{
		return Flat::allFinite($this->P) && Flat::allFinite($this->x) && Flat::isSymmetric($this->P, $this->n) && Cholesky::isPositiveDefinite($this->P, $this->n);
	}
}
