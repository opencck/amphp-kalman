<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Liquidity;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BookMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;

/**
 * What a market order of a given size would actually cost (ROADMAP §4.5
 * M-17).
 *
 * Walking the book level by level until the order is filled gives the average
 * execution price; the gap between that and the mid is the slippage:
 *
 *   slippage_bps = (avgFill − mid) / mid · 10000     for a buy
 *
 * This is the one liquidity number a bot can act on without translation.
 * Imbalance, density and slope all describe the book; this one answers the
 * question actually being asked before an order goes out — *what will this
 * cost me*. It is also the honest way to size: a signal worth four basis
 * points is not tradable in a book where clearing your size costs six.
 *
 * When the book is too thin to fill the request, the metric reports the
 * fillable fraction and a NAN cost rather than pretending the remainder
 * executes at the last level — an unfillable order is a different event from
 * an expensive one, and a strategy must be able to tell them apart.
 */
final class CostToTrade implements BookMetric
{
	private float $buySlippageBps = \NAN;

	private float $sellSlippageBps = \NAN;

	private float $buyFilled = \NAN;

	private float $sellFilled = \NAN;

	public function __construct(public readonly float $notional = 10000.0)
	{
		if (!($notional > 0.0) || !\is_finite($notional)) {
			throw new InvalidArgument('CostToTrade notional must be finite and > 0');
		}
	}

