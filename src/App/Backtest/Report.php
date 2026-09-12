<?php declare(strict_types=1);

namespace OpenCCK\Kalman\App\Backtest;

/**
 * Backtest summary (§8 Phase 6): consistency statistics, likelihood and,
 * when a truth channel is supplied, the accuracy of the filter and of the
 * smoother plus how much the filter lags the smoother.
 */
final readonly class Report
{
	/**
	 * @param array<int, float> $nisPerChannel
	 * @param array<int, float> $rejectionRatePerChannel
	 * @param array<string, mixed> $extra
	 */
	public function __construct(
		public int $steps,
		public float $logLikelihood,
		public float $meanNis,
		public array $nisPerChannel,
		public array $rejectionRatePerChannel,
		public ?float $meanNees,
		public ?float $filterRmse,
		public ?float $smootherRmse,
		public ?float $filterLagSteps,
		public float $seconds,
		public array $extra = [],
	) {
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'steps' => $this->steps,
			'logLikelihood' => $this->logLikelihood,
			'meanNis' => $this->meanNis,
			'nisPerChannel' => $this->nisPerChannel,
			'rejectionRatePerChannel' => $this->rejectionRatePerChannel,
			'meanNees' => $this->meanNees,
			'filterRmse' => $this->filterRmse,
			'smootherRmse' => $this->smootherRmse,
			'filterLagSteps' => $this->filterLagSteps,
			'seconds' => $this->seconds,
			'extra' => $this->extra,
		];
	}

	public function toJson(): string
	{
		return \json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_PRESERVE_ZERO_FRACTION);
	}
}
