<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Filter;

use OpenCCK\Kalman\Domain\Contract\CovarianceRepresentation;
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
use OpenCCK\Kalman\Domain\Factory\CovarianceFactory;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * §2.13 extended Kalman filter: linearisation by Jacobians at the current
 * estimate, otherwise the same sequential scalar machinery and the same
 * CovarianceRepresentation strategies as KalmanFilter.
 *
 *   predict:  x̂⁻ = f(x̂, dt),      P⁻ = F P Fᵀ + Q,   F = ∂f/∂x|x̂
 *   correct:  ỹ_i = z_i − h_i(x̂⁻),  row = ∂h_i/∂x|x̂⁻, then scalar update per channel
 *
 * The state is re-linearised after every channel (iterated within a step
 * is NOT done — that is the IEKF, not implemented).
 */
final class ExtendedKalmanFilter
{
	private const LN_2PI = 1.8378770664093453;

	private int $n;
	private int $m;

	/** @var array<int, float> */
	private array $x;
	private CovarianceRepresentation $cov;
	private FilterConfig $config;
	private int $gateMode;
	private float $gateParam;
	private float $logLikelihood = 0.0;
	private int $steps = 0;
	private ?int $lastTimestampNs = null;
	private float $lastDt = 0.0;

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
		?CovarianceRepresentation $representation = null,
	) {
		JitSanity::verify();
		$this->config = $config ?? FilterConfig::default();
		$this->n = $motion->stateSize();
		$this->m = $observation->channelCount();
		if (\count($x0) !== $this->n) {
			throw DimensionMismatch::forVector('x0', $this->n, \count($x0));
		}
		if (\count($P0) !== $this->n * $this->n) {
			throw DimensionMismatch::forMatrix('P0', $this->n * $this->n, \count($P0));
		}
		$this->cov = $representation ?? CovarianceFactory::fromConfig($this->config, $this->n);
		$this->cov->fromDense($P0);
		if (!Cholesky::isPositiveDefinite($this->cov->toDense(), $this->n)) {
			throw new InvalidArgument('Initial covariance must be SPD');
		}
		$this->x = \array_values(\array_map('floatval', $x0));
		$this->gateMode = $this->config->gating->mode;
		$this->gateParam = $this->config->gating->parameter;
		$this->lastInnovation = \array_fill(0, $this->m, 0.0);
		$this->lastS = \array_fill(0, $this->m, 0.0);
		$this->lastWeight = \array_fill(0, $this->m, -1.0);
	}

	public function predict(float $dt): void
	{
		if ($dt < 0.0 || !\is_finite($dt)) {
			throw new InvalidArgument('dt must be finite and >= 0');
		}
		if ($dt === 0.0) {
			return;
		}
		$F = $this->motion->jacobian($this->x, $dt);
		$Q = $this->motion->processNoise($dt);
		$this->x = \array_values($this->motion->propagate($this->x, $dt));
		if (\count($this->x) !== $this->n) {
			throw new NumericalFailure('propagate() returned a vector of the wrong size');
		}
		$this->cov->predictDense($F, $Q);
		assert($this->invariantsHold(), 'EKF covariance invariants violated after predict');
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
	 * Sequential linearised correction; returns the log-likelihood increment.
	 *
	 * @param array<int, float> $values
	 */
	private function correctRaw(array $values): float
	{
		$ll = 0.0;
		$n = $this->n;
		$x = &$this->x;
		$cov = $this->cov;
		$obs = $this->observation;
		foreach ($this->lastTouched as $ch) {
			$this->lastWeight[$ch] = -1.0;
		}
		$this->lastTouched = [];
		foreach ($values as $channel => $z) {
			if ($channel < 0 || $channel >= $this->m) {
				throw new InvalidArgument(\sprintf('Channel %d out of range', $channel));
			}
			$predicted = $obs->projectChannel($channel, $x);
			$h = $obs->jacobianRow($channel, $x);
			$r = $obs->channelVariance($channel);
			if (!($r > 0.0)) {
				throw new InvalidArgument('channel variance must be > 0');
			}
			$s = $cov->prepareScalar($h, $r);
			$innovation = $z - $predicted;
			if (!($s > 0.0) || !\is_finite($innovation)) {
				throw new NumericalFailure(\sprintf('Channel %d: s=%g innovation=%g', $channel, $s, $innovation));
			}
			$weight = 1.0;
			$rEff = $r;
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
						$rEff = $r * $u / $this->gateParam;
						$sEff = $s - $r + $rEff;
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
			$phi = $cov->gain();
			$k = $innovation / $sEff;
			for ($i = 0; $i < $n; $i++) {
				$x[$i] += $phi[$i] * $k;
			}
			$cov->commitScalar($rEff);
			$ll -= 0.5 * ($innovation * $innovation / $sEff + \log($sEff) + self::LN_2PI);
		}
		$this->logLikelihood += $ll;
		assert($values === [] || $this->invariantsHold(), 'EKF covariance invariants violated after correct');
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
		return $this->cov->toDense();
	}

	public function variance(int $i): float
	{
		return $this->cov->variance($i);
	}

	public function snapshot(): StateSnapshot
	{
		return new StateSnapshot($this->x, $this->cov->toDense(), $this->n, $this->lastTimestampNs, $this->logLikelihood, $this->steps);
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

	public function lastDt(): float
	{
		return $this->lastDt;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function channelCount(): int
	{
		return $this->m;
	}

	public function motion(): NonlinearMotionModel
	{
		return $this->motion;
	}

	public function observation(): NonlinearObservationModel
	{
		return $this->observation;
	}

	public function invariantsHold(): bool
	{
		$P = $this->cov->toDense();
		return Flat::allFinite($P) && Flat::allFinite($this->x) && Flat::isSymmetric($P, $this->n) && Cholesky::isPositiveDefinite($P, $this->n);
	}
}
