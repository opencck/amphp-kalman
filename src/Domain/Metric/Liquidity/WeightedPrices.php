<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Liquidity;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BookMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;

/**
 * Volume-weighted prices of the two sides, and the weighted mid between them
 * (ROADMAP §4.5 M-19, the reference WABP and WAAP).
 *
 *   WABP = Σ p·v / Σ v over bids          WAAP = the same over asks
 *   WM   = (P_ask·V_bid + P_bid·V_ask) / (V_bid + V_ask)
 *
 * The two measure different things, and the reference uses the less useful
 * one. A side average taken over the whole book describes the *geometry* of
 * resting orders — mostly of levels that will never trade — and moves when
 * someone posts size far away, which is not news.
 *
 * The weighted mid is the microstructure standard, and note that its weights
 * are **crossed**: the bid size multiplies the ask price. That is not a typo
 * and it is the entire content of the measure — size resting on the bid is
 * buying pressure, so it pushes fair value up towards the ask. It predicts the
 * next mid change better than the arithmetic mid, which is why it is the
 * starting point of the `Microprice` model, where the same quantity is
 * filtered rather than read raw.
 */
final class WeightedPrices implements BookMetric
{
	private float $bid = \NAN;

	private float $ask = \NAN;

	private float $weightedMid = \NAN;

	private float $mid = \NAN;

	public function __construct(public readonly int $levels = 5)
	{
		if ($levels < 0) {
			throw new InvalidArgument('WeightedPrices levels must be >= 0 (0 means the whole side)');
		}
	}

	public static function type(): string
	{
		return 'weighted-prices';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-19',
			symbol: 'WABP/WAAP',
			category: MetricCategory::Liquidity,
			inputs: [MetricInput::Book],
			kernels: ['WeightedPrices::side()', 'WeightedPrices::weightedMid()', 'WeightedPrices::all()'],
			nameEn: 'Side-weighted prices',
			nameRu: 'Взвешенные цены сторон',
			algoEn: 'The weighted mid is short-horizon fair value and predicts the next mid change; whole-side averages describe book geometry, not execution.',
			algoRu: 'Взвешенная середина — справедливая цена на коротком горизонте, она предсказывает следующее изменение середины; средние по всей стороне описывают геометрию стакана, а не исполнение.',
			plainEn: 'Volume-weighted average price of each side, and the size-weighted mid between the best quotes.',
			plainRu: 'Взвешенная объёмом средняя цена каждой стороны и взвешенная размерами середина между лучшими котировками.',
			example: 'examples/liquidity-weighted-prices.php',
		);
	}

	public function updateBook(OrderBook $book): void
	{
		if ($book->isEmpty()) {
			$this->bid = \NAN;
			$this->ask = \NAN;
			$this->weightedMid = \NAN;
			$this->mid = \NAN;
			return;
		}
		$this->bid = self::side($book->bidPrices, $book->bidSizes, $this->levels);
		$this->ask = self::side($book->askPrices, $book->askSizes, $this->levels);
		$this->weightedMid = $book->weightedMid();
		$this->mid = $book->mid();
	}

	public function isReady(): bool
	{
		return !\is_nan($this->weightedMid);
	}

	/** The weighted mid — the useful one. */
	public function value(): float
	{
		return $this->weightedMid;
	}

	/** @return array{weightedMid: float, bid: float, ask: float, mid: float, tilt: float} */
	public function values(): array
	{
		return [
			'weightedMid' => $this->weightedMid,
			'bid' => $this->bid,
			'ask' => $this->ask,
			'mid' => $this->mid,
			'tilt' => \is_nan($this->weightedMid) || !($this->mid > 0.0)
				? \NAN
				: ($this->weightedMid - $this->mid) / $this->mid * 10000.0,
		];
	}

	public function reset(): void
	{
		$this->bid = \NAN;
		$this->ask = \NAN;
		$this->weightedMid = \NAN;
		$this->mid = \NAN;
	}

	/** @return array{type: string, levels: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'levels' => $this->levels];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(isset($config['levels']) && \is_int($config['levels']) ? $config['levels'] : 5);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Volume-weighted average price of one side.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 */
	public static function side(array $prices, array $sizes, int $levels = 5): float
	{
		$n = \count($prices);
		if (\count($sizes) !== $n) {
			throw new InvalidArgument('WeightedPrices needs prices and sizes of equal length');
		}
		if ($levels > 0 && $levels < $n) {
			$n = $levels;
		}
		$weighted = 0.0;
		$volume = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$weighted += $prices[$i] * $sizes[$i];
			$volume += $sizes[$i];
		}
		return $volume > 0.0 ? $weighted / $volume : \NAN;
	}

	/**
	 * Size-weighted mid of the best quotes, with the weights crossed.
	 *
	 * @deterministic
	 * @offloadable
	 */
	public static function weightedMid(float $bidPrice, float $bidSize, float $askPrice, float $askSize): float
	{
		$total = $bidSize + $askSize;
		if (!($total > 0.0)) {
			return ($bidPrice + $askPrice) / 2.0;
		}
		return ($askPrice * $bidSize + $bidPrice * $askSize) / $total;
	}

	/**
	 * Every output for one snapshot.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 * @return array{bid: float, ask: float, mid: float, weightedMid: float, tiltBps: float}
	 */
	public static function all(array $bidPrices, array $bidSizes, array $askPrices, array $askSizes, int $levels = 5): array
	{
		if ($bidPrices === [] || $askPrices === []) {
			return ['bid' => \NAN, 'ask' => \NAN, 'mid' => \NAN, 'weightedMid' => \NAN, 'tiltBps' => \NAN];
		}
		$mid = ($bidPrices[0] + $askPrices[0]) / 2.0;
		$weightedMid = self::weightedMid($bidPrices[0], $bidSizes[0], $askPrices[0], $askSizes[0]);
		return [
			'bid' => self::side($bidPrices, $bidSizes, $levels),
			'ask' => self::side($askPrices, $askSizes, $levels),
			'mid' => $mid,
			'weightedMid' => $weightedMid,
			'tiltBps' => $mid > 0.0 ? ($weightedMid - $mid) / $mid * 10000.0 : \NAN,
		];
	}
}
