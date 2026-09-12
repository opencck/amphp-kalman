<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Support;

use OpenCCK\Kalman\Domain\Linalg\Cholesky;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded random numbers for deterministic tests (PHP 8.2 compatible:
 * no Randomizer::getFloat, which arrived in 8.3).
 */
final class Rng
{
	private Randomizer $r;
	private ?float $spare = null;

	public function __construct(int $seed)
	{
		$this->r = new Randomizer(new Mt19937($seed));
	}

	/** Uniform in [0, 1). */
	public function uniform(): float
	{
		return $this->r->getInt(0, (1 << 53) - 1) / (float) (1 << 53);
	}

	public function uniformBetween(float $lo, float $hi): float
	{
		return $lo + ($hi - $lo) * $this->uniform();
	}

	public function int(int $lo, int $hi): int
	{
		return $this->r->getInt($lo, $hi);
	}

	/** Standard normal via Marsaglia polar method. */
	public function normal(): float
	{
		if ($this->spare !== null) {
			$s = $this->spare;
			$this->spare = null;
			return $s;
		}
		do {
			$u = 2.0 * $this->uniform() - 1.0;
			$v = 2.0 * $this->uniform() - 1.0;
			$q = $u * $u + $v * $v;
		} while ($q >= 1.0 || $q === 0.0);
		$f = \sqrt(-2.0 * \log($q) / $q);
		$this->spare = $v * $f;
		return $u * $f;
	}

	/**
	 * Zero-mean multivariate normal with covariance Σ (flat n×n, PSD).
	 *
	 * @param array<int, float> $sigma
	 * @return array<int, float>
	 */
	public function multivariateNormal(array $sigma, int $n): array
	{
		$L = Cholesky::decomposeSemidefinite($sigma, $n);
		$z = [];
		for ($i = 0; $i < $n; $i++) {
			$z[] = $this->normal();
		}
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$s = 0.0;
			for ($k = 0; $k <= $i; $k++) {
				$s += $L[$i * $n + $k] * $z[$k];
			}
			$out[] = $s;
		}
		return $out;
	}

	/**
	 * Random symmetric positive definite matrix A·Aᵀ + εI (flat).
	 *
	 * @return array<int, float>
	 */
	public function spdMatrix(int $n, float $scale = 1.0, float $epsilon = 0.1): array
	{
		/** @var array<int, float> $A */
		$A = [];
		for ($i = 0; $i < $n * $n; $i++) {
			$A[] = $this->normal() * $scale;
		}
		$S = \array_fill(0, $n * $n, 0.0);
		for ($i = 0; $i < $n; $i++) {
			for ($j = $i; $j < $n; $j++) {
				$s = 0.0;
				for ($k = 0; $k < $n; $k++) {
					$s += $A[$i * $n + $k] * $A[$j * $n + $k];
				}
				if ($i === $j) {
					$s += $epsilon;
				}
				$S[$i * $n + $j] = $s;
				$S[$j * $n + $i] = $s;
			}
		}
		return $S;
	}

	/**
	 * Random dense matrix rows×cols with entries ~ N(0, scale²).
	 *
	 * @return array<int, float>
	 */
	public function matrix(int $rows, int $cols, float $scale = 1.0): array
	{
		$M = [];
		for ($i = 0; $i < $rows * $cols; $i++) {
			$M[] = $this->normal() * $scale;
		}
		return $M;
	}

	/** @return array<int, float> */
	public function vector(int $n, float $scale = 1.0): array
	{
		$v = [];
		for ($i = 0; $i < $n; $i++) {
			$v[] = $this->normal() * $scale;
		}
		return $v;
	}
}
