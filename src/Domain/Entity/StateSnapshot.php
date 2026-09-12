<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Immutable copy of the filter state at one instant: x̂, P, the exchange
 * timestamp of the last processed measurement and accumulated statistics.
 * Serialisable (toArray / fromArray) for persistence and IPC.
 */
final readonly class StateSnapshot
{
	/**
	 * @param array<int, float> $mean n
	 * @param array<int, float> $covariance n² row-major
	 */
	public function __construct(
		public array $mean,
		public array $covariance,
		public int $size,
		public ?int $timestampNs = null,
		public float $logLikelihood = 0.0,
		public int $steps = 0,
	) {
		if (\count($mean) !== $size) {
			throw DimensionMismatch::forVector('mean', $size, \count($mean));
		}
		if (\count($covariance) !== $size * $size) {
			throw DimensionMismatch::forMatrix('covariance', $size * $size, \count($covariance));
		}
	}

	public function mean(int $i): float
	{
		$this->checkIndex($i);
		return $this->mean[$i];
	}

	public function variance(int $i): float
	{
		$this->checkIndex($i);
		return $this->covariance[$i * $this->size + $i];
	}

	public function stddev(int $i): float
	{
		return \sqrt($this->variance($i));
	}

	public function covariance(int $i, int $j): float
	{
		$this->checkIndex($i);
		$this->checkIndex($j);
		return $this->covariance[$i * $this->size + $j];
	}

	public function correlation(int $i, int $j): float
	{
		return $this->covariance($i, $j) / \sqrt($this->variance($i) * $this->variance($j));
	}

	/** @return array<int, float> */
	public function variances(): array
	{
		$d = [];
		for ($i = 0; $i < $this->size; $i++) {
			$d[] = $this->covariance[$i * $this->size + $i];
		}
		return $d;
	}

	/**
	 * Variance of a linear combination wᵀx (e.g. NAV of a basket): wᵀ·P·w.
	 *
	 * @param array<int, float> $weights state index => weight (sparse)
	 */
	public function linearVariance(array $weights): float
	{
		$sum = 0.0;
		$n = $this->size;
		foreach ($weights as $i => $wi) {
			$this->checkIndex($i);
			foreach ($weights as $j => $wj) {
				$sum += $wi * $wj * $this->covariance[$i * $n + $j];
			}
		}
		return $sum;
	}

	/**
	 * @return array{n: int, x: array<int, float>, P: array<int, float>, ts: int|null, ll: float, steps: int}
	 */
	public function toArray(): array
	{
		return [
			'n' => $this->size,
			'x' => $this->mean,
			'P' => $this->covariance,
			'ts' => $this->timestampNs,
			'll' => $this->logLikelihood,
			'steps' => $this->steps,
		];
	}

	/**
	 * @param array{n: int, x: array<int, float|int>, P: array<int, float|int>, ts?: int|null, ll?: float, steps?: int} $data
	 */
	public static function fromArray(array $data): self
	{
		if (!isset($data['n'], $data['x'], $data['P'])) {
			throw new InvalidArgument('Snapshot array requires keys n, x, P');
		}
		return new self(
			\array_map('floatval', $data['x']),
			\array_map('floatval', $data['P']),
			$data['n'],
			$data['ts'] ?? null,
			(float) ($data['ll'] ?? 0.0),
			(int) ($data['steps'] ?? 0),
		);
	}

	/**
	 * Compact form sent by worker processes: mean + diagonal only.
	 *
	 * @return array{n: int, x: array<int, float>, diag: array<int, float>, ts: int|null, steps: int}
	 */
	public function toCompactArray(): array
	{
		return [
			'n' => $this->size,
			'x' => $this->mean,
			'diag' => $this->variances(),
			'ts' => $this->timestampNs,
			'steps' => $this->steps,
		];
	}

	private function checkIndex(int $i): void
	{
		if ($i < 0 || $i >= $this->size) {
			throw new InvalidArgument(\sprintf('State index %d out of range [0, %d)', $i, $this->size));
		}
	}
}
