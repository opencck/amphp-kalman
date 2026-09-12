<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * §6.4 binary MessageDecoder in the Simple Binary Encoding (SBE) wire
 * layout — one unpack() per message and no intermediate objects.
 *
 * Message (little-endian, schema id 1, template id 1 "Tick"):
 *
 *   messageHeader   blockLength u16 = 8 | templateId u16 = 1 | schemaId u16 = 1 | version u16 = 0
 *   root block      ts i64  (exchange time, ns since epoch)
 *   group "values"  groupSizeEncoding: blockLength u16 = 10 | numInGroup u16
 *   group entries   channel u16 | value f64   (× numInGroup)
 *
 * Messages of another template (heartbeats, acks) yield null; a different
 * schema id or a truncated frame throws InvalidArgument. A short frame with
 * numInGroup = 0 is a blind tick (prediction only).
 *
 * See SbeTickEncoder for the matching writer (mock exchanges, replay files).
 */
final class SbeTickDecoder implements MessageDecoder
{
	public const SCHEMA_ID = 1;
	public const TEMPLATE_TICK = 1;
	public const HEADER_LENGTH = 20; // 8 (message header) + 8 (ts) + 4 (group header)
	public const ENTRY_LENGTH = 10;  // u16 + f64

	/** @var array<int, string> unpack() format per group size */
	private array $formats = [];

	/**
	 * @param array<int, int> $channelMap source channel => filter channel (identity when empty)
	 */
	public function __construct(private readonly array $channelMap = [])
	{
	}

	public function decode(string $payload, ?int $receivedNs = null): ?Measurement
	{
		$length = \strlen($payload);
		if ($length < self::HEADER_LENGTH) {
			throw new InvalidArgument(\sprintf('SBE frame too short: %d bytes', $length));
		}
		/** @var array{block: int, template: int, schema: int, version: int, ts: int, gblock: int, count: int} $head */
		$head = \unpack('vblock/vtemplate/vschema/vversion/Pts/vgblock/vcount', $payload);
		if ($head['schema'] !== self::SCHEMA_ID) {
			throw new InvalidArgument(\sprintf('Unexpected SBE schema id %d', $head['schema']));
		}
		if ($head['template'] !== self::TEMPLATE_TICK) {
			return null;
		}
		$count = $head['count'];
		if ($count === 0) {
			return Measurement::blind($head['ts']);
		}
		$expected = self::HEADER_LENGTH + $count * $head['gblock'];
		if ($length < $expected || $head['gblock'] < self::ENTRY_LENGTH) {
			throw new InvalidArgument(\sprintf('SBE frame truncated: %d bytes, %d expected', $length, $expected));
		}
		$format = $this->formats[$count] ??= self::format($count);
		/** @var array<string, int|float> $body */
		$body = \unpack($format, $payload, self::HEADER_LENGTH);
		$values = [];
		$map = $this->channelMap;
		for ($i = 0; $i < $count; $i++) {
			$channel = (int) $body["c$i"];
			if ($map !== []) {
				if (!isset($map[$channel])) {
					continue;
				}
				$channel = $map[$channel];
			}
			$values[$channel] = (float) $body["v$i"];
		}
		return Measurement::at($head['ts'], $values, $receivedNs);
	}

	private static function format(int $count): string
	{
		$parts = [];
		for ($i = 0; $i < $count; $i++) {
			$parts[] = "vc$i";
			$parts[] = "ev$i";
		}
		return \implode('/', $parts);
	}
}
