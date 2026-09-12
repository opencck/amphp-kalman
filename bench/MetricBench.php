<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench;

use OpenCCK\Kalman\Bench\Support\Benchmark;
use OpenCCK\Kalman\Bench\Support\Timer;
use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Metric\Liquidity\LiquidityDensity;
use OpenCCK\Kalman\Domain\Metric\Liquidity\OrderBookImbalance;
use OpenCCK\Kalman\Domain\Metric\Microstructure\OrderFlowImbalance;
use OpenCCK\Kalman\Domain\Metric\Microstructure\Vpin;
use OpenCCK\Kalman\Domain\Metric\Momentum\Cci;
use OpenCCK\Kalman\Domain\Metric\Momentum\Rsi;
use OpenCCK\Kalman\Domain\Metric\Momentum\Stochastic;
use OpenCCK\Kalman\Domain\Metric\Trend\Adx;
use OpenCCK\Kalman\Domain\Metric\Trend\Macd;
use OpenCCK\Kalman\Domain\Metric\Trend\MovingAverage;
use OpenCCK\Kalman\Domain\Metric\Volatility\Atr;
use OpenCCK\Kalman\Domain\Metric\Volatility\OhlcVolatility;
use OpenCCK\Kalman\Domain\Metric\Volume\Vwap;

/**
 * Cost of one metric update: microseconds per observation, and the allocation
 * delta over 100 000 updates, which must be 0 (BRIEF §2 p.13, ROADMAP §9).
 *
 * The allocation figure is the one that matters for a live session. A metric
 * that allocates per tick drags the garbage collector into the filter's path,
 * and a GC pause during a burst is exactly when it can least be afforded. Every
 * buffer a metric needs is therefore sized in its constructor.
 *
 * Book and bar metrics are measured against a pre-built snapshot: constructing
 * an `OrderBook` allocates by nature, and that cost belongs to the decoder, not
 * to the metric.
 */
final class MetricBench implements Benchmark
{
	private const STEPS = 100_000;

	private const ALLOC_STEPS = 100_000;

	public function name(): string
	{
		return 'metric';
	}

	public function run(): array
	{
		$out = [];

		// ---------------------------------------------------------- price metrics
		$price = static fn (int $i): float => 100.0 + \sin($i * 0.01) * 5.0;

		foreach ([
			'ema' => new MovingAverage(20),
			'sma' => new MovingAverage(20, \OpenCCK\Kalman\Domain\Metric\Trend\MaType::Sma),
			'rsi' => new Rsi(14),
			'macd' => new Macd(),
		] as $label => $metric) {
			$out[$label . '_us'] = Timer::microsPerOp(
				static function (int $iterations) use ($metric, $price): mixed {
					$ts = 0;
					for ($i = 0; $i < $iterations; $i++) {
						$ts += 1_000_000;
						$metric->updatePrice($ts, $price($i));
					}
					return $metric->value();
				},
				self::STEPS,
			);
			$out[$label . '_bytes'] = (float) Timer::allocationDelta(
				static function () use ($metric, $price): void {
					$ts = 0;
					for ($i = 0; $i < self::ALLOC_STEPS; $i++) {
						$ts += 1_000_000;
						$metric->updatePrice($ts, $price($i));
					}
				},
			);
		}

		// ------------------------------------------------------------ bar metrics
		$bars = [];
		for ($i = 0; $i < 256; $i++) {
			$mid = 100.0 + \sin($i * 0.05) * 3.0;
			$bars[] = Bar::of(
				$i * 60_000_000_000,
				$i * 60_000_000_000 + 59_999_999_999,
				$mid,
				$mid + 0.4,
				$mid - 0.4,
				$mid + 0.1,
				100.0,
				10,
			);
		}

		foreach ([
			'atr' => new Atr(14),
			'adx' => new Adx(14),
			'cci' => new Cci(20),
			'stochastic' => Stochastic::slow(),
			'ohlc_volatility' => new OhlcVolatility(20),
		] as $label => $metric) {
			$out[$label . '_us'] = Timer::microsPerOp(
				static function (int $iterations) use ($metric, $bars): mixed {
					$n = \count($bars);
					for ($i = 0; $i < $iterations; $i++) {
						$metric->updateBar($bars[$i % $n]);
					}
					return $metric->value();
				},
				self::STEPS,
			);
			$out[$label . '_bytes'] = (float) Timer::allocationDelta(
				static function () use ($metric, $bars): void {
					$n = \count($bars);
					for ($i = 0; $i < self::ALLOC_STEPS; $i++) {
						$metric->updateBar($bars[$i % $n]);
					}
				},
			);
		}

		// ----------------------------------------------------------- book metrics
		$books = [];
		for ($i = 0; $i < 64; $i++) {
			$mid = 100.0 + \sin($i * 0.1) * 0.5;
			$bids = [];
			$asks = [];
			for ($level = 1; $level <= 20; $level++) {
				$bids[] = [$mid - 0.01 * $level, (float) $level];
				$asks[] = [$mid + 0.01 * $level, (float) $level];
			}
			$books[] = OrderBook::of($i * 1_000_000, $bids, $asks);
		}

		foreach ([
			'obi' => new OrderBookImbalance(5),
			'liquidity_density' => new LiquidityDensity(10.0),
			'ofi' => new OrderFlowImbalance(50),
		] as $label => $metric) {
			$out[$label . '_us'] = Timer::microsPerOp(
				static function (int $iterations) use ($metric, $books): mixed {
					$n = \count($books);
					for ($i = 0; $i < $iterations; $i++) {
						$metric->updateBook($books[$i % $n]);
					}
					return $metric->value();
				},
				self::STEPS,
			);
			$out[$label . '_bytes'] = (float) Timer::allocationDelta(
				static function () use ($metric, $books): void {
					$n = \count($books);
					for ($i = 0; $i < self::ALLOC_STEPS; $i++) {
						$metric->updateBook($books[$i % $n]);
					}
				},
			);
		}

		// ---------------------------------------------------------- trade metrics
		$trades = [];
		for ($i = 0; $i < 256; $i++) {
			$trades[] = Trade::at(
				$i * 1_000_000,
				100.0 + \sin($i * 0.02),
				1.0 + ($i % 7) * 0.1,
				$i % 3 === 0 ? TradeSide::Sell : TradeSide::Buy,
			);
		}

		foreach ([
			'vwap' => Vwap::rolling(256),
			'vpin' => new Vpin(100.0, 50),
		] as $label => $metric) {
			$out[$label . '_us'] = Timer::microsPerOp(
				static function (int $iterations) use ($metric, $trades): mixed {
					$n = \count($trades);
					for ($i = 0; $i < $iterations; $i++) {
						$metric->updateTrade($trades[$i % $n]);
					}
					return $metric->value();
				},
				self::STEPS,
			);
			$out[$label . '_bytes'] = (float) Timer::allocationDelta(
				static function () use ($metric, $trades): void {
					$n = \count($trades);
					for ($i = 0; $i < self::ALLOC_STEPS; $i++) {
						$metric->updateTrade($trades[$i % $n]);
					}
				},
			);
		}

		return $out;
	}
}
