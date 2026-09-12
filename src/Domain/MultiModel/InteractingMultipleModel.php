<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\MultiModel;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Entity\UpdateResult;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Exception\OutOfSequenceMeasurement;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * §2.14 interacting multiple model estimator (Blom & Bar-Shalom 1988):
 * M filters with different hypotheses (Q_calm, Q_volatile, …) and a Markov
 * regime transition matrix Π. Per step:
 *
 *   1. mixing:      μ_{i|j} = Π_ij μ_i / c_j,  x̂⁰_j = Σ_i μ_{i|j} x̂_i,
 *                   P⁰_j = Σ_i μ_{i|j} [P_i + (x̂_i − x̂⁰_j)(x̂_i − x̂⁰_j)ᵀ]
 *   2. filtering:   each filter predicts + corrects from its mixed initial condition
 *   3. probabilities: μ_j ∝ Λ_j c_j,  Λ_j = N(ỹ_j; 0, S_j) from the step log-likelihood
 *   4. output:      x̂ = Σ μ_j x̂_j,  P = Σ μ_j [P_j + (x̂_j − x̂)(x̂_j − x̂)ᵀ]
 *
 * regimeProbabilities() is a signal in its own right (calm vs volatile).
 */
final class InteractingMultipleModel
{
	/** @var non-empty-list<KalmanFilter> */
	private array $filters;

	/** @var array<int, float> */
	private array $mu;

	/** @var array<int, float> M×M */
	private array $pi;
	private int $M;
	private int $n;
	private ?int $lastTimestampNs = null;
	private int $steps = 0;

	/**
	 * @param array<int, KalmanFilter> $filters same state size, same channels; their x̂/P are the initial conditions
	 * @param array<int, float> $transition M×M row-stochastic Π (row i: from regime i)
	 * @param array<int, float>|null $initialProbabilities defaults to uniform
	 */
	public function __construct(array $filters, array $transition, ?array $initialProbabilities = null, private readonly float $minProbability = 1e-9)
	{
		$list = \array_values($filters);
		if (\count($list) < 2) {
			throw new InvalidArgument('IMM needs at least two filters');
		}
		$this->filters = $list;
		$this->M = \count($list);
		$this->n = $this->filters[0]->stateSize();
		foreach ($this->filters as $f) {
			if ($f->stateSize() !== $this->n) {
				throw new InvalidArgument('All filters must share the state size');
			}
		}
		if (\count($transition) !== $this->M * $this->M) {
			throw new InvalidArgument('transition must be M×M');
		}
		for ($i = 0; $i < $this->M; $i++) {
			$row = 0.0;
			for ($j = 0; $j < $this->M; $j++) {
				if ($transition[$i * $this->M + $j] < 0.0) {
					throw new InvalidArgument('transition probabilities must be >= 0');
				}
				$row += $transition[$i * $this->M + $j];
			}
			if (\abs($row - 1.0) > 1e-9) {
				throw new InvalidArgument("transition row $i must sum to 1");
			}
		}
		$this->pi = \array_values($transition);
		$this->mu = $initialProbabilities === null ? \array_fill(0, $this->M, 1.0 / $this->M) : self::normalise(\array_values($initialProbabilities));
	}

