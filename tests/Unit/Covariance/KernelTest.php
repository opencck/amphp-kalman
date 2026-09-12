<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Covariance;

use OpenCCK\Kalman\Domain\Covariance\DenseSequential;
use OpenCCK\Kalman\Domain\Covariance\Kernels\Sequential2;
use OpenCCK\Kalman\Domain\Covariance\Kernels\Sequential4;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Factory\CovarianceFactory;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * Generated loop-free kernels are bit-identical to the generic DenseSequential
 * (same operation order), so results are compared with assertSame.
 */
final class KernelTest extends TestCase
{
	public function testFactorySelectsKernels(): void
	{
		self::assertInstanceOf(Sequential2::class, CovarianceFactory::create(FilterForm::Sequential, 2));
		self::assertInstanceOf(Sequential4::class, CovarianceFactory::create(FilterForm::Sequential, 4));
		self::assertInstanceOf(DenseSequential::class, CovarianceFactory::create(FilterForm::Sequential, 3));
		self::assertInstanceOf(DenseSequential::class, CovarianceFactory::create(FilterForm::Sequential, 2, false));
		self::assertFalse(FilterConfig::default()->withUnrolledKernels(false)->unrolledKernels);
	}

	/** @return iterable<string, array{int}> */
	public function sizes(): iterable
	{
		yield 'n=2' => [2];
		yield 'n=4' => [4];
	}

	/** @dataProvider sizes */
	public function testKernelMatchesGenericBitwise(int $n): void
	{
		$rng = new Rng(700 + $n);
		$kernel = CovarianceFactory::create(FilterForm::Sequential, $n, true);
		$generic = new DenseSequential($n);
		$P0 = $rng->spdMatrix($n);
		$kernel->fromDense($P0);
		$generic->fromDense($P0);
		self::assertSame($generic->toDense(), $kernel->toDense());

		for ($step = 0; $step < 50; $step++) {
			$F = $rng->matrix($n, $n, 0.3);
			for ($i = 0; $i < $n; $i++) {
				$F[$i * $n + $i] += 1.0;
			}
			$Q = $rng->spdMatrix($n, 0.2, 0.01);
			$kernel->predictDense($F, $Q);
			$generic->predictDense($F, $Q);
			self::assertSame($generic->toDense(), $kernel->toDense(), "predict step $step");

			// random sparse row with 1..n entries; index-sorted like StaticObservation
			// (bit-identity requires the same summation order as the generic foreach)
			$h = [];
			$count = $rng->int(1, $n);
			for ($c = 0; $c < $count; $c++) {
				$h[$rng->int(0, $n - 1)] = $rng->normal();
			}
			\ksort($h);
			$r = $rng->uniformBetween(0.1, 2.0);
			$s1 = $kernel->prepareScalar($h, $r);
			$s2 = $generic->prepareScalar($h, $r);
			self::assertSame($s2, $s1);
			self::assertSame($generic->gain(), $kernel->gain());
			$kernel->commitScalar($r);
			$generic->commitScalar($r);
			self::assertSame($generic->toDense(), $kernel->toDense(), "correct step $step");
			for ($i = 0; $i < $n; $i++) {
				self::assertSame($generic->variance($i), $kernel->variance($i));
			}
		}
	}

	public function testSparsePredictMatchesGeneric(): void
	{
		$model = new ConstantVelocity(0.4);
		$kernel = new Sequential2();
		$generic = new DenseSequential(2);
		$P0 = [2.0, 0.1, 0.1, 0.5];
		$kernel->fromDense($P0);
		$generic->fromDense($P0);
		$x1 = [1.0, 0.3];
		$x2 = [1.0, 0.3];
		$Q = $model->processNoise(0.25);
		$kernel->predictSparse($model, 0.25, $x1, $Q);
		$generic->predictSparse($model, 0.25, $x2, $Q);
		self::assertSame($x2, $x1);
		self::assertSame($generic->toDense(), $kernel->toDense());
	}
}
