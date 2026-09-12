<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Filter;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Filter\ExtendedKalmanFilter;
use OpenCCK\Kalman\Domain\Filter\InformationFilter;
use OpenCCK\Kalman\Domain\Filter\UnscentedKalmanFilter;
use OpenCCK\Kalman\Domain\Model\Generic\LinearMotionAdapter;
use OpenCCK\Kalman\Domain\Model\Observation\LinearObservationAdapter;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Support\RandomLinearModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * §8: stepRaw() in every filter — the object-free path must be bit-identical
 * to step() and expose the same per-channel results.
 */
final class RawStepTest extends TestCase
{
	/** @return array<int, array{0: int, 1: array<int, float>}> */
	private function ticks(Rng $rng, int $m, int $count): array
	{
		$ticks = [];
		$ts = 0;
		for ($k = 0; $k < $count; $k++) {
			$ts += $rng->int(50_000_000, 400_000_000);
			$values = [];
			for ($c = 0; $c < $m; $c++) {
				if ($rng->uniform() < 0.8) {
					$values[$c] = $rng->normal() * 1.5;
				}
			}
			$ticks[] = [$ts, $values];
		}
		return $ticks;
	}

	public function testExtendedKalmanFilterRawPathMatchesObjectPath(): void
	{
		$rng = new Rng(8801);
		$n = 4;
		$m = 3;
		$model = new RandomLinearModel($rng, $n, $m);
		$obs = new StaticObservation($model->rows(), $model->variances());
		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);
		$cfg = FilterConfig::default()->withGating(GatingPolicy::huber(2.0));

		$a = new ExtendedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg);
		$b = new ExtendedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg);
		foreach ($this->ticks($rng, $m, 80) as [$ts, $values]) {
			$result = $a->step(Measurement::at($ts, $values));
			$b->stepRaw($ts, $values);
			self::assertSame($a->mean(), $b->mean());
			self::assertSame($a->covariance(), $b->covariance());
			self::assertSame(\array_keys($values), $b->lastChannels());
			foreach ($result->outcomes as $o) {
				self::assertSame($o->innovation, $b->lastInnovation($o->channel));
				self::assertSame($o->innovationVariance, $b->lastInnovationVariance($o->channel));
				self::assertSame($o->weight, $b->lastWeight($o->channel));
			}
			for ($c = 0; $c < $m; $c++) {
				if (!isset($values[$c])) {
					self::assertSame(-1.0, $b->lastWeight($c));
				}
			}
		}
		self::assertSame($a->logLikelihood(), $b->logLikelihood());
		self::assertSame($a->steps(), $b->steps());
	}

	public function testUnscentedKalmanFilterRawPathMatchesObjectPath(): void
	{
		$rng = new Rng(8802);
		$n = 3;
		$m = 2;
		$model = new RandomLinearModel($rng, $n, $m);
		$obs = new StaticObservation($model->rows(), $model->variances());
		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);
		$cfg = FilterConfig::default()->withGating(GatingPolicy::chiSquare(0.01));

		$a = new UnscentedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg, alpha: 1.0, kappa: 0.0);
		$b = new UnscentedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg, alpha: 1.0, kappa: 0.0);
		foreach ($this->ticks($rng, $m, 60) as [$ts, $values]) {
			$result = $a->step(Measurement::at($ts, $values));
			$b->stepRaw($ts, $values);
			self::assertSame($a->mean(), $b->mean());
			self::assertSame($a->covariance(), $b->covariance());
			self::assertSame(\array_keys($values), $b->lastChannels());
			foreach ($result->outcomes as $o) {
				self::assertSame($o->innovation, $b->lastInnovation($o->channel));
				self::assertSame($o->innovationVariance, $b->lastInnovationVariance($o->channel));
				self::assertSame($o->weight, $b->lastWeight($o->channel));
			}
		}
		self::assertSame($a->logLikelihood(), $b->logLikelihood());
	}

	public function testInformationFilterRawPathMatchesObjectPath(): void
	{
		$rng = new Rng(8803);
		$n = 3;
		$m = 4;
		$model = new RandomLinearModel($rng, $n, $m);
		$obs = new StaticObservation($model->rows(), $model->variances());
		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);

		$a = InformationFilter::fromCovariance($model, $obs, $x0, $P0);
		$b = InformationFilter::fromCovariance($model, $obs, $x0, $P0);
		foreach ($this->ticks($rng, $m, 40) as [$ts, $values]) {
			$a->step(Measurement::at($ts, $values));
			$b->stepRaw($ts, $values);
			self::assertSame($a->informationMatrix(), $b->informationMatrix());
			self::assertSame($a->informationVector(), $b->informationVector());
			self::assertSame($a->lastTimestampNs(), $b->lastTimestampNs());
		}
	}
}