	/**
	 * @return array<int, UpdateResult> per-filter results
	 */
	public function step(Measurement $measurement): array
	{
		$M = $this->M;
		$n = $this->n;
		$ts = $measurement->timestampNs;
		if ($this->lastTimestampNs !== null && $ts < $this->lastTimestampNs) {
			throw new OutOfSequenceMeasurement($ts, $this->lastTimestampNs);
		}

		// 1. mixing probabilities c_j = Σ_i Π_ij μ_i,  μ_{i|j} = Π_ij μ_i / c_j
		$c = \array_fill(0, $M, 0.0);
		for ($j = 0; $j < $M; $j++) {
			for ($i = 0; $i < $M; $i++) {
				$c[$j] += $this->pi[$i * $M + $j] * $this->mu[$i];
			}
		}
		$means = [];
		$covs = [];
		foreach ($this->filters as $f) {
			$means[] = $f->mean();
			$covs[] = $f->covariance();
		}
		if ($this->steps > 0) {
			for ($j = 0; $j < $M; $j++) {
				$x0 = \array_fill(0, $n, 0.0);
				$w = [];
				for ($i = 0; $i < $M; $i++) {
					$w[$i] = $c[$j] > 0.0 ? $this->pi[$i * $M + $j] * $this->mu[$i] / $c[$j] : 0.0;
					for ($r = 0; $r < $n; $r++) {
						$x0[$r] += $w[$i] * $means[$i][$r];
					}
				}
				$P0 = \array_fill(0, $n * $n, 0.0);
				for ($i = 0; $i < $M; $i++) {
					if ($w[$i] === 0.0) {
						continue;
					}
					$d = Flat::subtract($means[$i], $x0);
					$term = Flat::add($covs[$i], Flat::outer($d, $d));
					for ($e = 0; $e < $n * $n; $e++) {
						$P0[$e] += $w[$i] * $term[$e];
					}
				}
				$this->filters[$j]->reset($x0, Flat::symmetrize($P0, $n), $this->lastTimestampNs);
			}
		}

		// 2. filtering + 3. likelihoods
		$results = [];
		$logLik = [];
		foreach ($this->filters as $f) {
			$result = $f->step($measurement);
			$results[] = $result;
			$logLik[] = $result->isBlind() ? 0.0 : $result->logLikelihood;
		}
		$maxLog = -\INF;
		foreach ($logLik as $l) {
			if ($l > $maxLog) {
				$maxLog = $l;
			}
		}
		$mu = [];
		$total = 0.0;
		for ($j = 0; $j < $M; $j++) {
			$mu[$j] = \exp($logLik[$j] - $maxLog) * $c[$j];
			$total += $mu[$j];
		}
		for ($j = 0; $j < $M; $j++) {
			$mu[$j] = \max($this->minProbability, $mu[$j] / $total);
		}
		$this->mu = self::normalise($mu);
		$this->lastTimestampNs = $ts;
		$this->steps++;
		return $results;
	}

	/** @return array<int, float> regime probabilities μ_j */
	public function regimeProbabilities(): array
	{
		return $this->mu;
	}

	public function mostLikelyRegime(): int
	{
		$best = 0;
		foreach ($this->mu as $j => $p) {
			if ($p > $this->mu[$best]) {
				$best = $j;
			}
		}
		return $best;
	}

	/**
	 * Mixture mean Σ μ_j x̂_j.
	 *
	 * @return array<int, float>
	 */
	public function mean(): array
	{
		$x = \array_fill(0, $this->n, 0.0);
		foreach ($this->filters as $j => $f) {
			$m = $f->mean();
			for ($r = 0; $r < $this->n; $r++) {
				$x[$r] += $this->mu[$j] * $m[$r];
			}
		}
		return $x;
	}

	/**
	 * Mixture covariance Σ μ_j [P_j + (x̂_j − x̂)(x̂_j − x̂)ᵀ].
	 *
	 * @return array<int, float>
	 */
	public function covariance(): array
	{
		$n = $this->n;
		$x = $this->mean();
		$P = \array_fill(0, $n * $n, 0.0);
		foreach ($this->filters as $j => $f) {
			$d = Flat::subtract($f->mean(), $x);
			$term = Flat::add($f->covariance(), Flat::outer($d, $d));
			for ($e = 0; $e < $n * $n; $e++) {
				$P[$e] += $this->mu[$j] * $term[$e];
			}
		}
		return Flat::symmetrize($P, $n);
	}

	public function snapshot(): StateSnapshot
	{
		return new StateSnapshot($this->mean(), $this->covariance(), $this->n, $this->lastTimestampNs, 0.0, $this->steps);
	}

	/** @return array<int, KalmanFilter> */
	public function filters(): array
	{
		return $this->filters;
	}

	public function steps(): int
	{
		return $this->steps;
	}

	/**
	 * @param array<int, float> $p
	 * @return array<int, float>
	 */
	private static function normalise(array $p): array
	{
		$s = \array_sum($p);
		if ($s <= 0.0) {
			throw new InvalidArgument('Probabilities must sum to a positive number');
		}
		return \array_map(static fn (float $v): float => $v / $s, $p);
	}
}
