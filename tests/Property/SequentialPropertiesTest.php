<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Property;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Filter\FilterBatch;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Reference\SequentialVsNaiveTest;
use OpenCCK\Kalman\Tests\Support\RandomLinearModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * §7.5 property-based checks on random models.
 */
final class SequentialPropertiesTest extends TestCase
{
	/** @return iterable<string, array{FilterForm}> */
	public function forms(): iterable
	{
		foreach (SequentialVsNaiveTest::availableForms() as $form) {
			yield $form->value => [$form];
		}
	}

	/** @dataProvider forms */
	public function testChannelOrderDoesNotMatter(FilterForm $form): void
	{
		$rng = new Rng(11);
		$n = 5;
		$m = 4;
		$model = new RandomLinearModel($rng, $n, $m);
		$obs = new StaticObservation($model->rows(), $model->variances());
		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);
		$cfg = FilterConfig::default()->withForm($form);

		$a = new KalmanFilter($model, $obs, $x0, $P0, $cfg);
		$b = new KalmanFilter($model, $obs, $x0, $P0, $cfg);
		for ($k = 0; $k < 30; $k++) {
			$dt = $rng->uniformBetween(0.1, 1.0);
			$a->predict($dt);
			$b->predict($dt);
			$values = [0 => $rng->normal(), 1 => $rng->normal(), 2 => $rng->normal(), 3 => $rng->normal()];
			$a->correct(Measurement::at($k, $values));
			$b->correct(Measurement::at($k, [3 => $values[3], 1 => $values[1], 0 => $values[0], 2 => $values[2]]));
			self::assertLessThan(1e-10, Flat::maxAbsDiff($a->mean(), $b->mean()));
			self::assertLessThan(1e-10, Flat::maxAbsDiff($a->covariance(), $b->covariance()));
		}
	}

	/** @dataProvider forms */
	public function testTwoMeasurementsEquivalentToOneWithCombinedVariance(FilterForm $form): void
	{
		$rng = new Rng(12);
		$n = 3;
		$model = new RandomLinearModel($rng, $n, 1);
		$row = $model->rows()[0];
		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);
		$r1 = 0.3;
		$r2 = 0.7;
		$z1 = 1.2;
		$z2 = -0.4;
		$rComb = 1.0 / (1.0 / $r1 + 1.0 / $r2);
		$zComb = $rComb * ($z1 / $r1 + $z2 / $r2);

		$two = new KalmanFilter($model, new StaticObservation([$row, $row], [$r1, $r2]), $x0, $P0, FilterConfig::default()->withForm($form));
		$two->correct(Measurement::at(0, [0 => $z1, 1 => $z2]));

		$one = new KalmanFilter($model, new StaticObservation([$row], [$rComb]), $x0, $P0, FilterConfig::default()->withForm($form));
		$one->correct(Measurement::at(0, [0 => $zComb]));

		self::assertLessThan(1e-10, Flat::maxAbsDiff($one->mean(), $two->mean()));
		self::assertLessThan(1e-10, Flat::maxAbsDiff($one->covariance(), $two->covariance()));
	}

	/** @dataProvider forms */
	public function testPredictComposesForContinuousTimeModel(FilterForm $form): void
	{
		$obs = new StaticObservation([[0 => 1.0]], [1.0]);
		$make = static fn (): KalmanFilter => new KalmanFilter(new ConstantVelocity(0.8), $obs, [1.0, 0.5], [2.0, 0.3, 0.3, 1.0], FilterConfig::default()->withForm($form));
		$a = $make();
		$b = $make();
		$a->predict(0.3);
		$a->predict(0.9);
		$b->predict(1.2);
		self::assertLessThan(1e-12, Flat::maxAbsDiff($a->mean(), $b->mean()));
		self::assertLessThan(1e-12, Flat::maxAbsDiff($a->covariance(), $b->covariance()));
	}

	/** @dataProvider forms */
	public function testSkippingAllChannelsEqualsBlindSteps(FilterForm $form): void
	{
		$obs = new StaticObservation([[0 => 1.0], [1 => 1.0]], [1.0, 1.0]);
		$make = static fn (): KalmanFilter => new KalmanFilter(new ConstantVelocity(0.8), $obs, [1.0, 0.5], [2.0, 0.3, 0.3, 1.0], FilterConfig::default()->withForm($form));
		$a = $make();
		$b = $make();
		for ($k = 0; $k < 10; $k++) {
			$a->step(Measurement::at($k * 1_000_000_000, []));
			$b->step(Measurement::blind($k * 1_000_000_000));
		}
		self::assertSame($a->mean(), $b->mean());
		self::assertSame($a->covariance(), $b->covariance());
	}

	/** @dataProvider forms */
	public function testFilterBatchIsBitIdenticalToInProcessFilter(FilterForm $form): void
	{
		$modelConfig = [
			'motion' => ['type' => 'constant-velocity', 'sigmaA' => 0.4],
			'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [0.02]],
			'x0' => [10.0, 0.0],
			'P0' => [1.0, 0.0, 0.0, 1.0],
		];
		$filterConfig = FilterConfig::default()->withForm($form)->toArray();
		$ticks = [];
		$rng = new Rng(99);
		for ($k = 0; $k < 200; $k++) {
			$ticks[] = ['ts' => $k * 50_000_000 + $rng->int(0, 10_000), 'values' => [0 => 10.0 + $rng->normal()]];
		}

		$inProcess = new KalmanFilter(new ConstantVelocity(0.4), new StaticObservation([[0 => 1.0]], [0.02]), [10.0, 0.0], [1.0, 0.0, 0.0, 1.0], FilterConfig::fromArray($filterConfig));
		foreach ($ticks as $t) {
			$inProcess->stepRaw($t['ts'], $t['values']);
		}

		// whole batch in one call
		$whole = FilterBatch::run($modelConfig, $filterConfig, null, $ticks);
		self::assertSame($inProcess->mean(), $whole['snapshot']['x']);
		self::assertSame($inProcess->covariance(), $whole['snapshot']['P']);
		self::assertSame($inProcess->logLikelihood(), $whole['logLikelihood']);
		self::assertSame(200, $whole['processed']);

		// split into two batches chained through the snapshot. The F/Q matrix
		// cache quantises dt (1 µs) and is path-dependent (a fresh worker starts
		// with a cold cache), so bit-identity across a split requires the cache off.
		$noCache = FilterConfig::default()->withForm($form)->withMatrixCache(false)->toArray();
		$reference = new KalmanFilter(new ConstantVelocity(0.4), new StaticObservation([[0 => 1.0]], [0.02]), [10.0, 0.0], [1.0, 0.0, 0.0, 1.0], FilterConfig::fromArray($noCache));
		foreach ($ticks as $t) {
			$reference->stepRaw($t['ts'], $t['values']);
		}
		$first = FilterBatch::run($modelConfig, $noCache, null, \array_slice($ticks, 0, 120), true);
		$second = FilterBatch::run($modelConfig, $noCache, $first['snapshot'], \array_slice($ticks, 120));
		self::assertSame($reference->mean(), $second['snapshot']['x']);
		self::assertSame($reference->covariance(), $second['snapshot']['P']);
		self::assertSame($reference->logLikelihood(), $second['snapshot']['ll']);
		self::assertSame(200, $second['snapshot']['steps']);
		self::assertCount(120, $first['innovations'] ?? []);
	}
}
