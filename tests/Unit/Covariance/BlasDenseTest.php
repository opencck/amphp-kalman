<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Covariance;

use OpenCCK\Kalman\Domain\Covariance\BlasDense;
use OpenCCK\Kalman\Domain\Covariance\DenseSequential;
use OpenCCK\Kalman\Domain\Entity\Backend;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Exception\UnsupportedOperation;
use OpenCCK\Kalman\Domain\Factory\CovarianceFactory;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Ffi\BlasBackend;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Support\RandomLinearModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * §6.3 BLAS backend. The numerical tests run only where ext-ffi and an
 * OpenBLAS library are present (CI job "blas"); the configuration tests
 * run everywhere.
 */
final class BlasDenseTest extends TestCase
{
	public function testConfigRoundTripAndValidation(): void
	{
		$cfg = FilterConfig::default()->withBackend(Backend::Blas);
		self::assertSame(Backend::Blas, $cfg->backend);
		self::assertSame('blas', $cfg->toArray()['backend']);
		self::assertSame(Backend::Blas, FilterConfig::fromArray($cfg->toArray())->backend);
		self::assertSame(Backend::Php, FilterConfig::fromArray([])->backend);

		$this->expectException(InvalidArgument::class);
		FilterConfig::default()->withForm(FilterForm::UD)->withBackend(Backend::Blas);
	}

	public function testFactoryRejectsBlasWithNonSequentialForm(): void
	{
		$this->expectException(UnsupportedOperation::class);
		CovarianceFactory::create(FilterForm::UD, 4, true, Backend::Blas);
	}

	public function testUnavailableBackendIsReportedNotFatal(): void
	{
		if (BlasBackend::isAvailable()) {
			self::assertNull(BlasBackend::unavailableReason());
			self::assertNotSame('', BlasBackend::load()->library());
			return;
		}
		self::assertIsString(BlasBackend::unavailableReason());
		$this->expectException(UnsupportedOperation::class);
		CovarianceFactory::create(FilterForm::Sequential, 4, true, Backend::Blas);
	}

	/** @dataProvider sizes */
	public function testMatchesDenseSequentialToRoundoff(int $n): void
	{
		if (!BlasBackend::isAvailable()) {
			self::markTestSkipped('ext-ffi / OpenBLAS not available: ' . (string) BlasBackend::unavailableReason());
		}
		$rng = new Rng(9000 + $n);
		$m = \max(1, \intdiv($n, 2));
		$model = new RandomLinearModel($rng, $n, $m);
		$obs = new StaticObservation($model->rows(), $model->variances());
		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);

		$php = new DenseSequential($n);
		$blas = new BlasDense($n);
		$php->fromDense($P0);
		$blas->fromDense($P0);
		self::assertSame($P0, $blas->toDense());

		$ts = 0;
		for ($k = 0; $k < 50; $k++) {
			$dt = $rng->uniform() * 0.5 + 0.01;
			$F = $model->transition($dt);
			$Q = $model->processNoise($dt);
			$php->predictDense($F, $Q);
			$blas->predictDense($F, $Q);
			self::assertEqualsWithDelta($php->toDense(), $blas->toDense(), 1e-9 * (1.0 + Flat::maxAbs($php->toDense())));
			for ($c = 0; $c < $m; $c++) {
				$h = $obs->channelRow($c);
				$r = $obs->channelVariance($c);
				$sPhp = $php->prepareScalar($h, $r);
				$sBlas = $blas->prepareScalar($h, $r);
				self::assertEqualsWithDelta($sPhp, $sBlas, 1e-9 * $sPhp);
				self::assertEqualsWithDelta($php->gain(), $blas->gain(), 1e-9);
				$php->commitScalar($r);
				$blas->commitScalar($r);
			}
			$P = $blas->toDense();
			self::assertTrue(Flat::isSymmetric($P, $n), 'BLAS P must stay exactly symmetric');
			self::assertEqualsWithDelta($php->toDense(), $P, 1e-9 * (1.0 + Flat::maxAbs($php->toDense())));
		}

		// through the filter with FilterConfig::withBackend
		$kfPhp = new KalmanFilter($model, $obs, $x0, $P0, FilterConfig::default()->withMatrixCache(false));
		$kfBlas = new KalmanFilter($model, $obs, $x0, $P0, FilterConfig::default()->withMatrixCache(false)->withBackend(Backend::Blas));
		for ($k = 0; $k < 30; $k++) {
			$ts += $rng->int(10_000_000, 300_000_000);
			$values = [];
			for ($c = 0; $c < $m; $c++) {
				$values[$c] = $rng->normal();
			}
			$kfPhp->step(Measurement::at($ts, $values));
			$kfBlas->step(Measurement::at($ts, $values));
		}
		self::assertEqualsWithDelta($kfPhp->mean(), $kfBlas->mean(), 1e-8);
		self::assertEqualsWithDelta($kfPhp->covariance(), $kfBlas->covariance(), 1e-8);
	}

	/** @return array<string, array{0: int}> */
	public function sizes(): array
	{
		return ['n=3' => [3], 'n=8' => [8], 'n=33' => [33]];
	}
}
