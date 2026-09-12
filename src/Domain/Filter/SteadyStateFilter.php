<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Filter;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Diagnostics\SteadyStateSolver;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Exception\UnsupportedOperation;

/**
 * §2.10 steady-state filter: constant gain K from the DARE, no covariance.
 *
 *   x̂ ← F x̂ + u,   x̂ ← x̂ + K(z − H x̂)        cost O(n·m)
 *
 * Valid only for a fixed sampling interval (bars) and for measurements that
 * always carry every channel — a missing channel throws UnsupportedOperation
 * because K was computed for the full channel set.
 */
final class SteadyStateFilter
{
	private int $n;
	private int $m;

	/** @var array<int, float> */
	private array $x;

	/** @var array<int, float> */
	private array $xTmp;

	/** @var array<int, float> */
	private array $innovation;

	private int $steps = 0;

	/**
	 * @param array<int, float> $F n×n
	 * @param array<int, float>|null $u n
	 * @param array<int, float> $H m×n dense
	 * @param array<int, float> $K n×m
	 * @param array<int, float> $x0 n
	 * @param array<int, float> $S m×m steady-state innovation covariance (for NIS)
	 */
	public function __construct(
		private readonly array $F,
		private readonly ?array $u,
		private readonly array $H,
		private readonly array $K,
		array $x0,
		int $n,
		int $m,
		private readonly array $S = [],
	) {
		if (\count($F) !== $n * $n) {
			throw DimensionMismatch::forMatrix('F', $n * $n, \count($F));
		}
		if (\count($H) !== $m * $n) {
			throw DimensionMismatch::forMatrix('H', $m * $n, \count($H));
		}
		if (\count($K) !== $n * $m) {
			throw DimensionMismatch::forMatrix('K', $n * $m, \count($K));
		}
		if (\count($x0) !== $n) {
			throw DimensionMismatch::forVector('x0', $n, \count($x0));
		}
		if ($u !== null && \count($u) !== $n) {
			throw DimensionMismatch::forVector('u', $n, \count($u));
		}
		$this->n = $n;
		$this->m = $m;
		$this->x = \array_values($x0);
		$this->xTmp = \array_fill(0, $n, 0.0);
		$this->innovation = \array_fill(0, $m, 0.0);
	}

	/**
	 * Builds the steady-state filter for a model pair at a fixed dt.
	 *
	 * @param array<int, float> $x0
	 */
	public static function fromModels(MotionModel $motion, ObservationModel $observation, float $dt, array $x0): self
	{
		$n = $motion->stateSize();
		$m = $observation->channelCount();
		$dare = SteadyStateSolver::forModels($motion, $observation, $dt);
		$Rdiag = [];
		for ($c = 0; $c < $m; $c++) {
			$Rdiag[] = $observation->channelVariance($c);
		}
		$PHt = \OpenCCK\Kalman\Domain\Linalg\Flat::multiplyTransposed($dare['P'], $dare['H'], $n, $n, $m);
		$S = \OpenCCK\Kalman\Domain\Linalg\Flat::add(\OpenCCK\Kalman\Domain\Linalg\Flat::multiply($dare['H'], $PHt, $m, $n, $m), \OpenCCK\Kalman\Domain\Linalg\Flat::diagonal($Rdiag));
		return new self($dare['F'], $motion->control($dt), $dare['H'], $dare['K'], $x0, $n, $m, $S);
	}

	/**
	 * One fixed-interval step with all channels.
	 *
	 * @param array<int, float> $z channel => value; all m channels required
	 */
	public function step(array $z): void
	{
		$n = $this->n;
		$m = $this->m;
		if (\count($z) !== $m) {
			throw new UnsupportedOperation('Steady-state filter requires every channel on every step');
		}
		$x = &$this->x;
		$t = &$this->xTmp;
		$F = $this->F;
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			$sum = 0.0;
			for ($j = 0; $j < $n; $j++) {
				$sum += $F[$in + $j] * $x[$j];
			}
			$t[$i] = $sum;
		}
		if ($this->u !== null) {
			for ($i = 0; $i < $n; $i++) {
				$t[$i] += $this->u[$i];
			}
		}
		$H = $this->H;
		$y = &$this->innovation;
		for ($c = 0; $c < $m; $c++) {
			if (!isset($z[$c])) {
				throw new UnsupportedOperation(\sprintf('Channel %d missing: steady-state gain needs all channels', $c));
			}
			$cn = $c * $n;
			$pred = 0.0;
			for ($j = 0; $j < $n; $j++) {
				$pred += $H[$cn + $j] * $t[$j];
			}
			$y[$c] = $z[$c] - $pred;
		}
		$K = $this->K;
		for ($i = 0; $i < $n; $i++) {
			$im = $i * $m;
			$sum = $t[$i];
			for ($c = 0; $c < $m; $c++) {
				$sum += $K[$im + $c] * $y[$c];
			}
			$x[$i] = $sum;
		}
		$this->steps++;
	}

	/** @return array<int, float> */
	public function mean(): array
	{
		return $this->x;
	}

	public function meanAt(int $i): float
	{
		if ($i < 0 || $i >= $this->n) {
			throw new InvalidArgument('index out of range');
		}
		return $this->x[$i];
	}

	/** @return array<int, float> innovations of the last step */
	public function lastInnovations(): array
	{
		return $this->innovation;
	}

	/** @return array<int, float> constant gain n×m */
	public function gain(): array
	{
		return $this->K;
	}

	/** @return array<int, float> steady-state innovation covariance m×m (empty if unknown) */
	public function innovationCovariance(): array
	{
		return $this->S;
	}

	public function steps(): int
	{
		return $this->steps;
	}

	/** @param array<int, float> $x0 */
	public function reset(array $x0): void
	{
		if (\count($x0) !== $this->n) {
			throw DimensionMismatch::forVector('x0', $this->n, \count($x0));
		}
		$this->x = \array_values($x0);
		$this->steps = 0;
	}
}
