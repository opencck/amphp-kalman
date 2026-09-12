<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Filter;

use OpenCCK\Kalman\Domain\Diagnostics\Nees;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Filter\ExtendedKalmanFilter;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Filter\UnscentedKalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Finance\LogPriceEtfBasket;
use OpenCCK\Kalman\Domain\Model\Finance\StochasticVolatilityLeverage;
use OpenCCK\Kalman\Domain\Model\Generic\LinearMotionAdapter;
use OpenCCK\Kalman\Domain\Model\Observation\LinearObservationAdapter;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Support\RandomLinearModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class NonlinearFiltersTest extends TestCase
{
	/** §7.5: EKF and UKF on a LINEAR model reproduce the KF. */
	public function testEkfAndUkfEqualKalmanFilterOnLinearModel(): void
	{
		$rng = new Rng(1201);
		$n = 4;
		$m = 3;
		$model = new RandomLinearModel($rng, $n, $m);
		$obs = new StaticObservation($model->rows(), $model->variances());
		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);
		$cfg = FilterConfig::default()->withMatrixCache(false)->withForm(FilterForm::Joseph);

		$kf = new KalmanFilter($model, $obs, $x0, $P0, $cfg);
		$ekf = new ExtendedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg);
		$ukf = new UnscentedKalmanFilter(new LinearMotionAdapter($model), new LinearObservationAdapter($obs), $x0, $P0, $cfg, alpha: 1.0, beta: 2.0, kappa: 3.0 - $n);

		$ts = 0;
		for ($k = 0; $k < 60; $k++) {
			$ts += $rng->int(100_000_000, 800_000_000);
			$values = [];
			for ($c = 0; $c < $m; $c++) {
				if ($rng->uniform() < 0.7) {
					$values[$c] = $rng->normal() * 2.0;
				}
			}
			$mst = Measurement::at($ts, $values);
			$a = $kf->step($mst);
			$b = $ekf->step($mst);
			$c = $ukf->step($mst);
			self::assertLessThan(1e-9, Flat::maxAbsDiff($kf->mean(), $ekf->mean()), "EKF mean step $k");
			self::assertLessThan(1e-9, Flat::maxAbsDiff($kf->covariance(), $ekf->covariance()), "EKF cov step $k");
			self::assertLessThan(1e-7, Flat::maxAbsDiff($kf->mean(), $ukf->mean()), "UKF mean step $k");
			self::assertLessThan(1e-7, Flat::maxAbsDiff($kf->covariance(), $ukf->covariance()), "UKF cov step $k");
			self::assertEqualsWithDelta($a->logLikelihood, $b->logLikelihood, 1e-9);
			self::assertEqualsWithDelta($a->logLikelihood, $c->logLikelihood, 1e-6);
		}
		self::assertSame($kf->lastTimestampNs(), $ukf->lastTimestampNs());
	}

	public function testLogPriceEtfEkfTracksSimulatedBasket(): void
	{
		$weights = [0.5, 0.3, 0.2];
		$sigma = [4e-6, 1e-6, 5e-7, 1e-6, 3e-6, 2e-7, 5e-7, 2e-7, 2e-6];
		$model = new LogPriceEtfBasket($weights, $sigma, 0.05, 0.01, [1e-4, 2e-4, 3e-4], 5e-5);
		$filter = $model->filter([100.0, 50.0, 20.0], null, logPriceStd: 0.002, premiumPriorStd: 0.03);
		$rng = new Rng(1202);
		// truth drawn from the prior
		$truth = [];
		foreach ([100.0, 50.0, 20.0] as $p) {
			$truth[] = \log($p) + 0.002 * $rng->normal();
		}
		$truth[] = 0.03 * $rng->normal();
		$dt = 0.5;
		$Q = $model->processNoise($dt);
		$neesSum = 0.0;
		$ts = 0;
		$N = 3000;
		for ($s = 0; $s < $N; $s++) {
			$ts += 500_000_000;
			$truth = $model->propagate($truth, $dt);
			$w = $rng->multivariateNormal($Q, 4);
			for ($i = 0; $i < 4; $i++) {
				$truth[$i] += $w[$i];
			}
			$z = $model->project($truth);
			$values = [];
			for ($c = 0; $c < 4; $c++) {
				if ($rng->uniform() < 0.7) {
					$values[$c] = $z[$c] + \sqrt($model->channelVariance($c)) * $rng->normal();
				}
			}
			$filter->step(Measurement::at($ts, $values));
			$neesSum += Nees::compute($truth, $filter->mean(), $filter->covariance(), 4);
		}
		$nees = $neesSum / $N;
		// EKF linearisation error is tiny here (prices move ~0.1 %); allow a wider band than the linear tests
		self::assertGreaterThan(3.4, $nees, "NEES $nees");
		self::assertLessThan(4.6, $nees, "NEES $nees");
		$prices = $model->prices($filter);
		self::assertEqualsWithDelta(\exp($truth[0]), $prices[0], 0.5);
		self::assertSame(3, $model->premiumIndex());
	}

	public function testUkfStochasticVolatilityWithLeverageTracksVariance(): void
	{
		$mu = -9.0;
		$phi = 0.97;
		$sigmaEta = 0.2;
		$rho = -0.5;
		$model = new StochasticVolatilityLeverage($mu, $phi, $sigmaEta, $rho);
		$filter = $model->filter();
		$rng = new Rng(1203);
		$h = $mu;
		$eps = $rng->normal();
		$ts = 0;
		$hs = [];
		$est = [];
		for ($k = 0; $k < 4000; $k++) {
			$ts += 60_000_000_000;
			// truth: leverage — today's variance shock correlated with yesterday's return shock
			$zeta = $rng->normal();
			$h = $mu + $phi * ($h - $mu) + $sigmaEta * ($rho * $eps + \sqrt(1.0 - $rho * $rho) * $zeta);
			$eps = $rng->normal();
			$r = \exp(0.5 * $h) * $eps;
			$filter->step(Measurement::at($ts, [0 => $r]));
			if ($k >= 500) {
				$hs[] = $h;
				$est[] = $filter->meanAt(0);
			}
		}
		// the estimate must be correlated with the true log-variance and unbiased on average
		$n = \count($hs);
		$mh = \array_sum($hs) / $n;
		$me = \array_sum($est) / $n;
		$cov = 0.0;
		$vh = 0.0;
		$ve = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$cov += ($hs[$i] - $mh) * ($est[$i] - $me);
			$vh += ($hs[$i] - $mh) ** 2;
			$ve += ($est[$i] - $me) ** 2;
		}
		$corr = $cov / \sqrt($vh * $ve);
		self::assertGreaterThan(0.4, $corr, "correlation $corr");
		self::assertEqualsWithDelta($mh, $me, 0.3, 'mean log-variance');
		self::assertGreaterThan(0.0, StochasticVolatilityLeverage::volatility($filter));
		self::assertTrue($filter->invariantsHold());
	}
}
