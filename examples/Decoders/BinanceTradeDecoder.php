<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Examples\Decoders;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Infrastructure\Ingest\MessageDecoder;

/**
 * Binance spot `<symbol>@trade` stream:
 *   {"e":"trade","E":1672515782136,"s":"BNBBTC","t":12345,"p":"0.001","q":"100","T":1672515782136,"m":true,"M":true}
 *
 * Exchange time = "T" (trade time, ms) → ns. Symbols map to filter channels.
 * Exchange formats change — this lives in examples/, not src/.
 */
final class BinanceTradeDecoder implements MessageDecoder
{
	/** @param array<string, int> $channels symbol => channel */
	public function __construct(private readonly array $channels)
	{
	}

	public function decode(string $payload, ?int $receivedNs = null): ?Measurement
	{
		/** @var mixed $data */
		$data = \json_decode($payload, true, 4, \JSON_THROW_ON_ERROR);
		if (!\is_array($data)) {
			return null;
		}
		// combined streams wrap the event: {"stream":"bnbbtc@trade","data":{...}}
		if (isset($data['data']) && \is_array($data['data'])) {
			$data = $data['data'];
		}
		if (($data['e'] ?? null) !== 'trade' || !isset($data['s'], $data['p'], $data['T'])) {
			return null;
		}
		$symbol = (string) $data['s'];
		if (!isset($this->channels[$symbol]) || !\is_numeric($data['p']) || !\is_numeric($data['T'])) {
			return null;
		}
		return Measurement::at((int) $data['T'] * 1_000_000, [$this->channels[$symbol] => (float) $data['p']], $receivedNs);
	}

	/**
	 * Subscription message for the given symbols (lower-case stream names).
	 *
	 * @param array<int, string> $symbols
	 */
	public static function subscribe(array $symbols, int $id = 1): string
	{
		$params = [];
		foreach ($symbols as $s) {
			$params[] = \strtolower((string) $s) . '@trade';
		}
		return \json_encode(['method' => 'SUBSCRIBE', 'params' => $params, 'id' => $id], \JSON_THROW_ON_ERROR);
	}
}
