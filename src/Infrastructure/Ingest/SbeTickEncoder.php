<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Writer side of SbeTickDecoder's wire format (mock exchanges, replay files,
 * internal buses). Pure and allocation-light: one pack() per entry.
 */
final class SbeTickEncoder
{
	private function __construct()
	{
	}

	/**
	 * @param array<int, float|int> $values channel => value
	 */
	public static function encode(int $timestampNs, array $values): string
	{
		$count = \count($values);
		if ($count > 0xFFFF) {
			throw new InvalidArgument('At most 65535 channels per SBE tick');
		}
		if ($timestampNs < 0) {
			throw new InvalidArgument('timestamp must be >= 0');
		}
		$out = \pack('vvvvPvv', 8, SbeTickDecoder::TEMPLATE_TICK, SbeTickDecoder::SCHEMA_ID, 0, $timestampNs, SbeTickDecoder::ENTRY_LENGTH, $count);
		foreach ($values as $channel => $value) {
			if ($channel < 0 || $channel > 0xFFFF) {
				throw new InvalidArgument(\sprintf('Channel %d out of the u16 range', $channel));
			}
			$out .= \pack('ve', $channel, (float) $value);
		}
		return $out;
	}

	/** A non-tick message (e.g. heartbeat) with the given template id; decoders return null for it. */
	public static function service(int $templateId): string
	{
		if ($templateId === SbeTickDecoder::TEMPLATE_TICK) {
			throw new InvalidArgument('template 1 is the tick template');
		}
		return \pack('vvvvPvv', 8, $templateId, SbeTickDecoder::SCHEMA_ID, 0, 0, SbeTickDecoder::ENTRY_LENGTH, 0);
	}
}
