<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Bench\Support\Timer;
use OpenCCK\Kalman\Examples\Decoders\BinanceTradeDecoder;
use OpenCCK\Kalman\Infrastructure\Ingest\JsonTickDecoder;
use OpenCCK\Kalman\Infrastructure\Ingest\SbeTickDecoder;
use OpenCCK\Kalman\Infrastructure\Ingest\SbeTickEncoder;

/**
 * §6.4 / §8 decode cost per message: the library's compact JSON tick format,
 * the SBE binary layout (1 and 8 channels) and a real exchange payload
 * (Binance trade event: nested object, string-encoded numbers). The exchange
 * JSON is what §6.4 warns about; the compact formats are close to the cost of
 * building the Measurement itself. Metric unit: µs per message.
 */
final class DecoderBench implements Benchmark
{
	public function name(): string
	{
		return 'decoder';
	}

	public function run(): array
	{
		$out = [];
		foreach ([1, 8] as $m) {
			$values = [];
			for ($c = 0; $c < $m; $c++) {
				$values[$c] = 100.0 + 0.001 * $c;
			}
			$ts = 1_700_000_000_123_456_789;
			$json = \json_encode(['ts' => $ts, 'values' => $values], \JSON_THROW_ON_ERROR);
			$sbe = SbeTickEncoder::encode($ts, $values);
			$jsonDecoder = new JsonTickDecoder();
			$sbeDecoder = new SbeTickDecoder();
			$out["json_m{$m}_us"] = Timer::microsPerOp(static function (int $it) use ($jsonDecoder, $json): void {
				for ($i = 0; $i < $it; $i++) {
					$jsonDecoder->decode($json);
				}
			}, 100_000);
			$out["sbe_m{$m}_us"] = Timer::microsPerOp(static function (int $it) use ($sbeDecoder, $sbe): void {
				for ($i = 0; $i < $it; $i++) {
					$sbeDecoder->decode($sbe);
				}
			}, 100_000);
			$out["json_m{$m}_len"] = (float) \strlen($json);
			$out["sbe_m{$m}_len"] = (float) \strlen($sbe);
		}

		// a real exchange event (combined-stream wrapper, string numbers, ~180 bytes)
		$binance = '{"stream":"btcusdt@trade","data":{"e":"trade","E":1700000000123,"s":"BTCUSDT","t":3456789012,"p":"36123.45000000","q":"0.00250000","b":88,"a":50,"T":1700000000120,"m":true,"M":true}}';
		$decoder = new BinanceTradeDecoder(['BTCUSDT' => 0]);
		$out['binance_trade_us'] = Timer::microsPerOp(static function (int $it) use ($decoder, $binance): void {
			for ($i = 0; $i < $it; $i++) {
				$decoder->decode($binance);
			}
		}, 100_000);
		$out['binance_trade_len'] = (float) \strlen($binance);
		return $out;
	}
}
