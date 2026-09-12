<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Diagnostics;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Linalg\LU;

/**
 * §2.10 observability: rank of O = [H; HF; HF²; …; HF^{n−1}] must equal n.
 * Unobservable components are still estimated through the dynamics, but
 * slowly and with growing P — a warning at model-assembly time.
 */
final class ObservabilityCheck
{
	private function __construct()
	{
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $F n×n
	 * @param array<int, float> $H m×n
	 */
	public static function rank(array $F, array $H, int $n, int $m, float $tolerance = 1e-10): int
	{
		$O = [];
		$block = $H;
		for ($k = 0; $k < $n; $k++) {
			foreach ($block as $v) {
				$O[] = $v;
			}
			$block = Flat::multiply($block, $F, $m, $n, $n);
		}
		return LU::rank($O, $n * $m, $n, $tolerance);
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param array<int, float> $F
	 * @param array<int, float> $H
	 */
	public static function isObservable(array $F, array $H, int $n, int $m, float $tolerance = 1e-10): bool
	{
		return self::rank($F, $H, $n, $m, $tolerance) === $n;
	}

	/** Convenience for model objects at a representative dt. */
	public static function forModels(MotionModel $motion, ObservationModel $observation, float $dt): int
	{
		$n = $motion->stateSize();
		$m = $observation->channelCount();
		$H = [];
		for ($c = 0; $c < $m; $c++) {
			foreach (Flat::denseRow($observation->channelRow($c), $n) as $v) {
				$H[] = $v;
			}
		}
		return self::rank($motion->transition($dt), $H, $n, $m);
	}
}
