<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Examples\Decoders;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Infrastructure\Ingest\MessageDecoder;

/**
 * Coinbase Exchange `ticker` channel:
 *   {"type":"ticker","product_id":"BTC-USD","price":"42000.1","best_bid":"41999.9","best_ask":"42000.3",
 *    "time":"2024-01-05T12:34:56.789012Z", ...}
 *
 * Uses the mid of best bid/ask as the quote and the ISO-8601 "time" field
 * (microsecond precision) as exchange time.
 */
final class CoinbaseTickerDecoder implements MessageDecoder
{
	/** @param array<string, int> $channels product_id => channel */
	public function __construct(private readonly array $channels, private readonly bool $useMid = true)
	{
	}

	public function decode(string $payload, ?int $receivedNs = null): ?Measurement
	{
		/** @var mixed $data */
		$data = \json_decode($payload, true, 4, \JSON_THROW_ON_ERROR);
		if (!\is_array($data) || ($data['type'] ?? null) !== 'ticker' || !isset($data['product_id'], $data['time'])) {
			return null;
		}
		$product = (string) $data['product_id'];
		if (!isset($this->channels[$product])) {
			return null;
		}
		if ($this->useMid && isset($data['best_bid'], $data['best_ask']) && \is_numeric($data['best_bid']) && \is_numeric($data['best_ask'])) {
			$price = 0.5 * ((float) $data['best_bid'] + (float) $data['best_ask']);
		} elseif (isset($data['price']) && \is_numeric($data['price'])) {
			$price = (float) $data['price'];
		} else {
			return null;
		}
		$ts = self::isoToNs((string) $data['time']);
		if ($ts === null) {
			return null;
		}
		return Measurement::at($ts, [$this->channels[$product] => $price], $receivedNs);
	}

	/** "2024-01-05T12:34:56.789012Z" → epoch nanoseconds. */
	public static function isoToNs(string $iso): ?int
	{
		if (\preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?Z$/', $iso, $m) !== 1) {
			return null;
		}
		$seconds = \strtotime($m[1] . 'Z');
		if ($seconds === false) {
			return null;
		}
		$frac = \str_pad($m[2] ?? '0', 9, '0');
		return $seconds * 1_000_000_000 + (int) $frac;
	}

	/** @param array<int, string> $products */
	public static function subscribe(array $products): string
	{
		return \json_encode(['type' => 'subscribe', 'product_ids' => \array_values($products), 'channels' => ['ticker']], \JSON_THROW_ON_ERROR);
	}
}
