<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Filter;

use OpenCCK\Kalman\Domain\Contract\CovarianceRepresentation;
use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Contract\StepRecorder;
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
 * n-dimensional discrete Kalman filter with sequential scalar correction.
 *
 * Owns x̂ and a CovarianceRepresentation. Every step is synchronous and
 * atomic: no I/O, no awaits, no allocation inside predict()/correctRaw().
 * Value objects (Measurement in, UpdateResult out) are created only at the
 * API boundary; stepRaw() avoids them entirely.
 *
 * Time comes from the data: step()/stepRaw() derive dt from exchange
 * timestamps; predict(dt) is the low-level entry for callers that manage
 * time themselves.
 */
final class KalmanFilter
{
	private const LN_2PI = 1.8378770664093453;

	private int $n;
	private int $m;

	/** @var array<int, float> */
	private array $x;

	/** @var array<int, float> scratch for F·x */
	private array $xTmp;

	private CovarianceRepresentation $cov;
	private bool $sparse;

	private bool $cacheEnabled;
	private int $cacheSize;
	private float $dtQuantumInv;

	/** @var array<int, array<int, float>> */
	private array $cacheF = [];

	/** @var array<int, array<int, float>> */
	private array $cacheQ = [];

	/** @var array<int, array<int, float>|false> */
	private array $cacheU = [];

	private int $gateMode;
	private float $gateParam;

	private float $logLikelihood = 0.0;
	private int $steps = 0;
	private ?int $lastTimestampNs = null;
	private float $lastDt = 0.0;

	/** @var array<int, float> per-channel innovation of the last correction */
	private array $lastInnovation;

	/** @var array<int, float> per-channel innovation variance s of the last correction */
	private array $lastS;

	/** @var array<int, float> per-channel weight of the last correction (−1 = channel absent) */
	private array $lastWeight;

	/** @var array<int, int> channels touched by the last correction (prefix of length lastTouchedCount) */
	private array $lastTouched;
	private int $lastTouchedCount = 0;

	private ?StepRecorder $recorder = null;

	private readonly FilterConfig $config;

	/**
	 * @param array<int, float> $x0 initial mean (n)
	 * @param array<int, float> $P0 initial covariance (n² row-major, SPD)
	 */
	public function __construct(
		private readonly MotionModel $motion,
		private readonly ObservationModel $observation,
		array $x0,
		array $P0,
		?FilterConfig $config = null,
		?CovarianceRepresentation $representation = null,
	) {
		JitSanity::verify();
		$config ??= FilterConfig::default();
		$this->config = $config;
		$this->n = $motion->stateSize();
		$this->m = $observation->channelCount();
		if ($this->n < 1) {
			throw new InvalidArgument('State size must be >= 1');
		}
		if ($this->m < 1) {
			throw new InvalidArgument('Observation model must have >= 1 channel');
		}

		$this->cov = $representation ?? CovarianceFactory::fromConfig($config, $this->n);
		if ($this->cov->size() !== $this->n) {
			throw DimensionMismatch::forVector('representation size', $this->n, $this->cov->size());
		}

		$this->sparse = $motion instanceof SparseMotionModel;
		$this->cacheEnabled = $config->matrixCache && $motion instanceof StationaryModel;
		$this->cacheSize = $config->matrixCacheSize;
		$this->dtQuantumInv = 1.0 / $config->dtQuantum;

		$this->gateMode = $config->gating->mode;
		$this->gateParam = $config->gating->parameter;

		$this->xTmp = \array_fill(0, $this->n, 0.0);
		$this->lastInnovation = \array_fill(0, $this->m, 0.0);
		$this->lastS = \array_fill(0, $this->m, 0.0);
		$this->lastWeight = \array_fill(0, $this->m, -1.0);
		$this->lastTouched = \array_fill(0, $this->m, 0);

		$this->x = \array_fill(0, $this->n, 0.0);
		$this->reset($x0, $P0);
	}

