<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Discretization\VanLoan;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Arbitrary continuous-time linear model dx = A x dt + G dβ (E[dβdβᵀ] = Qc dt),
 * discretised exactly with Van Loan for every new dt. Results are memoised
 * per quantised dt (µs) because the matrix exponential is O(n³)·(6 + log₂‖M‖).
 */
final class DenseLinear implements MotionModel, StationaryModel, SerializableModel
{
	/** @var array<int, array{F: array<int, float>, Q: array<int, float>, u?: array<int, float>}> */
	private array $cache = [];

	/**
	 * @param array<int, float> $A n×n
	 * @param array<int, float> $G n×q
	 * @param array<int, float> $Qc q×q
	 * @param array<int, float>|null $drift constant drift b (n): dx = (A x + b) dt + G dβ
	 */
	public function __construct(
		private readonly array $A,
		private readonly array $G,
		private readonly array $Qc,
		private readonly int $n,
		private readonly int $q,
		private readonly ?array $drift = null,
		private readonly int $cacheSize = 64,
	) {
		if (\count($A) !== $n * $n) {
			throw DimensionMismatch::forMatrix('A', $n * $n, \count($A));
		}
		if (\count($G) !== $n * $q) {
			throw DimensionMismatch::forMatrix('G', $n * $q, \count($G));
		}
		if (\count($Qc) !== $q * $q) {
			throw DimensionMismatch::forMatrix('Qc', $q * $q, \count($Qc));
		}
		if ($drift !== null && \count($drift) !== $n) {
			throw DimensionMismatch::forVector('drift', $n, \count($drift));
		}
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function transition(float $dt): array
	{
		return $this->discretized($dt)['F'];
	}

	public function processNoise(float $dt): array
	{
		return $this->discretized($dt)['Q'];
	}

	/**
	 * Control from a constant drift: u = ∫₀^dt e^{A s} b ds ≈ (F − I)A⁻¹ b;
	 * evaluated by augmenting the state (exact, no inverse) — see augmentedDrift().
	 */
	public function control(float $dt): ?array
	{
		if ($this->drift === null) {
			return null;
		}
		return $this->discretized($dt)['u'] ?? null;
	}

	/** @return array{F: array<int, float>, Q: array<int, float>, u?: array<int, float>} */
	private function discretized(float $dt): array
	{
		$key = (int) \round($dt * 1e6);
		if (isset($this->cache[$key])) {
			return $this->cache[$key];
		}
		$result = VanLoan::discretize($this->A, $this->G, $this->Qc, $this->n, $this->q, $dt);
		if ($this->drift !== null) {
			$result['u'] = $this->augmentedDrift($dt);
		}
		if (\count($this->cache) >= $this->cacheSize) {
			$oldest = \array_key_first($this->cache);
			if ($oldest !== null) {
				unset($this->cache[$oldest]);
			}
		}
		$this->cache[$key] = $result;
		return $result;
	}

	/**
	 * exp([A b; 0 0]·dt) = [F u; 0 1] gives u exactly without inverting A.
	 *
	 * @return array<int, float>
	 */
	private function augmentedDrift(float $dt): array
	{
		$n = $this->n;
		$N = $n + 1;
		$M = \array_fill(0, $N * $N, 0.0);
		for ($i = 0; $i < $n; $i++) {
			for ($j = 0; $j < $n; $j++) {
				$M[$i * $N + $j] = $this->A[$i * $n + $j] * $dt;
			}
			$M[$i * $N + $n] = ($this->drift[$i] ?? 0.0) * $dt;
		}
		$E = \OpenCCK\Kalman\Domain\Linalg\MatrixExponential::compute($M, $N);
		$u = [];
		for ($i = 0; $i < $n; $i++) {
			$u[] = $E[$i * $N + $n];
		}
		return $u;
	}

	public static function type(): string
	{
		return 'dense-linear';
	}

	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'n' => $this->n,
			'q' => $this->q,
			'A' => $this->A,
			'G' => $this->G,
			'Qc' => $this->Qc,
			'drift' => $this->drift,
		];
	}

	public static function fromArray(array $config): static
	{
		$n = ConfigReader::int($config, 'n');
		$q = ConfigReader::int($config, 'q');
		return new self(
			ConfigReader::floatList($config, 'A', $n * $n),
			ConfigReader::floatList($config, 'G', $n * $q),
			ConfigReader::floatList($config, 'Qc', $q * $q),
			$n,
			$q,
			ConfigReader::optionalFloatList($config, 'drift'),
		);
	}
}
