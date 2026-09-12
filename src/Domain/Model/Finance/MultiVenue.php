<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;

/**
 * §3.4 Multi-venue fair value: one asset on V venues with systematic offsets.
 *
 *   x = [p, v, δ₂..δ_V]ᵀ,  n = V + 1      (offsets relative to venue 1)
 *   F : (p, v) CV;  δⱼ ← e^{−dt/τ_δ}·δⱼ   (OU to zero)
 *   Q : CV block σ_a²;  δ block σ_δ²·(1 − e^{−2dt/τ_δ})·τ_δ/2 … i.e. OU with stationary var σ_δ²
 *   z = [q₁..q_V],  H row 1 = [1 0 0…],  row j = [1 0 … 1(δⱼ) …]
 *   R = diag(r₁..r_V)  (spread² per venue, plus latency term v̂²·τ_latency² if desired)
 *
 * Channels arrive one at a time → sequential form; out-of-sequence ticks are
 * normal → pair with Infrastructure\Async\ReorderBuffer.
 */
final class MultiVenue implements SparseMotionModel, StationaryModel, SerializableModel
{
	private int $venues;
	private int $n;
	private float $qA;
	private float $sigmaDelta2;

	/**
	 * @param float $sigmaA velocity noise intensity
	 * @param float $tauDelta offset memory (seconds)
	 * @param float $sigmaDelta stationary std of a venue offset
	 * @param array<int, float> $variances r_j for venues 1..V
	 */
	public function __construct(
		private readonly float $sigmaA,
		private readonly float $tauDelta,
		private readonly float $sigmaDelta,
		private readonly array $variances,
	) {
		$this->venues = \count($variances);
		if ($this->venues < 2) {
			throw new InvalidArgument('At least two venues are required');
		}
		if ($sigmaA <= 0.0 || $tauDelta <= 0.0 || $sigmaDelta <= 0.0) {
			throw new InvalidArgument('sigmaA, tauDelta, sigmaDelta must be > 0');
		}
		foreach ($variances as $r) {
			if ($r <= 0.0) {
				throw new InvalidArgument('venue variances must be > 0');
			}
		}
		$this->n = $this->venues + 1;
		$this->qA = $sigmaA * $sigmaA;
		$this->sigmaDelta2 = $sigmaDelta * $sigmaDelta;
	}

	public function venues(): int
	{
		return $this->venues;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	/** State index of the offset of venue j (j = 1..V−1, venue 0 is the reference). */
	public function offsetIndex(int $venue): int
	{
		if ($venue < 1 || $venue >= $this->venues) {
			throw new InvalidArgument('venue must be in 1..V-1');
		}
		return 1 + $venue;
	}

	private function decay(float $dt): float
	{
		return \exp(-$dt / $this->tauDelta);
	}

	public function transition(float $dt): array
	{
		$n = $this->n;
		$F = Flat::identity($n);
		$F[1] = $dt;
		$d = $this->decay($dt);
		for ($i = 2; $i < $n; $i++) {
			$F[$i * $n + $i] = $d;
		}
		return $F;
	}

	public function processNoise(float $dt): array
	{
		$n = $this->n;
		$Q = \array_fill(0, $n * $n, 0.0);
		$dt2 = $dt * $dt;
		$Q[0] = $this->qA * $dt2 * $dt / 3.0;
		$Q[1] = 0.5 * $this->qA * $dt2;
		$Q[$n] = $Q[1];
		$Q[$n + 1] = $this->qA * $dt;
		$d = $this->decay($dt);
		$qd = $this->sigmaDelta2 * (1.0 - $d * $d);
		for ($i = 2; $i < $n; $i++) {
			$Q[$i * $n + $i] = $qd;
		}
		return $Q;
	}

	public function control(float $dt): ?array
	{
		return null;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$n = $this->n;
		$d = $this->decay($dt);
		$x[0] += $dt * $x[1];
		for ($i = 2; $i < $n; $i++) {
			$x[$i] *= $d;
		}
		// row ops
		for ($j = 0; $j < $n; $j++) {
			$P[$j] += $dt * $P[$n + $j];
		}
		for ($i = 2; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = 0; $j < $n; $j++) {
				$P[$in + $j] *= $d;
			}
		}
		// column ops
		for ($r = 0; $r < $n; $r++) {
			$rn = $r * $n;
			$P[$rn] += $dt * $P[$rn + 1];
			for ($i = 2; $i < $n; $i++) {
				$P[$rn + $i] *= $d;
			}
		}
		// mirror upper → lower
		for ($i = 0; $i < $n; $i++) {
			$in = $i * $n;
			for ($j = $i + 1; $j < $n; $j++) {
				$P[$j * $n + $i] = $P[$in + $j];
			}
		}
	}

	public function observation(): StaticObservation
	{
		$rows = [[0 => 1.0]];
		$names = ['venue0'];
		for ($j = 1; $j < $this->venues; $j++) {
			$rows[] = [0 => 1.0, 1 + $j => 1.0];
			$names[] = 'venue' . $j;
		}
		return new StaticObservation($rows, $this->variances, $names);
	}

	public function filter(float $firstPrice, ?FilterConfig $config = null, float $velocityPriorStd = 0.1): KalmanFilter
	{
		$n = $this->n;
		$x0 = \array_fill(0, $n, 0.0);
		$x0[0] = $firstPrice;
		$diag = [$this->variances[0] * 4.0, $velocityPriorStd * $velocityPriorStd];
		for ($i = 2; $i < $n; $i++) {
			$diag[] = $this->sigmaDelta2;
		}
		return new KalmanFilter($this, $this->observation(), $x0, Flat::diagonal($diag), $config);
	}

	public static function type(): string
	{
		return 'multi-venue';
	}

	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'sigmaA' => $this->sigmaA,
			'tauDelta' => $this->tauDelta,
			'sigmaDelta' => $this->sigmaDelta,
			'variances' => $this->variances,
		];
	}

	public static function fromArray(array $config): static
	{
		return new self(
			ConfigReader::float($config, 'sigmaA'),
			ConfigReader::float($config, 'tauDelta'),
			ConfigReader::float($config, 'sigmaDelta'),
			ConfigReader::floatList($config, 'variances'),
		);
	}
}
