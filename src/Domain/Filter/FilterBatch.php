<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Filter;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\FilterFactory;
use OpenCCK\Kalman\Domain\Model\Observation\MutableObservation;

/**
 * Pure, process-boundary-safe filter run: given a serialisable model
 * description, a starting snapshot and a batch of ticks, returns the
 * resulting snapshot plus per-tick diagnostics. This is the unit of work
 * that can be shipped to a worker, a queue consumer or another machine
 * (ADR-005). The in-process KalmanFilter produces bit-identical results.
 *
 * Time-varying observation rows travel WITH the ticks: an optional
 * `rows` key (channel => sparse row) is applied to the model's
 * MutableObservation before the tick is processed — this is how a pairs
 * regression H_t = [1, x_t] crosses a process boundary.
 *
 * Note on splitting a stream into several batches: the F(dt)/Q(dt) cache
 * quantises dt (FilterConfig::dtQuantum) and a fresh process starts with a
 * cold cache, so results across a split are bit-identical only with the
 * cache disabled or with exactly regular dt. With the cache on, the
 * difference is bounded by the quantisation error (≈ 1e-6 relative in dt).
 */
final class FilterBatch
{
	private function __construct()
	{
	}

	/**
	 * @deterministic
	 * @offloadable
	 *
	 * @param array<string, mixed> $modelConfig  FilterFactory::fromConfig() shape
	 * @param array<string, mixed>|null $filterConfig FilterConfig::toArray() shape
	 * @param array<string, mixed>|null $snapshot StateSnapshot::toArray() shape (null → x0/P0 from model config)
	 * @param array<int, array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>}> $ticks
	 * @param bool $withInnovations include per-tick innovations / weights in the result
	 * @return array{
	 *     snapshot: array{n: int, x: array<int, float>, P: array<int, float>, ts: int|null, ll: float, steps: int},
	 *     processed: int,
	 *     logLikelihood: float,
	 *     innovations?: array<int, array<int, array{y: float, s: float, w: float}>>
	 * }
	 */
	public static function run(
		array $modelConfig,
		?array $filterConfig,
		?array $snapshot,
		array $ticks,
		bool $withInnovations = false,
	): array {
		$filter = FilterFactory::fromConfig($modelConfig, $filterConfig, $snapshot);
		$observation = $filter->observation();
		$before = $filter->logLikelihood();
		$innovations = [];

		foreach ($ticks as $tick) {
			if (isset($tick['rows'])) {
				if (!$observation instanceof MutableObservation) {
					throw new InvalidArgument('Per-tick rows require a mutable-observation model');
				}
				foreach ($tick['rows'] as $channel => $row) {
					$observation->setRow($channel, $row);
				}
			}
			$filter->stepRaw($tick['ts'], $tick['values']);
			if ($withInnovations) {
				$row = [];
				foreach ($filter->lastChannels() as $ch) {
					$row[$ch] = [
						'y' => $filter->lastInnovation($ch),
						's' => $filter->lastInnovationVariance($ch),
						'w' => $filter->lastWeight($ch),
					];
				}
				$innovations[] = $row;
			}
		}

		$out = [
			'snapshot' => $filter->snapshot()->toArray(),
			'processed' => \count($ticks),
			'logLikelihood' => $filter->logLikelihood() - $before,
		];
		if ($withInnovations) {
			$out['innovations'] = $innovations;
		}
		return $out;
	}
}
