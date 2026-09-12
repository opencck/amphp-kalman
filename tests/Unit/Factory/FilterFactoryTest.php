<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Factory;

use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\FilterFactory;
use OpenCCK\Kalman\Domain\Factory\ModelRegistry;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Generic\RandomWalk;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use PHPUnit\Framework\TestCase;

final class FilterFactoryTest extends TestCase
{
	public function testBuildsFromArrayConfig(): void
	{
		$f = FilterFactory::fromConfig([
			'motion' => ['type' => 'constant-velocity', 'sigmaA' => 0.5],
			'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [0.01], 'names' => ['px']],
			'x0' => [100, 0],
			'P0diag' => [4.0, 1.0],
		], ['form' => 'sequential']);
		self::assertSame(2, $f->stateSize());
		self::assertSame(1, $f->channelCount());
		self::assertSame([100.0, 0.0], $f->mean());
		self::assertSame([4.0, 0.0, 0.0, 1.0], $f->covariance());
		self::assertSame(FilterForm::Sequential, $f->config()->form);
		self::assertSame('px', $f->observation()->channelName(0));
	}

	public function testDescribeRoundTrip(): void
	{
		$f = FilterFactory::fromConfig([
			'motion' => ['type' => 'random-walk', 'sigma' => 2.0],
			'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [0.5]],
			'x0' => [3.0],
			'P0' => [7.0],
		]);
		$desc = FilterFactory::describe($f);
		$g = FilterFactory::fromConfig($desc);
		self::assertSame($f->mean(), $g->mean());
		self::assertSame($f->covariance(), $g->covariance());
		self::assertIsArray($desc['motion']);
		self::assertSame('random-walk', $desc['motion']['type']);
	}

	public function testModelRegistryKnowsBuiltins(): void
	{
		self::assertContains('constant-velocity', ModelRegistry::types());
		self::assertSame(ConstantVelocity::class, ModelRegistry::classFor('constant-velocity'));
		self::assertSame(RandomWalk::class, ModelRegistry::classFor('random-walk'));
		self::assertSame(StaticObservation::class, ModelRegistry::classFor('static-observation'));
	}

	public function testUnknownTypeThrows(): void
	{
		$this->expectException(InvalidArgument::class);
		ModelRegistry::build(['type' => 'nope']);
	}

	public function testMotionUsedAsObservationThrows(): void
	{
		$this->expectException(InvalidArgument::class);
		FilterFactory::fromConfig([
			'motion' => ['type' => 'random-walk', 'sigma' => 1.0],
			'observation' => ['type' => 'random-walk', 'sigma' => 1.0],
		]);
	}
}