	public static function fromSnapshot(
		MotionModel $motion,
		ObservationModel $observation,
		StateSnapshot $snapshot,
		?FilterConfig $config = null,
	): self {
		$filter = new self($motion, $observation, $snapshot->mean, $snapshot->covariance, $config ?? FilterConfig::default());
		$filter->lastTimestampNs = $snapshot->timestampNs;
		$filter->logLikelihood = $snapshot->logLikelihood;
		$filter->steps = $snapshot->steps;
		return $filter;
	}

	// ────────────────────────────────────────────────────────────── time update

	/**
	 * x̂ ← F x̂ + B u,  P ← F P Fᵀ + Q  for an interval of dt seconds.
	 * dt = 0 is a no-op; dt < 0 is an error.
	 */
	public function predict(float $dt): void
	{
		if ($dt < 0.0) {
			throw new InvalidArgument(\sprintf('dt must be >= 0, got %g', $dt));
		}
		if ($dt === 0.0) {
			return;
		}
		if (!\is_finite($dt)) {
			throw new InvalidArgument('dt must be finite');
		}

		$key = 0;
		$hit = false;
		if ($this->cacheEnabled) {
			$key = (int) \round($dt * $this->dtQuantumInv);
			$hit = isset($this->cacheQ[$key]);
		}

		if ($hit) {
			$Q = $this->cacheQ[$key];
		} else {
			$Q = $this->motion->processNoise($dt);
		}

		if ($this->sparse) {
			/** @var SparseMotionModel $model */
			$model = $this->motion;
			$this->cov->predictSparse($model, $dt, $this->x, $Q);
			if ($this->cacheEnabled && !$hit) {
				$this->cacheStore($key, [], $Q, false);
			}
		} else {
			if ($hit) {
				$F = $this->cacheF[$key];
				$u = $this->cacheU[$key];
			} else {
				$F = $this->motion->transition($dt);
				$u = $this->motion->control($dt) ?? false;
				if ($this->cacheEnabled) {
					$this->cacheStore($key, $F, $Q, $u);
				}
			}

			$n = $this->n;
			$x = &$this->x;
			$t = &$this->xTmp;
			for ($i = 0; $i < $n; $i++) {
				$in = $i * $n;
				$sum = 0.0;
				for ($j = 0; $j < $n; $j++) {
					$sum += $F[$in + $j] * $x[$j];
				}
				$t[$i] = $sum;
			}
			if ($u !== false) {
				for ($i = 0; $i < $n; $i++) {
					$t[$i] += $u[$i];
				}
			}
			// swap buffers (refcount swap, no copy)
			$tmp = $this->x;
			$this->x = $this->xTmp;
			$this->xTmp = $tmp;

			$this->cov->predictDense($F, $Q);
		}

		assert($this->invariantsHold(), 'covariance invariants violated after predict');
	}

	// ─────────────────────────────────────────────────────── measurement update

	/**
	 * Corrects with every channel present in the measurement (sequentially),
	 * without any time update. Use step() for the normal predict+correct path.
	 */
	public function correct(Measurement $measurement): UpdateResult
	{
		$this->recorder?->recordPrior(0.0, $this->x, $this->cov->toDense());
		$ll = $this->correctRaw($measurement->values);
		$this->steps++;
		$this->recorder?->recordPosterior($this->x, $this->cov->toDense(), $measurement->timestampNs);
		return $this->buildResult($ll, 0.0, $measurement->timestampNs);
	}

	/**
	 * Full step: dt from exchange timestamps → predict → correct.
	 * The first measurement initialises the clock (no predict).
	 *
	 * @throws OutOfSequenceMeasurement when the timestamp goes backwards
	 */
	public function step(Measurement $measurement): UpdateResult
	{
		$dt = $this->advanceClock($measurement->timestampNs);
		if ($dt > 0.0) {
			$this->predict($dt);
		}
		$this->recorder?->recordPrior($dt, $this->x, $this->cov->toDense());
		$ll = $this->correctRaw($measurement->values);
		$this->steps++;
		$this->recorder?->recordPosterior($this->x, $this->cov->toDense(), $measurement->timestampNs);
		return $this->buildResult($ll, $dt, $measurement->timestampNs);
	}

