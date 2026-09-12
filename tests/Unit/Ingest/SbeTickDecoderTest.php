<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Ingest;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Infrastructure\Ingest\JsonTickDecoder;
use OpenCCK\Kalman\Infrastructure\Ingest\SbeTickDecoder;
use OpenCCK\Kalman\Infrastructure\Ingest\SbeTickEncoder;
use PHPUnit\Framework\TestCase;

final class SbeTickDecoderTest extends TestCase
{
	public function testRoundTripIsExact(): void
	{
		$ts = 1_700_000_000_123_456_789;
		$values = [0 => 100.25, 3 => -1.0e-9, 7 => 12345.678901234567];
		$frame = SbeTickEncoder::encode($ts, $values);
		self::assertSame(SbeTickDecoder::HEADER_LENGTH + 3 * SbeTickDecoder::ENTRY_LENGTH, \strlen($frame));

		$m = (new SbeTickDecoder())->decode($frame, 42);
		self::assertNotNull($m);
		self::assertSame($ts, $m->timestampNs);
		self::assertSame($values, $m->values);
		self::assertSame(42, $m->receivedNs);
	}

	public function testMatchesJsonDecoder(): void
	{
		$ts = 1_690_000_000_000_000_001;
		$values = [1 => 3.5, 0 => 2.25];
		$json = \json_encode(['ts' => $ts, 'values' => $values], \JSON_THROW_ON_ERROR);
		$a = (new JsonTickDecoder())->decode($json);
		$b = (new SbeTickDecoder())->decode(SbeTickEncoder::encode($ts, $values));
		self::assertNotNull($a);
		self::assertNotNull($b);
		self::assertSame($a->timestampNs, $b->timestampNs);
		self::assertSame($a->values, $b->values);
	}

	public function testBlindTickAndServiceMessages(): void
	{
		$decoder = new SbeTickDecoder();
		$blind = $decoder->decode(SbeTickEncoder::encode(5_000, []));
		self::assertNotNull($blind);
		self::assertTrue($blind->isBlind());
		self::assertSame(5_000, $blind->timestampNs);

		self::assertNull($decoder->decode(SbeTickEncoder::service(2)));
	}

	public function testChannelMap(): void
	{
		$decoder = new SbeTickDecoder([10 => 0, 11 => 1]);
		$m = $decoder->decode(SbeTickEncoder::encode(1, [10 => 1.0, 11 => 2.0, 12 => 3.0]));
		self::assertNotNull($m);
		self::assertSame([0 => 1.0, 1 => 2.0], $m->values);
	}

	public function testTruncatedFrameIsRejected(): void
	{
		$frame = SbeTickEncoder::encode(1, [0 => 1.0, 1 => 2.0]);
		$this->expectException(InvalidArgument::class);
		(new SbeTickDecoder())->decode(\substr($frame, 0, \strlen($frame) - 3));
	}

	public function testForeignSchemaIsRejected(): void
	{
		$frame = \pack('vvvvPvv', 8, 1, 99, 0, 1, 10, 0);
		$this->expectException(InvalidArgument::class);
		(new SbeTickDecoder())->decode($frame);
	}
}
