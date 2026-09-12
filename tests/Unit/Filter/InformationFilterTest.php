<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Filter;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\UnsupportedOperation;
use OpenCCK\Kalman\Domain\Filter\InformationFilter;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Finance\NelsonSiegel;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Support\RandomLinearModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class InformationFilterTest extends TestCase
{
	/** §7.5: information form == covariance form for a positive definite prior. */
	public function testEquivalentToCovarianceForm(): void
	{
		$rng = new Rng(901);
		$n = 4;
		$m = 3;
		$model = new RandomLinearModel($rng, $n, $m);
		$obs = new StaticObservation($model->rows(), $model->variances());
		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);

		$kf = new KalmanFilter($model, $obs, $x0, $P0, FilterConfig::default()->withMatrixCache(false));
		$if = InformationFilter::fromCovariance($model, $obs, $x0, $P0);

		$ts = 0;
		for ($k = 0; $k < 60; $k++) {
			$ts += $rng->int(100_000_000, 900_000_000);
			$values = [];
			for ($c = 0; $c < $m; $c++) {
				if ($rng->uniform() < 0.7) {
					$values[$c] = $rng->normal() * 2.0;
				}
			}
			$mst = Measurement::at($ts, $values);   // may be blind
			$kf->step($mst);
			$if->step($mst);
			self::assertLessThan(1e-8, Flat::maxAbsDiff($kf->mean(), $if->mean()), "mean at step $k");
			self::assertLessThan(1e-8, Flat::maxAbsDiff($kf->covariance(), $if->covariance()), "covariance at step $k");
		}
		self::assertSame($kf->lastTimestampNs(), $if->lastTimestampNs());
		self::assertSame(4, $if->snapshot()->size);
	}

	public function testNoPriorBecomesDefinedAfterEnoughInformation(): void
	{
		// 2 states observed through 2 channels: after one full measurement Y is PD (F = I random walks)
		$model = new \OpenCCK\Kalman\Domain\Model\Generic\BlockDiagonal([
			new \OpenCCK\Kalman\Domain\Model\Generic\RandomWalk(0.1),
			new \OpenCCK\Kalman\Domain\Model\Generic\RandomWalk(0.1),
		]);
		$obs = new StaticObservation([[0 => 1.0], [1 => 1.0]], [0.04, 0.09]);
		$if = new InformationFilter($model, $obs);   // Y₀ = 0
		self::assertFalse($if->hasInformation());
		$if->step(Measurement::at(1_000_000_000, [0 => 3.0]));
		self::assertFalse($if->hasInformation());          // only one direction known
		$if->step(Measurement::at(2_000_000_000, [1 => -1.0]));
		self::assertTrue($if->hasInformation());
		$mean = $if->mean();
		self::assertEqualsWithDelta(3.0, $mean[0], 1e-12);
		self::assertEqualsWithDelta(-1.0, $mean[1], 1e-12);
		$P = $if->covariance();
		self::assertEqualsWithDelta(0.09, $P[3], 1e-12);   // exactly the measurement variance: no prior, no time passed for state 1
	}

	public function testMeanUndefinedWithoutInformation(): void
	{
		$if = new InformationFilter(new \OpenCCK\Kalman\Domain\Model\Generic\RandomWalk(1.0), new StaticObservation([[0 => 1.0]], [1.0]));
		$this->expectException(UnsupportedOperation::class);
		$if->mean();
	}

	/** Distributed fusion (§3.4): sum of remote information contributions == central update. */
	public function testAddInformationEqualsCorrect(): void
	{
		$ns = new NelsonSiegel(0.0609, [3.0, 12.0, 60.0, 120.0], [0.02, 0.05, 0.1], [5.0, -1.5, 0.5], [0.05, 0.08, 0.15], \array_fill(0, 4, 4e-4));
		$kf = $ns->filter();
		$central = InformationFilter::fromCovariance($ns->motion(), $ns->observation(), $kf->mean(), $kf->covariance());
		$distributed = InformationFilter::fromCovariance($ns->motion(), $ns->observation(), $kf->mean(), $kf->covariance());
		$z = [0 => 5.1, 1 => 4.6, 2 => 4.2, 3 => 4.4];
		$central->correct(Measurement::at(1, $z));
		$obs = $ns->observation();
		foreach ([3, 1, 0, 2] as $c) {      // arbitrary order from four "venues"
			$distributed->addInformation($obs->channelRow($c), $obs->channelVariance($c), $z[$c]);
		}
		self::assertLessThan(1e-12, Flat::maxAbsDiff($central->informationMatrix(), $distributed->informationMatrix()));
		self::assertLessThan(1e-12, Flat::maxAbsDiff($central->informationVector(), $distributed->informationVector()));
		$kf->correct(Measurement::at(1, $z));
		self::assertLessThan(1e-9, Flat::maxAbsDiff($kf->mean(), $central->mean()));
	}
}