	/**
	 * Object-free step for workers and backtests. Results are read via
	 * lastInnovation() / lastInnovationVariance() / lastWeight().
	 *
	 * @param array<int, float> $values channel => value
	 */
	public function stepRaw(int $timestampNs, array $values): void
	{
		$dt = $this->advanceClock($timestampNs);
		if ($dt > 0.0) {
			$this->predict($dt);
		}
		$this->recorder?->recordPrior($dt, $this->x, $this->cov->toDense());
		$this->correctRaw($values);
		$this->steps++;
		$this->recorder?->recordPosterior($this->x, $this->cov->toDense(), $timestampNs);
	}

	/**
	 * Attaches (or detaches with null) a recorder of priors/posteriors for
	 * smoothing, EM and backtests. Adds a dense copy of P per step.
	 */
	public function setRecorder(?StepRecorder $recorder): void
	{
		$this->recorder = $recorder;
	}

	/**
	 * Sequential scalar correction over the given channels. Returns the
	 * log-likelihood increment. Allocation-free.
	 *
	 * @param array<int, float> $values channel => value
	 */
	private function correctRaw(array $values): float
	{
		$n = $this->n;
		$m = $this->m;
		$x = &$this->x;
		$cov = $this->cov;
		$obs = $this->observation;
		$mode = $this->gateMode;
		$param = $this->gateParam;

		$lastInnovation = &$this->lastInnovation;
		$lastS = &$this->lastS;
		$lastWeight = &$this->lastWeight;
		$touched = &$this->lastTouched;
		$count = 0;
		$ll = 0.0;

		// reset weights of channels touched last time (only those, O(touched))
		$prev = $this->lastTouchedCount;
		for ($i = 0; $i < $prev; $i++) {
			$lastWeight[$touched[$i]] = -1.0;
		}

		foreach ($values as $channel => $z) {
			if ($channel < 0 || $channel >= $m) {
				throw new InvalidArgument(\sprintf('Channel %d out of range [0, %d)', $channel, $m));
			}

			$h = $obs->channelRow($channel);
			$r = $obs->channelVariance($channel);
			if (!($r > 0.0)) {
				throw new InvalidArgument(\sprintf('Channel %d variance must be > 0, got %g', $channel, $r));
			}

			$s = $cov->prepareScalar($h, $r);

			$predicted = 0.0;
			foreach ($h as $j => $c) {
				$predicted += $c * $x[$j];
			}
			$innovation = $z - $predicted;

			if (!($s > 0.0) || !\is_finite($innovation)) {
				throw new NumericalFailure(\sprintf(
					'Channel %d: innovation variance %g, innovation %g',
					$channel,
					$s,
					$innovation,
				));
			}

			// ── gate ──
			$weight = 1.0;
			$rEff = $r;
			$sEff = $s;
			if ($mode !== GatingPolicy::MODE_NONE) {
				$d2 = $innovation * $innovation / $s;
				if ($mode === GatingPolicy::MODE_THRESHOLD) {
					if ($d2 > $param) {
						$weight = 0.0;
					}
				} else {
					$u = \sqrt($d2);
					if ($u > $param) {
						$weight = $param / $u;
						$rEff = $r * $u / $param;
						$sEff = $s - $r + $rEff;
					}
				}
			}

			$touched[$count++] = $channel;
			$lastInnovation[$channel] = $innovation;
			$lastS[$channel] = $s;
			$lastWeight[$channel] = $weight;

			if ($weight === 0.0) {
				continue;
			}

			// ── state update x += phi · (innovation / s) ──
			$phi = $cov->gain();
			$k = $innovation / $sEff;
			for ($i = 0; $i < $n; $i++) {
				$x[$i] += $phi[$i] * $k;
			}
			$cov->commitScalar($rEff);

			$ll -= 0.5 * ($innovation * $innovation / $sEff + \log($sEff) + self::LN_2PI);
		}

		$this->lastTouchedCount = $count;
		$this->logLikelihood += $ll;

		assert($count === 0 || $this->invariantsHold(), 'covariance invariants violated after correct');

		return $ll;
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

	private function buildResult(float $ll, float $dt, ?int $timestampNs): UpdateResult
	{
		$outcomes = [];
		$count = $this->lastTouchedCount;
		for ($i = 0; $i < $count; $i++) {
			$ch = $this->lastTouched[$i];
			$outcomes[] = new ChannelOutcome($ch, $this->lastInnovation[$ch], $this->lastS[$ch], $this->lastWeight[$ch]);
		}
		return new UpdateResult($outcomes, $ll, $dt, $timestampNs);
	}

	/**
	 * @param array<int, float> $F
	 * @param array<int, float> $Q
	 * @param array<int, float>|false $u
	 */
	private function cacheStore(int $key, array $F, array $Q, array|false $u): void
	{
		if (\count($this->cacheQ) >= $this->cacheSize) {
			$oldest = \array_key_first($this->cacheQ);
			if ($oldest !== null) {
				unset($this->cacheQ[$oldest], $this->cacheF[$oldest], $this->cacheU[$oldest]);
			}
		}
		$this->cacheQ[$key] = $Q;
		$this->cacheF[$key] = $F;
		$this->cacheU[$key] = $u;
	}

	// ───────────────────────────────────────────────────────────────── access

	/** @return array<int, float> current mean (no copy — treat as read-only) */
	public function mean(): array
	{
		return $this->x;
	}

	public function meanAt(int $i): float
	{
		if ($i < 0 || $i >= $this->n) {
			throw new InvalidArgument(\sprintf('State index %d out of range', $i));
		}
		return $this->x[$i];
	}

	/** @return array<int, float> n² row-major copy of P */
	public function covariance(): array
	{
		return $this->cov->toDense();
	}

	public function variance(int $i): float
	{
		if ($i < 0 || $i >= $this->n) {
			throw new InvalidArgument(\sprintf('State index %d out of range', $i));
		}
		return $this->cov->variance($i);
	}

	public function snapshot(): StateSnapshot
	{
		return new StateSnapshot(
			$this->x,
			$this->cov->toDense(),
			$this->n,
			$this->lastTimestampNs,
			$this->logLikelihood,
			$this->steps,
		);
	}

	/**
	 * Replaces the state; keeps configuration. Resets the clock unless a
	 * timestamp is given, and zeroes the accumulated log-likelihood.
	 *
	 * @param array<int, float> $x0
	 * @param array<int, float> $P0
	 */
	public function reset(array $x0, array $P0, ?int $timestampNs = null): void
	{
		$n = $this->n;
		if (\count($x0) !== $n) {
			throw DimensionMismatch::forVector('x0', $n, \count($x0));
		}
		if (\count($P0) !== $n * $n) {
			throw DimensionMismatch::forMatrix('P0', $n * $n, \count($P0));
		}
		if (!Flat::allFinite($x0) || !Flat::allFinite($P0)) {
			throw new InvalidArgument('Initial state must be finite');
		}
		$x = &$this->x;
		for ($i = 0; $i < $n; $i++) {
			$x[$i] = (float) $x0[$i];
		}
		$this->cov->fromDense($P0);
		if (!Cholesky::isPositiveDefinite($this->cov->toDense(), $n)) {
			throw new InvalidArgument('Initial covariance must be symmetric positive definite');
		}
		$this->lastTimestampNs = $timestampNs;
		$this->lastDt = 0.0;
		$this->logLikelihood = 0.0;
		$this->steps = 0;
		for ($c = 0; $c < $this->m; $c++) {
			$this->lastWeight[$c] = -1.0;
		}
		$this->lastTouchedCount = 0;
	}

	// ─────────────────────────────────────────────── corporate actions / edits

	/**
	 * Rescales state components (split, redenomination): x_i ← f_i·x_i,
	 * P_ij ← f_i·f_j·P_ij. Components not listed keep factor 1. Rare
	 * operation — goes through a dense copy of P.
	 *
	 * @param array<int, float> $factors state index => factor
	 */
	public function scaleState(array $factors): void
	{
		$n = $this->n;
		$f = \array_fill(0, $n, 1.0);
		foreach ($factors as $i => $v) {
			if ($i < 0 || $i >= $n) {
				throw new InvalidArgument(\sprintf('State index %d out of range', $i));
			}
			if (!\is_finite($v) || $v === 0.0) {
				throw new InvalidArgument('Scale factors must be finite and non-zero');
			}
			$f[$i] = $v;
		}
		$P = $this->cov->toDense();
		for ($i = 0; $i < $n; $i++) {
			$this->x[$i] *= $f[$i];
			for ($j = 0; $j < $n; $j++) {
				$P[$i * $n + $j] *= $f[$i] * $f[$j];
			}
		}
		$this->cov->fromDense($P);
	}

	/**
	 * Shifts state components by a known amount (dividend, coupon): x_i += d_i.
	 *
	 * @param array<int, float> $deltas state index => shift
	 */
	public function shiftState(array $deltas): void
	{
		foreach ($deltas as $i => $v) {
			if ($i < 0 || $i >= $this->n) {
				throw new InvalidArgument(\sprintf('State index %d out of range', $i));
			}
			if (!\is_finite($v)) {
				throw new InvalidArgument('Shifts must be finite');
			}
			$this->x[$i] += $v;
		}
	}

	/**
	 * Inflates the uncertainty of state components (rebalance, model break):
	 * P_ii += δ_i. Rare operation — goes through a dense copy of P.
	 *
	 * @param array<int, float> $deltas state index => added variance (≥ 0)
	 */
	public function addVariance(array $deltas): void
	{
		$n = $this->n;
		$P = $this->cov->toDense();
		foreach ($deltas as $i => $v) {
			if ($i < 0 || $i >= $n) {
				throw new InvalidArgument(\sprintf('State index %d out of range', $i));
			}
			if (!\is_finite($v) || $v < 0.0) {
				throw new InvalidArgument('Added variance must be finite and >= 0');
			}
			$P[$i * $n + $i] += $v;
		}
		$this->cov->fromDense($P);
	}

	/** Accumulated innovation log-likelihood Σ −½[ỹ²/s + ln s + ln 2π] over accepted channels. */
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

	/** dt (seconds) used by the last step()/stepRaw(). */
	public function lastDt(): float
	{
		return $this->lastDt;
	}

	public function lastInnovation(int $channel): float
	{
		$this->checkChannel($channel);
		return $this->lastInnovation[$channel];
	}

	public function lastInnovationVariance(int $channel): float
	{
		$this->checkChannel($channel);
		return $this->lastS[$channel];
	}

	/** 1 accepted, 0 rejected, (0,1) Huber, −1 channel absent in the last step. */
	public function lastWeight(int $channel): float
	{
		$this->checkChannel($channel);
		return $this->lastWeight[$channel];
	}

	/** @return list<int> channels present in the last correction */
	public function lastChannels(): array
	{
		return \array_slice($this->lastTouched, 0, $this->lastTouchedCount);
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function channelCount(): int
	{
		return $this->m;
	}

	public function config(): FilterConfig
	{
		return $this->config;
	}

	public function motion(): MotionModel
	{
		return $this->motion;
	}

	public function observation(): ObservationModel
	{
		return $this->observation;
	}

	public function representation(): CovarianceRepresentation
	{
		return $this->cov;
	}

	// ─────────────────────────────────────────────────────────────── invariants

	/**
	 * Debug-only checks (§7.3), compiled out with zend.assertions=-1:
	 * exact symmetry, finiteness, positive definiteness.
	 */
	public function invariantsHold(): bool
	{
		$P = $this->cov->toDense();
		if (!Flat::allFinite($P) || !Flat::allFinite($this->x)) {
			return false;
		}
		if (!Flat::isSymmetric($P, $this->n)) {
			return false;
		}
		return Cholesky::isPositiveDefinite($P, $this->n);
	}

	private function checkChannel(int $channel): void
	{
		if ($channel < 0 || $channel >= $this->m) {
			throw new InvalidArgument(\sprintf('Channel %d out of range [0, %d)', $channel, $this->m));
		}
	}
}