	public static function type(): string
	{
		return 'cost-to-trade';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-36',
			symbol: 'CTT',
			category: MetricCategory::Liquidity,
			inputs: [MetricInput::Book],
			kernels: ['CostToTrade::forNotional()', 'CostToTrade::forSize()', 'CostToTrade::bothSides()'],
			nameEn: 'Cost to trade',
			nameRu: 'Стоимость исполнения',
			algoEn: 'Answers the question asked before every market order: what will this cost. A signal worth four basis points is not tradable where clearing the size costs six.',
			algoRu: 'Отвечает на вопрос, который задают перед каждой рыночной заявкой: во сколько это обойдётся. Сигнал в четыре базисных пункта не торгуется там, где исполнение объёма стоит шесть.',
			plainEn: 'Average execution price from walking the book for a given size, expressed as slippage from the mid in basis points.',
			plainRu: 'Средняя цена исполнения при проходе по стакану заданным объёмом, выраженная как проскальзывание от середины спреда в базисных пунктах.',
			example: 'examples/liquidity-cost-to-trade.php',
		);
	}

	public function updateBook(OrderBook $book): void
	{
		if ($book->isEmpty()) {
			$this->buySlippageBps = \NAN;
			$this->sellSlippageBps = \NAN;
			$this->buyFilled = \NAN;
			$this->sellFilled = \NAN;
			return;
		}
		$mid = $book->mid();
		$buy = self::walk($book->askPrices, $book->askSizes, $mid, $this->notional, true);
		$sell = self::walk($book->bidPrices, $book->bidSizes, $mid, $this->notional, false);
		$this->buySlippageBps = $buy['slippageBps'];
		$this->sellSlippageBps = $sell['slippageBps'];
		$this->buyFilled = $buy['filled'];
		$this->sellFilled = $sell['filled'];
	}

	public function isReady(): bool
	{
		return !\is_nan($this->buyFilled) && !\is_nan($this->sellFilled);
	}

	/** Round-trip cost: buy slippage plus sell slippage, in basis points. */
	public function value(): float
	{
		return $this->buySlippageBps + $this->sellSlippageBps;
	}

	/** @return array{roundTripBps: float, buyBps: float, sellBps: float, buyFilled: float, sellFilled: float} */
	public function values(): array
	{
		return [
			'roundTripBps' => $this->value(),
			'buyBps' => $this->buySlippageBps,
			'sellBps' => $this->sellSlippageBps,
			'buyFilled' => $this->buyFilled,
			'sellFilled' => $this->sellFilled,
		];
	}

	public function reset(): void
	{
		$this->buySlippageBps = \NAN;
		$this->sellSlippageBps = \NAN;
		$this->buyFilled = \NAN;
		$this->sellFilled = \NAN;
	}

	/** @return array{type: string, notional: float} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'notional' => $this->notional];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['notional']) && (\is_float($config['notional']) || \is_int($config['notional']))
				? (float) $config['notional']
				: 10000.0,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Slippage in basis points for a buy (walking the asks) or a sell (walking
	 * the bids) of the given notional.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 */
	public static function forNotional(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		float $notional,
		bool $isBuy = true,
	): float {
		if ($bidPrices === [] || $askPrices === []) {
			return \NAN;
		}
		$mid = ($bidPrices[0] + $askPrices[0]) / 2.0;
		$result = $isBuy
			? self::walk($askPrices, $askSizes, $mid, $notional, true)
			: self::walk($bidPrices, $bidSizes, $mid, $notional, false);
		return $result['slippageBps'];
	}

	/**
	 * The same in base units instead of quote currency.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices the side being lifted, best first
	 * @param list<float> $sizes
	 * @return array{avgPrice: float, slippageBps: float, filled: float, levels: int}
	 */
	public static function forSize(array $prices, array $sizes, float $mid, float $size, bool $isBuy = true): array
	{
		return self::walkSize($prices, $sizes, $mid, $size, $isBuy);
	}

	/**
	 * Both directions and the round trip.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 * @return array{buyBps: float, sellBps: float, roundTripBps: float, buyFilled: float, sellFilled: float}
	 */
	public static function bothSides(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		float $notional,
	): array {
		if ($bidPrices === [] || $askPrices === []) {
			return ['buyBps' => \NAN, 'sellBps' => \NAN, 'roundTripBps' => \NAN, 'buyFilled' => \NAN, 'sellFilled' => \NAN];
		}
		$mid = ($bidPrices[0] + $askPrices[0]) / 2.0;
		$buy = self::walk($askPrices, $askSizes, $mid, $notional, true);
		$sell = self::walk($bidPrices, $bidSizes, $mid, $notional, false);
		return [
			'buyBps' => $buy['slippageBps'],
			'sellBps' => $sell['slippageBps'],
			'roundTripBps' => $buy['slippageBps'] + $sell['slippageBps'],
			'buyFilled' => $buy['filled'],
			'sellFilled' => $sell['filled'],
		];
	}

	/**
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return array{avgPrice: float, slippageBps: float, filled: float, levels: int}
	 */
	private static function walk(array $prices, array $sizes, float $mid, float $notional, bool $isBuy): array
	{
		$n = \count($prices);
		if (\count($sizes) !== $n) {
			throw new InvalidArgument('CostToTrade needs prices and sizes of equal length');
		}
		$remaining = $notional;
		$spent = 0.0;
		$bought = 0.0;
		$levels = 0;
		for ($i = 0; $i < $n && $remaining > 0.0; $i++) {
			$price = $prices[$i];
			$available = $sizes[$i] * $price;
			$take = $remaining < $available ? $remaining : $available;
			$spent += $take;
			$bought += $take / $price;
			$remaining -= $take;
			$levels++;
		}
		$filled = $notional > 0.0 ? ($notional - $remaining) / $notional : \NAN;
		if ($bought <= 0.0 || $remaining > 0.0 || !($mid > 0.0)) {
			return ['avgPrice' => \NAN, 'slippageBps' => \NAN, 'filled' => $filled, 'levels' => $levels];
		}
		$avgPrice = $spent / $bought;
		$slippage = ($isBuy ? $avgPrice - $mid : $mid - $avgPrice) / $mid * 10000.0;
		return ['avgPrice' => $avgPrice, 'slippageBps' => $slippage, 'filled' => $filled, 'levels' => $levels];
	}

	/**
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return array{avgPrice: float, slippageBps: float, filled: float, levels: int}
	 */
	private static function walkSize(array $prices, array $sizes, float $mid, float $size, bool $isBuy): array
	{
		$n = \count($prices);
		if (\count($sizes) !== $n) {
			throw new InvalidArgument('CostToTrade needs prices and sizes of equal length');
		}
		$remaining = $size;
		$spent = 0.0;
		$levels = 0;
		for ($i = 0; $i < $n && $remaining > 0.0; $i++) {
			$take = $remaining < $sizes[$i] ? $remaining : $sizes[$i];
			$spent += $take * $prices[$i];
			$remaining -= $take;
			$levels++;
		}
		$filled = $size > 0.0 ? ($size - $remaining) / $size : \NAN;
		if ($remaining > 0.0 || !($mid > 0.0) || $size <= 0.0) {
			return ['avgPrice' => \NAN, 'slippageBps' => \NAN, 'filled' => $filled, 'levels' => $levels];
		}
		$avgPrice = $spent / $size;
		$slippage = ($isBuy ? $avgPrice - $mid : $mid - $avgPrice) / $mid * 10000.0;
		return ['avgPrice' => $avgPrice, 'slippageBps' => $slippage, 'filled' => $filled, 'levels' => $levels];
	}
}
