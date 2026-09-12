<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Observation;

use OpenCCK\Kalman\Domain\Contract\NonlinearObservationModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;

/**
 * Presents a linear ObservationModel as a NonlinearObservationModel
 * (h(x) = H·x, gradient rows = rows of H).
 */
final class LinearObservationAdapter implements NonlinearObservationModel
{
	public function __construct(private readonly ObservationModel $inner)
	{
	}

	public function channelCount(): int
	{
		return $this->inner->channelCount();
	}

	public function project(array $x): array
	{
		$m = $this->inner->channelCount();
		$out = [];
		for ($c = 0; $c < $m; $c++) {
			$sum = 0.0;
			foreach ($this->inner->channelRow($c) as $j => $h) {
				$sum += $h * $x[$j];
			}
			$out[] = $sum;
		}
		return $out;
	}

	public function projectChannel(int $channel, array $x): float
	{
		$sum = 0.0;
		foreach ($this->inner->channelRow($channel) as $j => $h) {
			$sum += $h * $x[$j];
		}
		return $sum;
	}

	public function jacobianRow(int $channel, array $x): array
	{
		return $this->inner->channelRow($channel);
	}

	public function channelVariance(int $channel): float
	{
		return $this->inner->channelVariance($channel);
	}

	public function channelName(int $channel): string
	{
		return $this->inner->channelName($channel);
	}
}
