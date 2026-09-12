<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Observation;

use OpenCCK\Kalman\Domain\Contract\CorrelatedObservationModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * Adapter: full R → diagonal R' = I via Cholesky whitening (§2.5.2).
 *
 *   R = L·Lᵀ,   H' = L⁻¹·H,   z' = L⁻¹·z
 *
 * Because whitening mixes channels, every measurement MUST carry all m
 * channels; transform() enforces this. Build once for a stationary R.
 *
 * Likelihood: the filter accumulates the log-density of the WHITENED
 * measurement z'. The density of the original z differs by the Jacobian
 * of z' = L⁻¹z:  ln p(z) = ln p(z') − ½·ln|R|. Add logLikelihoodCorrection()
 * per full measurement to compare with a block filter on the original data.
 */
final class DecorrelatedObservation implements ObservationModel
{
	private int $m;
	private int $n;

	/** @var array<int, float> m×m lower Cholesky factor of R */
	private array $L;

	/** @var array<int, array<int, float>> whitened sparse rows */
	private array $rows;

	/** @var array<int, string> */
	private array $names;

	public function __construct(CorrelatedObservationModel $model)
	{
		$this->m = $model->channelCount();
		$this->n = $model->stateSize();
		$m = $this->m;
		$n = $this->n;
		$R = $model->measurementNoise();
		if (\count($R) !== $m * $m) {
			throw new InvalidArgument('measurementNoise() must be m×m');
		}
		$H = $model->observationMatrix();
		if (\count($H) !== $m * $n) {
			throw new InvalidArgument('observationMatrix() must be m×n');
		}
		$this->L = Cholesky::decompose($R, $m);
		// H' = L⁻¹ H : solve L·H' = H column by column
		$Hw = \array_fill(0, $m * $n, 0.0);
		for ($j = 0; $j < $n; $j++) {
			$col = [];
			for ($i = 0; $i < $m; $i++) {
				$col[] = $H[$i * $n + $j];
			}
			$w = Cholesky::solveForward($this->L, $col, $m);
			for ($i = 0; $i < $m; $i++) {
				$Hw[$i * $n + $j] = $w[$i];
			}
		}
		$this->rows = [];
		$this->names = [];
		for ($i = 0; $i < $m; $i++) {
			$this->rows[] = Flat::sparseRow(\array_slice($Hw, $i * $n, $n));
			$this->names[] = 'white' . $i;
		}
	}

	public function channelCount(): int
	{
		return $this->m;
	}

	public function channelRow(int $channel): array
	{
		return $this->rows[$channel];
	}

	public function channelVariance(int $channel): float
	{
		return 1.0;
	}

	public function channelName(int $channel): string
	{
		return $this->names[$channel];
	}

	/**
	 * Whitens a full measurement: z' = L⁻¹ z. All channels are required.
	 */
	public function transform(Measurement $measurement): Measurement
	{
		$m = $this->m;
		if ($measurement->channelCount() !== $m) {
			throw new InvalidArgument('Decorrelated observation requires all channels in every measurement');
		}
		$z = [];
		for ($i = 0; $i < $m; $i++) {
			$z[] = $measurement->value($i);
		}
		$w = Cholesky::solveForward($this->L, $z, $m);
		$values = [];
		for ($i = 0; $i < $m; $i++) {
			$values[$i] = $w[$i];
		}
		return Measurement::at($measurement->timestampNs, $values, $measurement->receivedNs);
	}

	/**
	 * @param array<int, float> $z m values
	 * @return array<int, float> whitened
	 */
	public function transformRaw(array $z): array
	{
		return Cholesky::solveForward($this->L, $z, $this->m);
	}

	/** −½·ln|R|: add once per full measurement to recover the original-data log-likelihood. */
	public function logLikelihoodCorrection(): float
	{
		return -0.5 * Cholesky::logDeterminant($this->L, $this->m);
	}
}
