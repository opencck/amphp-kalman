<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Filter;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Diagnostics\JitSanity;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Exception\OutOfSequenceMeasurement;
use OpenCCK\Kalman\Domain\Exception\UnsupportedOperation;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * §2.6 information form: Y = P⁻¹, ŷ = P⁻¹x̂.
 *
 *   correct:  Y += hᵀh / r,   ŷ += hᵀz / r          (per channel, O(n·nnz) — additive, order-free)
 *   predict:  P = Y⁻¹ → P⁻ = F P Fᵀ + Q → Y = (P⁻)⁻¹   (two Cholesky inversions, O(n³))
 *
 * Ideal when m ≫ n (Nelson–Siegel) and for distributed fusion: every source
 * contributes (hᵀh/r, hᵀz/r) terms that are simply summed (see addInformation()).
 * Y₀ = 0 expresses "no prior" — the mean is undefined until Y becomes positive
 * definite; predict() is skipped while Y is singular.
 */
final class InformationFilter
{
	private int $n;
	private int $m;

	/** @var array<int, float> n² */
	private array $Y;

	/** @var array<int, float> n */
	private array $y;

	private ?int $lastTimestampNs = null;
	private int $steps = 0;

	/**
	 * @param array<int, float>|null $Y0 n² information matrix (null → zero: no prior)
	 * @param array<int, float>|null $y0 n information vector
	 */
	public function __construct(
		private readonly MotionModel $motion,
		private readonly ObservationModel $observation,
		?array $Y0 = null,
		?array $y0 = null,
	) {
		JitSanity::verify();
		$this->n = $motion->stateSize();
		$this->m = $observation->channelCount();
		$n = $this->n;
		$this->Y = $Y0 ?? \array_fill(0, $n * $n, 0.0);
		$this->y = $y0 ?? \array_fill(0, $n, 0.0);
		if (\count($this->Y) !== $n * $n) {
			throw DimensionMismatch::forMatrix('Y0', $n * $n, \count($this->Y));
		}
		if (\count($this->y) !== $n) {
			throw DimensionMismatch::forVector('y0', $n, \count($this->y));
		}
	}

	/**
	 * From a covariance-form prior x̂, P (SPD).
	 *
	 * @param array<int, float> $x0
	 * @param array<int, float> $P0
	 */
	public static function fromCovariance(MotionModel $motion, ObservationModel $observation, array $x0, array $P0): self
	{
		$n = $motion->stateSize();
		$L = Cholesky::decompose($P0, $n);
		$Y = Cholesky::inverse($L, $n);
		return new self($motion, $observation, $Y, Flat::matVec($Y, $x0, $n, $n));
	}

	public static function fromSnapshot(MotionModel $motion, ObservationModel $observation, StateSnapshot $snapshot): self
	{
		$f = self::fromCovariance($motion, $observation, $snapshot->mean, $snapshot->covariance);
		$f->lastTimestampNs = $snapshot->timestampNs;
		$f->steps = $snapshot->steps;
		return $f;
	}

	public function hasInformation(): bool
	{
		return Cholesky::isPositiveDefinite($this->Y, $this->n);
	}

	/** Time update through the covariance form; skipped while Y is singular (no prior). */
	public function predict(float $dt): void
	{
		if ($dt < 0.0) {
			throw new InvalidArgument('dt must be >= 0');
		}
		if ($dt === 0.0 || !$this->hasInformation()) {
			return;
		}
		$n = $this->n;
		$L = Cholesky::decompose($this->Y, $n);
		$P = Cholesky::inverse($L, $n);
		$x = Cholesky::solve($L, $this->y, $n);
		$F = $this->motion->transition($dt);
		$Q = $this->motion->processNoise($dt);
		$u = $this->motion->control($dt);
		$xNew = Flat::matVec($F, $x, $n, $n);
		if ($u !== null) {
			$xNew = Flat::add($xNew, $u);
		}
		$PNew = Flat::add(Flat::sandwich($F, $P, $n), $Q);
		$L2 = Cholesky::decompose($PNew, $n);
		$this->Y = Cholesky::inverse($L2, $n);
		$this->y = Flat::matVec($this->Y, $xNew, $n, $n);
	}

