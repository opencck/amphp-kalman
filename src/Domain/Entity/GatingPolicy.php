<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

use OpenCCK\Kalman\Domain\Diagnostics\ChiSquare;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Outlier handling for each scalar channel (§2.8).
 *
 * Channels are corrected one at a time, so every gate is a test on the
 * scalar normalised innovation d² = ỹ²/s ~ χ²(1):
 *
 *  - None:            always accept, weight 1.
 *  - Sigma(k):        reject when d² > k².
 *  - ChiSquare(α):    reject when d² > χ²₁(1−α)   (α = 0.01 → 6.63, α = 0.001 → 10.83).
 *  - Huber(c):        never reject; when |u| = √d² > c, inflate the measurement
 *                     variance r_eff = r·|u|/c and report weight c/|u|.
 *
 * A rejected channel changes neither x̂ nor P and is excluded from the log-likelihood.
 */
final readonly class GatingPolicy
{
	public const MODE_NONE = 0;
	public const MODE_THRESHOLD = 1;
	public const MODE_HUBER = 2;

	private function __construct(
		public string $name,
		public int $mode,
		/** Threshold on d² for MODE_THRESHOLD; c for MODE_HUBER; 0 otherwise. */
		public float $parameter,
	) {
	}

	public static function none(): self
	{
		return new self('none', self::MODE_NONE, 0.0);
	}

	/** Reject when |ỹ| > k·√s. */
	public static function sigma(float $k): self
	{
		if ($k <= 0.0) {
			throw new InvalidArgument('Sigma gate requires k > 0');
		}
		return new self(\sprintf('sigma(%g)', $k), self::MODE_THRESHOLD, $k * $k);
	}

	/** Reject when ỹ²/s > χ²₁(1 − α). */
	public static function chiSquare(float $alpha): self
	{
		if ($alpha <= 0.0 || $alpha >= 1.0) {
			throw new InvalidArgument('Chi-square gate requires 0 < alpha < 1');
		}
		return new self(\sprintf('chi2(%g)', $alpha), self::MODE_THRESHOLD, ChiSquare::quantile(1, 1.0 - $alpha));
	}

	/** Robust down-weighting with Huber constant c (≈ 1.345 for 95 % efficiency). */
	public static function huber(float $c = 1.345): self
	{
		if ($c <= 0.0) {
			throw new InvalidArgument('Huber gate requires c > 0');
		}
		return new self(\sprintf('huber(%g)', $c), self::MODE_HUBER, $c);
	}

	/** d² threshold for threshold-type gates. */
	public function threshold(): float
	{
		return $this->mode === self::MODE_THRESHOLD ? $this->parameter : \INF;
	}

	/**
	 * Weight for a scalar innovation: 1 accepted, 0 rejected, (0, 1) Huber.
	 * Reference implementation — the filter inlines the same logic.
	 */
	public function weight(float $innovation, float $s): float
	{
		if ($this->mode === self::MODE_NONE) {
			return 1.0;
		}
		$d2 = $innovation * $innovation / $s;
		if ($this->mode === self::MODE_THRESHOLD) {
			return $d2 > $this->parameter ? 0.0 : 1.0;
		}
		$u = \sqrt($d2);
		return $u > $this->parameter ? $this->parameter / $u : 1.0;
	}

	/** @return array{name: string, mode: int, parameter: float} */
	public function toArray(): array
	{
		return ['name' => $this->name, 'mode' => $this->mode, 'parameter' => $this->parameter];
	}

	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): self
	{
		$mode = ConfigReader::int($data, 'mode');
		if ($mode < self::MODE_NONE || $mode > self::MODE_HUBER) {
			throw new InvalidArgument(\sprintf('Unknown gating mode %d', $mode));
		}
		return new self(
			ConfigReader::string($data, 'name', 'custom'),
			$mode,
			ConfigReader::float($data, 'parameter', 0.0),
		);
	}
}
