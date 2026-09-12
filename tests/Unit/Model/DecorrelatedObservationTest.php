<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Model;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Observation\CorrelatedObservation;
use OpenCCK\Kalman\Domain\Model\Observation\DecorrelatedObservation;
use OpenCCK\Kalman\Tests\Reference\NaiveKalmanFilter;
use OpenCCK\Kalman\Tests\Support\RandomLinearModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class DecorrelatedObservationTest extends TestCase
{
	public function testWhitenedChannelsReproduceBlockUpdateWithFullR(): void
	{
		$rng = new Rng(500);
		$n = 4;
		$m = 3;
		$model = new RandomLinearModel($rng, $n, $m);
		$H = $model->denseH();
		$R = $rng->spdMatrix($m, 0.5, 0.2);   // full, correlated R

		$decorrelated = new DecorrelatedObservation(new CorrelatedObservation($H, $R, $m, $n));
		self::assertSame($m, $decorrelated->channelCount());
		self::assertSame(1.0, $decorrelated->channelVariance(1));

		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);
		$fast = new KalmanFilter($model, $decorrelated, $x0, $P0, FilterConfig::default()->withMatrixCache(false));
		$naive = new NaiveKalmanFilter($x0, NaiveKalmanFilter::fromFlat($P0, $n, $n));

		for ($k = 0; $k < 50; $k++) {
			$dt = $rng->uniformBetween(0.1, 1.0);
			$fast->predict($dt);
			$naive->predict(
				NaiveKalmanFilter::fromFlat($model->transition($dt), $n, $n),
				NaiveKalmanFilter::fromFlat($model->processNoise($dt), $n, $n),
				$model->control($dt),
			);
			$z = $rng->vector($m, 2.0);
			$values = [];
			foreach ($z as $i => $v) {
				$values[$i] = $v;
			}
			$fast->correct($decorrelated->transform(Measurement::at($k, $values)));
			$naive->correct(NaiveKalmanFilter::fromFlat($H, $m, $n), NaiveKalmanFilter::fromFlat($R, $m, $m), $z);

			self::assertLessThan(1e-9, Flat::maxAbsDiff($naive->x, $fast->mean()));
			self::assertLessThan(1e-9, Flat::maxAbsDiff(NaiveKalmanFilter::toFlat($naive->P), $fast->covariance()));
		}
		// whitened likelihood + Jacobian correction per measurement == original-data likelihood
		$corrected = $fast->logLikelihood() + 50 * $decorrelated->logLikelihoodCorrection();
		self::assertEqualsWithDelta($naive->logLikelihood, $corrected, 1e-7 * \abs($naive->logLikelihood) + 1e-9);
	}

	public function testPartialMeasurementIsRejected(): void
	{
		$d = new DecorrelatedObservation(new CorrelatedObservation([1.0, 0.0, 0.0, 1.0], [1.0, 0.5, 0.5, 1.0], 2, 2));
		$this->expectException(InvalidArgument::class);
		$d->transform(Measurement::at(0, [0 => 1.0]));
	}

	public function testIndefiniteRIsRejected(): void
	{
		$this->expectException(InvalidArgument::class);
		new CorrelatedObservation([1.0, 0.0, 0.0, 1.0], [1.0, 2.0, 2.0, 1.0], 2, 2);
	}
}
