<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Filter;

use OpenCCK\Kalman\App\Calibration\InnovationLikelihood;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Filter\FilterBatch;
use OpenCCK\Kalman\Domain\Model\Finance\PairsHedge;
use OpenCCK\Kalman\Infrastructure\Ingest\TickCodec;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * Time-varying observation rows shipped with the ticks (FilterBatch "rows")
 * reproduce the in-process MutableObservation run exactly.
 */
final class FilterBatchRowsTest extends TestCase
{
	public function testPerTickRowsMatchInProcessMutableObservation(): void
	{
		$pairs = new PairsHedge(1e-6, 1e-8, 0.05);
		$filter = $pairs->filter(0.5, 1.2);
		$config = $pairs->config(0.5, 1.2);
		$rng = new Rng(77);
		$ticks = [];
		$ts = 0;
		$x = 100.0;
		for ($k = 0; $k < 300; $k++) {
			$ts += 1_000_000_000;
			$x += 0.2 * $rng->normal();
			$y = 0.5 + 1.2 * $x + 0.05 * $rng->normal();
			$ticks[] = $pairs->tick($ts, $y, $x);
			$pairs->setRegressor($x);
			$filter->step(Measurement::at($ts, [0 => $y]));
		}
		// round-trip through the JSON line format as a worker would receive it
		$decoded = [];
		foreach ($ticks as $t) {
			$decoded[] = TickCodec::decodeLine(TickCodec::encodeRaw($t));
		}
		self::assertSame([0 => [0 => 1.0, 1 => $ticks[0]['rows'][0][1]]], $decoded[0]['rows'] ?? null);

		$batch = FilterBatch::run($config, $filter->config()->toArray(), null, $decoded);
		self::assertSame($filter->mean(), $batch['snapshot']['x']);
		self::assertSame($filter->covariance(), $batch['snapshot']['P']);
		self::assertSame($filter->logLikelihood(), InnovationLikelihood::evaluate($config, $filter->config()->toArray(), $decoded));
	}

	public function testRowsRequireMutableObservation(): void
	{
		$config = [
			'motion' => ['type' => 'random-walk', 'sigma' => 1.0],
			'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [1.0]],
		];
		$this->expectException(InvalidArgument::class);
		FilterBatch::run($config, null, null, [['ts' => 1, 'values' => [0 => 1.0], 'rows' => [0 => [0 => 2.0]]]]);
	}
}
