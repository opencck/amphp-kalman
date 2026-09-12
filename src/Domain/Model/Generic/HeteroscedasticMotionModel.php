<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * §2.16 decorator: scales Q by the current level and/or a time-of-day
 * profile, Q' = m·D·Q·D with D = diag(scales). Typical use — prices with
 * multiplicative noise: scales[i] = p̂ᵢ for price states, 1 for the rest,
 * so that Q_prices ∝ price² (heteroscedastic KF without log-prices).
 *
 * The caller updates the scales before each step (e.g. from the filter's
 * mean). Not stationary → the filter's matrix cache is bypassed.
 */
final class HeteroscedasticMotionModel implements SparseMotionModel
{
	/** @var array<int, float> */
	private array $scales;
	private float $multiplier = 1.0;
	private int $n;

	public function __construct(private readonly MotionModel $inner)
	{
		$this->n = $inner->stateSize();
		$this->scales = \array_fill(0, $this->n, 1.0);
	}

	public function inner(): MotionModel
	{
		return $this->inner;
	}

	/** @param array<int, float> $scales per-state factors (state index => scale), missing = 1 */
	public function setScales(array $scales): void
	{
		for ($i = 0; $i < $this->n; $i++) {
			$this->scales[$i] = 1.0;
		}
		foreach ($scales as $i => $s) {
			if ($i < 0 || $i >= $this->n) {
				throw new InvalidArgument(\sprintf('State index %d out of range', $i));
			}
			$this->scales[$i] = $s < 0.0 ? -$s : $s;
		}
	}

	/** Global multiplier, e.g. from IntradayProfile::multiplier(). */
	public function setMultiplier(float $multiplier): void
	{
		if ($multiplier <= 0.0) {
			throw new InvalidArgument('multiplier must be > 0');
		}
		$this->multiplier = $multiplier;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function transition(float $dt): array
	{
		return $this->inner->transition($dt);
	}

	public function processNoise(float $dt): array
	{
		$Q = $this->inner->processNoise($dt);
		$n = $this->n;
		$s = $this->scales;
		$m = $this->multiplier;
		for ($i = 0; $i < $n; $i++) {
			$si = $s[$i] * $m;
			for ($j = 0; $j < $n; $j++) {
				$Q[$i * $n + $j] *= $si * $s[$j];
			}
		}
		return $Q;
	}

	public function control(float $dt): ?array
	{
		return $this->inner->control($dt);
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		if ($this->inner instanceof SparseMotionModel) {
			$this->inner->advanceInPlace($x, $P, $dt);
			return;
		}
		$n = $this->n;
		$F = $this->inner->transition($dt);
		$u = $this->inner->control($dt);
		$xn = \array_fill(0, $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			$sum = 0.0;
			for ($j = 0; $j < $n; $j++) {
				$sum += $F[$i * $n + $j] * $x[$j];
			}
			$xn[$i] = $sum + ($u === null ? 0.0 : $u[$i]);
		}
		$x = $xn;
		$P = \OpenCCK\Kalman\Domain\Linalg\Flat::sandwich($F, $P, $n);
	}
}