	/** Measurement update: pure accumulation of information, channel order irrelevant. */
	public function correct(Measurement $measurement): void
	{
		foreach ($measurement->values as $channel => $z) {
			if ($channel < 0 || $channel >= $this->m) {
				throw new InvalidArgument(\sprintf('Channel %d out of range', $channel));
			}
			$this->addInformation($this->observation->channelRow($channel), $this->observation->channelVariance($channel), $z);
		}
		$this->steps++;
	}

	/**
	 * Adds one scalar information contribution hᵀh/r, hᵀz/r — the unit that
	 * remote sources send in a distributed fusion (§3.4).
	 *
	 * @param array<int, float> $h sparse row
	 */
	public function addInformation(array $h, float $r, float $z): void
	{
		if (!($r > 0.0)) {
			throw new InvalidArgument('r must be > 0');
		}
		$n = $this->n;
		$inv = 1.0 / $r;
		foreach ($h as $i => $hi) {
			foreach ($h as $j => $hj) {
				$this->Y[$i * $n + $j] += $hi * $hj * $inv;
			}
			$this->y[$i] += $hi * $z * $inv;
		}
	}

	/** dt from exchange timestamps, then predict + correct. */
	public function step(Measurement $measurement): void
	{
		$this->stepRaw($measurement->timestampNs, $measurement->values);
	}

	/**
	 * Object-free step (§6.2 p.9): the information form has no innovation,
	 * so there is nothing to read back except the state.
	 *
	 * @param array<int, float> $values channel => value
	 */
	public function stepRaw(int $timestampNs, array $values): void
	{
		if ($this->lastTimestampNs !== null) {
			$dtNs = $timestampNs - $this->lastTimestampNs;
			if ($dtNs < 0) {
				throw new OutOfSequenceMeasurement($timestampNs, $this->lastTimestampNs);
			}
			if ($dtNs > 0) {
				$this->predict($dtNs / 1e9);
			}
		}
		$this->lastTimestampNs = $timestampNs;
		$obs = $this->observation;
		$m = $this->m;
		foreach ($values as $channel => $z) {
			if ($channel < 0 || $channel >= $m) {
				throw new InvalidArgument(\sprintf('Channel %d out of range', $channel));
			}
			$this->addInformation($obs->channelRow($channel), $obs->channelVariance($channel), $z);
		}
		$this->steps++;
	}

	/** @return array<int, float> x̂ = Y⁻¹ŷ */
	public function mean(): array
	{
		if (!$this->hasInformation()) {
			throw new UnsupportedOperation('Mean is undefined: the information matrix is singular (no prior yet)');
		}
		return Cholesky::solve(Cholesky::decompose($this->Y, $this->n), $this->y, $this->n);
	}

	/** @return array<int, float> P = Y⁻¹ */
	public function covariance(): array
	{
		if (!$this->hasInformation()) {
			throw new UnsupportedOperation('Covariance is undefined: the information matrix is singular');
		}
		return Cholesky::inverse(Cholesky::decompose($this->Y, $this->n), $this->n);
	}

	/** @return array<int, float> */
	public function informationMatrix(): array
	{
		return $this->Y;
	}

	/** @return array<int, float> */
	public function informationVector(): array
	{
		return $this->y;
	}

	public function snapshot(): StateSnapshot
	{
		return new StateSnapshot($this->mean(), $this->covariance(), $this->n, $this->lastTimestampNs, 0.0, $this->steps);
	}

	public function lastTimestampNs(): ?int
	{
		return $this->lastTimestampNs;
	}

	public function stateSize(): int
	{
		return $this->n;
	}
}
