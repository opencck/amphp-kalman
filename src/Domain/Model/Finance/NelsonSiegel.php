<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Generic\BlockDiagonal;
use OpenCCK\Kalman\Domain\Model\Generic\OrnsteinUhlenbeck;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;

/**
 * §3.8 Dynamic Nelson–Siegel yield curve (Diebold–Li 2006).
 *
 *   x = [L, S, C]ᵀ — level, slope, curvature, each an OU process
 *   y(τᵢ) = L + S·(1 − e^{−λτᵢ})/(λτᵢ) + C·[(1 − e^{−λτᵢ})/(λτᵢ) − e^{−λτᵢ}] + vᵢ
 *   m = number of maturities (8..15) ≫ n = 3  → information form is attractive (Phase 5)
 */
final class NelsonSiegel implements SerializableModel
{
	/**
	 * @param float $lambda decay hyper-parameter (≈ 0.0609 for maturities in months, Diebold–Li)
	 * @param array<int, float> $maturities τ₁..τ_m
	 * @param array<int, float> $theta OU rates for [L, S, C]
	 * @param array<int, float> $mu long-run means for [L, S, C]
	 * @param array<int, float> $sigma OU diffusions for [L, S, C]
	 * @param array<int, float> $variances r_i per maturity (bid–ask²)
	 */
	public function __construct(
		private readonly float $lambda,
		private readonly array $maturities,
		private readonly array $theta,
		private readonly array $mu,
		private readonly array $sigma,
		private readonly array $variances,
	) {
		if ($lambda <= 0.0) {
			throw new InvalidArgument('lambda must be > 0');
		}
		if (\count($maturities) < 1 || \count($maturities) !== \count($variances)) {
			throw new InvalidArgument('maturities and variances must be non-empty and of equal length');
		}
		if (\count($theta) !== 3 || \count($mu) !== 3 || \count($sigma) !== 3) {
			throw new InvalidArgument('theta, mu, sigma must each have 3 entries (L, S, C)');
		}
	}

	/** @return array{0: float, 1: float, 2: float} loadings for maturity τ */
	public function loadings(float $tau): array
	{
		$lt = $this->lambda * $tau;
		$a = (1.0 - \exp(-$lt)) / $lt;
		return [1.0, $a, $a - \exp(-$lt)];
	}

	public function motion(): BlockDiagonal
	{
		return new BlockDiagonal([
			new OrnsteinUhlenbeck($this->theta[0], $this->mu[0], $this->sigma[0]),
			new OrnsteinUhlenbeck($this->theta[1], $this->mu[1], $this->sigma[1]),
			new OrnsteinUhlenbeck($this->theta[2], $this->mu[2], $this->sigma[2]),
		]);
	}

	public function observation(): StaticObservation
	{
		$rows = [];
		$names = [];
		foreach ($this->maturities as $tau) {
			[$l, $s, $c] = $this->loadings($tau);
			$rows[] = [0 => $l, 1 => $s, 2 => $c];
			$names[] = 'y' . $tau;
		}
		return new StaticObservation($rows, $this->variances, $names);
	}

	public function filter(?FilterConfig $config = null): KalmanFilter
	{
		$P0 = \array_fill(0, 9, 0.0);
		for ($i = 0; $i < 3; $i++) {
			$P0[$i * 3 + $i] = $this->theta[$i] > 0.0 ? $this->sigma[$i] ** 2 / (2.0 * $this->theta[$i]) : 1.0;
		}
		return new KalmanFilter($this->motion(), $this->observation(), $this->mu, $P0, $config);
	}

	/** Fitted yield for an arbitrary maturity from the current factors. */
	public function yield(KalmanFilter $filter, float $tau): float
	{
		[$l, $s, $c] = $this->loadings($tau);
		$x = $filter->mean();
		return $l * $x[0] + $s * $x[1] + $c * $x[2];
	}

	public static function type(): string
	{
		return 'nelson-siegel';
	}

	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'lambda' => $this->lambda,
			'maturities' => $this->maturities,
			'theta' => $this->theta,
			'mu' => $this->mu,
			'sigma' => $this->sigma,
			'variances' => $this->variances,
		];
	}

	public static function fromArray(array $config): static
	{
		return new self(
			ConfigReader::float($config, 'lambda'),
			ConfigReader::floatList($config, 'maturities'),
			ConfigReader::floatList($config, 'theta', 3),
			ConfigReader::floatList($config, 'mu', 3),
			ConfigReader::floatList($config, 'sigma', 3),
			ConfigReader::floatList($config, 'variances'),
		);
	}
}
