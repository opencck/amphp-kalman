<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Entity;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use PHPUnit\Framework\TestCase;

final class GatingPolicyTest extends TestCase
{
	public function testNoneAlwaysAccepts(): void
	{
		$g = GatingPolicy::none();
		self::assertSame(1.0, $g->weight(1000.0, 1.0));
		self::assertSame(\INF, $g->threshold());
	}

	public function testSigma(): void
	{
		$g = GatingPolicy::sigma(3.0);
		self::assertSame(9.0, $g->threshold());
		self::assertSame(1.0, $g->weight(2.9, 1.0));
		self::assertSame(0.0, $g->weight(3.1, 1.0));
	}

	public function testChiSquareThresholdsMatchTable(): void
	{
		self::assertEqualsWithDelta(6.635, GatingPolicy::chiSquare(0.01)->threshold(), 5e-3);
		self::assertEqualsWithDelta(10.828, GatingPolicy::chiSquare(0.001)->threshold(), 5e-3);
	}

	public function testHuberWeights(): void
	{
		$g = GatingPolicy::huber(1.345);
		self::assertSame(1.0, $g->weight(1.0, 1.0));
		self::assertEqualsWithDelta(1.345 / 4.0, $g->weight(4.0, 1.0), 1e-12);
		self::assertGreaterThan(0.0, $g->weight(100.0, 1.0));
	}

	public function testInvalidParameters(): void
	{
		$this->expectException(InvalidArgument::class);
		GatingPolicy::chiSquare(1.5);
	}

	public function testArrayRoundTrip(): void
	{
		$g = GatingPolicy::chiSquare(0.01);
		self::assertEquals($g, GatingPolicy::fromArray($g->toArray()));
	}

	public function testFilterConfigWithersAndRoundTrip(): void
	{
		$c = FilterConfig::default()
			->withForm(FilterForm::UD)
			->withGating(GatingPolicy::sigma(4.0))
			->withMatrixCache(false, 8, 1e-3)
			->withModelBreakThreshold(7);
		self::assertSame(FilterForm::UD, $c->form);
		self::assertSame(16.0, $c->gating->threshold());
		self::assertFalse($c->matrixCache);
		self::assertSame(8, $c->matrixCacheSize);
		self::assertSame(1e-3, $c->dtQuantum);
		self::assertSame(7, $c->modelBreakThreshold);
		self::assertEquals($c, FilterConfig::fromArray($c->toArray()));
	}
}
