<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use OpenCCK\Kalman\Domain\Entity\Measurement;

/**
 * Exchange payload → Measurement. The ONLY place where exchange JSON is
 * parsed (json_decode with JSON_THROW_ON_ERROR). Returns null for service
 * messages (heartbeats, subscription acks). MUST extract the exchange
 * timestamp from the payload — never substitute local time.
 */
interface MessageDecoder
{
	public function decode(string $payload, ?int $receivedNs = null): ?Measurement;
}
