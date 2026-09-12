<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * §2.16 U-shaped intraday variance profile: multiplier of Q as a function
 * of time of day, piecewise-linear between anchor points (seconds since
 * midnight in the exchange's UTC offset). Normalised so the mean over the
 * trading day is 1 when built with fromAnchors(..., normalise: true).
 */
final class IntradayProfile
{
	/** @var array<int, int> sorted seconds-of-day */
	private array $times;

	/** @var array<int, float> */
	private array $multipliers;

	/**
	 * @param array<int, float> $anchors secondOfDay => multiplier (≥ 2 points)
	 */
	public function __construct(array $anchors, private readonly int $utcOffsetSeconds = 0)
	{
		if (\count($anchors) < 2) {
			throw new InvalidArgument('At least two anchor points are required');
		}
		\ksort($anchors);
		$this->times = [];
		$this->multipliers = [];
		foreach ($anchors as $t => $m) {
			if ($t < 0 || $t >= 86400) {
				throw new InvalidArgument('Anchor time must be within a day');
			}
			if ($m <= 0.0) {
				throw new InvalidArgument('Multiplier must be > 0');
			}
			$this->times[] = $t;
			$this->multipliers[] = $m;
		}
	}

	/**
	 * Typical equity U-shape: open 3×, midday 0.6×, close 2× (normalised).
	 */
	public static function equityUShape(int $openSecond = 9 * 3600 + 30 * 60, int $closeSecond = 16 * 3600, int $utcOffsetSeconds = 0): self
	{
		$mid = \intdiv($openSecond + $closeSecond, 2);
		$anchors = [
			$openSecond => 3.0,
			$openSecond + 1800 => 1.4,
			$mid => 0.6,
			$closeSecond - 1800 => 1.2,
			$closeSecond => 2.0,
		];
		return (new self($anchors, $utcOffsetSeconds))->normalised($openSecond, $closeSecond);
	}

	/** Rescales so that the average multiplier over [from, to] is 1. */
	public function normalised(int $fromSecond, int $toSecond, int $samples = 1000): self
	{
		$sum = 0.0;
		for ($i = 0; $i < $samples; $i++) {
			$t = $fromSecond + ($toSecond - $fromSecond) * ($i + 0.5) / $samples;
			$sum += $this->multiplierAtSecond($t);
		}
		$mean = $sum / $samples;
		$anchors = [];
		foreach ($this->times as $i => $t) {
			$anchors[$t] = $this->multipliers[$i] / $mean;
		}
		return new self($anchors, $this->utcOffsetSeconds);
	}

	public function multiplier(int $timestampNs): float
	{
		$seconds = \intdiv($timestampNs, 1_000_000_000) + $this->utcOffsetSeconds;
		$sod = $seconds % 86400;
		if ($sod < 0) {
			$sod += 86400;
		}
		return $this->multiplierAtSecond((float) $sod);
	}

	public function multiplierAtSecond(float $secondOfDay): float
	{
		$times = $this->times;
		$count = \count($times);
		if ($secondOfDay <= $times[0]) {
			return $this->multipliers[0];
		}
		if ($secondOfDay >= $times[$count - 1]) {
			return $this->multipliers[$count - 1];
		}
		for ($i = 1; $i < $count; $i++) {
			if ($secondOfDay <= $times[$i]) {
				$t0 = $times[$i - 1];
				$t1 = $times[$i];
				$w = ($secondOfDay - $t0) / ($t1 - $t0);
				return $this->multipliers[$i - 1] * (1.0 - $w) + $this->multipliers[$i] * $w;
			}
		}
		return $this->multipliers[$count - 1];
	}
}
