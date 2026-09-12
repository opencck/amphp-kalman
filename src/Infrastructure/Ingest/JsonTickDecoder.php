<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Decoder for the library's own JSON tick format (mock exchanges, replay,
 * internal buses): {"ts": <ns>, "values": {"<channel>": <value>, …}}.
 * Messages without "ts"/"values" (heartbeats, acks) yield null.
 * Optionally remaps source channel ids to filter channel ids.
 *
 * Decoded JSON is untyped by definition; every field is validated below, so the
 * intermediate mixed assignments are intentional.
 *
 * @psalm-suppress MixedAssignment
 */
final class JsonTickDecoder implements MessageDecoder
{
	/**
	 * @param array<int, int> $channelMap source channel => filter channel (identity when empty)
	 */
	public function __construct(private readonly array $channelMap = [])
	{
	}

	public function decode(string $payload, ?int $receivedNs = null): ?Measurement
	{
		/** @var mixed $data */
		$data = \json_decode($payload, true, 8, \JSON_THROW_ON_ERROR);
		if (!\is_array($data) || !isset($data['ts'], $data['values']) || !\is_array($data['values'])) {
			return null;
		}
		$ts = $data['ts'];
		if (!\is_int($ts)) {
			if (\is_string($ts) && \preg_match('/^\d+$/', $ts) === 1) {
				$ts = (int) $ts;
			} else {
				throw new InvalidArgument('Tick "ts" must be integer nanoseconds');
			}
		}
		$values = [];
		foreach ($data['values'] as $ch => $v) {
			if (!\is_numeric($v)) {
				continue;
			}
			$channel = (int) $ch;
			if ($this->channelMap !== []) {
				if (!isset($this->channelMap[$channel])) {
					continue;
				}
				$channel = $this->channelMap[$channel];
			}
			$values[$channel] = (float) $v;
		}
		if ($values === []) {
			return null;
		}
		return Measurement::at($ts, $values, $receivedNs);
	}
}
