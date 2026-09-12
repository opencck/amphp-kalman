<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Entity;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use PHPUnit\Framework\TestCase;

final class MeasurementTest extends TestCase
{
	public function testAtNormalisesValuesToFloat(): void
	{
		$m = Measurement::at(1_000, [0 => 1, 2 => 2.5], 1_500);
		self::assertSame([0 => 1.0, 2 => 2.5], $m->values);
		self::assertSame(1_000, $m->timestampNs);
		self::assertSame(500, $m->latencyNs());
		self::assertTrue($m->has(2));
		self::assertFalse($m->has(1));
		self::assertSame([0, 2], $m->channels());
		self::assertSame(2, $m->channelCount());
		self::assertSame(2.5, $m->value(2));
		self::assertFalse($m->isBlind());
	}

	public function testBlind(): void
	{
		$b = Measurement::blind(42);
		self::assertTrue($b->isBlind());
		self::assertSame([], $b->values);
		self::assertNull($b->latencyNs());
	}

	public function testRejectsNonFinite(): void
	{
		$this->expectException(InvalidArgument::class);
		Measurement::at(1, [0 => \NAN]);
	}

	public function testRejectsNegativeChannel(): void
	{
		$this->expectException(InvalidArgument::class);
		Measurement::at(1, [-1 => 1.0]);
	}

	public function testMissingChannelValueThrows(): void
	{
		$this->expectException(InvalidArgument::class);
		Measurement::at(1, [0 => 1.0])->value(3);
	}

	public function testArrayRoundTrip(): void
	{
		$m = Measurement::at(7, [1 => 3.0], 9);
		self::assertEquals($m, Measurement::fromArray($m->toArray()));
		$m2 = $m->withTimestamp(8);
		self::assertSame(8, $m2->timestampNs);
		self::assertSame($m->values, $m2->values);
	}
}
