<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Adaptive;

use OpenCCK\Kalman\Domain\Entity\UpdateResult;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * §2.11.4 innovation-based adaptive estimation (Mehra 1970) over a sliding
 * window of N steps, per channel:
 *
 *   Ĉ_i  = mean(ỹ_i²)                   realised innovation variance
 *   R̂_i  = max(r_min, Ĉ_i − mean(h P⁻ hᵀ))   since s = h P⁻ hᵀ + r
 *   q̂    = mean over channels of Ĉ_i / mean(s_i)   → multiply Q by q̂ (≈ 1 when consistent)
 *
 * Only accepted channels contribute. The estimates are suggestions — apply
 * them through MutableObservation::setVariance() / HeteroscedasticMotionModel::setMultiplier()
 * with your own damping; see {@see AdaptiveNoise} for a ready-made loop.
 */
final class InnovationAdaptive
{
	/** @var array<int, array<int, float>> per channel ring of ỹ² */
	private array $sq = [];

	/** @var array<int, array<int, float>> per channel ring of h P⁻ hᵀ = s − r */
	private array $hph = [];

	/** @var array<int, array<int, float>> per channel ring of s */
	private array $s = [];

	/** @var array<int, int> */
	private array $pos = [];

	/** @var array<int, int> */
	private array $count = [];

	public function __construct(
		private readonly int $channels,
		private readonly int $window = 200,
		private readonly float $rMin = 1e-12,
	) {
		if ($channels < 1 || $window < 2) {
			throw new InvalidArgument('channels >= 1 and window >= 2 required');
		}
		for ($c = 0; $c < $channels; $c++) {
			$this->sq[$c] = \array_fill(0, $window, 0.0);
			$this->hph[$c] = \array_fill(0, $window, 0.0);
			$this->s[$c] = \array_fill(0, $window, 0.0);
			$this->pos[$c] = 0;
			$this->count[$c] = 0;
		}
	}

	/**
	 * @param array<int, float> $variances the r_i the filter used on this step (channel => r)
	 */
	public function record(UpdateResult $result, array $variances): void
	{
		foreach ($result->outcomes as $o) {
			if ($o->weight <= 0.0 || !isset($variances[$o->channel])) {
				continue;
			}
			$c = $o->channel;
			$p = $this->pos[$c];
			$this->sq[$c][$p] = $o->innovation * $o->innovation;
			$this->hph[$c][$p] = $o->innovationVariance - $variances[$c];
			$this->s[$c][$p] = $o->innovationVariance;
			$this->pos[$c] = ($p + 1) % $this->window;
			if ($this->count[$c] < $this->window) {
				$this->count[$c]++;
			}
		}
	}

	public function samples(int $channel): int
	{
		return $this->count[$channel];
	}

	/** Realised innovation variance Ĉ_i. */
	public function realisedVariance(int $channel): float
	{
		return self::mean($this->sq[$channel], $this->count[$channel]);
	}

	/** R̂_i = max(r_min, Ĉ_i − mean(hP⁻hᵀ)); NAN before any sample. */
	public function suggestedR(int $channel): float
	{
		$k = $this->count[$channel];
		if ($k === 0) {
			return \NAN;
		}
		$r = self::mean($this->sq[$channel], $k) - self::mean($this->hph[$channel], $k);
		return $r < $this->rMin ? $this->rMin : $r;
	}

	/**
	 * Ratio of realised to predicted innovation variance averaged over channels
	 * with data: > 1 → the filter is over-confident (inflate Q), < 1 → deflate.
	 */
	public function suggestedQScale(): float
	{
		$sum = 0.0;
		$n = 0;
		for ($c = 0; $c < $this->channels; $c++) {
			$k = $this->count[$c];
			if ($k === 0) {
				continue;
			}
			$predicted = self::mean($this->s[$c], $k);
			if ($predicted <= 0.0) {
				continue;
			}
			$sum += self::mean($this->sq[$c], $k) / $predicted;
			$n++;
		}
		return $n === 0 ? \NAN : $sum / $n;
	}

	/** @param array<int, float> $ring */
	private static function mean(array $ring, int $count): float
	{
		if ($count === 0) {
			return \NAN;
		}
		$sum = 0.0;
		for ($i = 0; $i < $count; $i++) {
			$sum += $ring[$i];
		}
		return $sum / $count;
	}
}
