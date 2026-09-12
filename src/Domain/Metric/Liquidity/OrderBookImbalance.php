<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Liquidity;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BookMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;

/**
 * Order-book imbalance (ROADMAP §4.5 M-16, and the reference OBP of §4.5
 * M-18, which is the same information in ratio form).
 *
 *   imbalance = (V_bid − V_ask) / (V_bid + V_ask)     in [−1, +1]
 *   ratio     = V_bid / V_ask                          in (0, ∞)
 *   logRatio  = ln(V_bid / V_ask)                      symmetric
 *
 * **Depth is a parameter, and the reference's choice of "all of it" is the
 * problem.** Summing the whole book lets levels far from the touch dominate,
 * and those levels (a) will not trade in any horizon the signal is used on,
 * (b) are where spoofing lives, because quoting there is nearly free, and
 * (c) depend on how many levels the venue publishes — twenty on one feed, two
 * hundred on another — so the same market gives different numbers depending on
 * the subscription. Five or ten levels, the default here, is what the
 * microstructure literature uses and what the reference itself moves to in its
 * Top5 and Top10 variants.
 *
 * `decayBps` weights each level by `exp(−distance / decay)` instead of cutting
 * at a fixed depth, which removes the discontinuity when a level crosses the
 * cut-off and keeps the measure continuous in price.
 *
 * The ratio form is asymmetric — buyers live in [1, ∞) and sellers in (0, 1] —
 * so thresholds on it are lopsided by construction. `logRatio` fixes that, and
 * `imbalance` is bounded, which is why it is the default.
 */
final class OrderBookImbalance implements BookMetric
{
	private float $bidVolume = \NAN;

	private float $askVolume = \NAN;

	public function __construct(
		public readonly int $levels = 5,
		public readonly float $decayBps = 0.0,
	) {
		if ($levels < 0) {
			throw new InvalidArgument('OrderBookImbalance levels must be >= 0 (0 means the whole book)');
		}
		if ($decayBps < 0.0 || !\is_finite($decayBps)) {
			throw new InvalidArgument('OrderBookImbalance decay must be finite and >= 0');
		}
	}

	public static function type(): string
	{
		return 'order-book-imbalance';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-16',
			symbol: 'OBI',
			category: MetricCategory::Liquidity,
			inputs: [MetricInput::Book],
			kernels: [
				'OrderBookImbalance::imbalance()',
				'OrderBookImbalance::ratio()',
				'OrderBookImbalance::logRatio()',
				'OrderBookImbalance::sideVolume()',
			],
			nameEn: 'Order-book imbalance',
			nameRu: 'Дисбаланс стакана',
			algoEn: 'A short-horizon direction predictor. Use the top levels: summing the whole book is dominated by orders that will never execute and by however many levels the venue happens to publish.',
			algoRu: 'Предиктор направления на коротком горизонте. Брать верхние уровни: сумма по всему стакану определяется заявками, которые никогда не исполнятся, и тем, сколько уровней отдаёт площадка.',
			plainEn: 'Normalised excess of bid-side volume over ask-side volume, over a chosen depth or with distance weighting.',
			plainRu: 'Нормированный перевес объёма на стороне покупок над объёмом на стороне продаж, на заданной глубине или со взвешиванием по расстоянию.',
			example: 'examples/liquidity-obi.php',
		);
	}

	public function updateBook(OrderBook $book): void
	{
		if ($book->isEmpty()) {
			$this->bidVolume = \NAN;
			$this->askVolume = \NAN;
			return;
		}
		$mid = $book->mid();
		$this->bidVolume = self::sideVolume($book->bidPrices, $book->bidSizes, $mid, $this->levels, $this->decayBps);
		$this->askVolume = self::sideVolume($book->askPrices, $book->askSizes, $mid, $this->levels, $this->decayBps);
	}

	public function isReady(): bool
	{
		return !\is_nan($this->bidVolume) && !\is_nan($this->askVolume);
	}

	public function value(): float
	{
		return self::normalise($this->bidVolume, $this->askVolume);
	}

	/** @return array{imbalance: float, ratio: float, logRatio: float, bidVolume: float, askVolume: float} */
	public function values(): array
	{
		return [
			'imbalance' => self::normalise($this->bidVolume, $this->askVolume),
			'ratio' => self::asRatio($this->bidVolume, $this->askVolume),
			'logRatio' => self::asLogRatio($this->bidVolume, $this->askVolume),
			'bidVolume' => $this->bidVolume,
			'askVolume' => $this->askVolume,
		];
	}

	public function reset(): void
	{
		$this->bidVolume = \NAN;
		$this->askVolume = \NAN;
	}

	/** @return array{type: string, levels: int, decayBps: float} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'levels' => $this->levels, 'decayBps' => $this->decayBps];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['levels']) && \is_int($config['levels']) ? $config['levels'] : 5,
			isset($config['decayBps']) && (\is_float($config['decayBps']) || \is_int($config['decayBps']))
				? (float) $config['decayBps']
				: 0.0,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * (V_bid − V_ask) / (V_bid + V_ask) for one snapshot.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices descending
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices ascending
	 * @param list<float> $askSizes
	 */
	public static function imbalance(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		int $levels = 5,
		float $decayBps = 0.0,
	): float {
		[$bid, $ask] = self::sides($bidPrices, $bidSizes, $askPrices, $askSizes, $levels, $decayBps);
		return self::normalise($bid, $ask);
	}

	/**
	 * V_bid / V_ask — the reference OBP form.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 */
	public static function ratio(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		int $levels = 5,
		float $decayBps = 0.0,
	): float {
		[$bid, $ask] = self::sides($bidPrices, $bidSizes, $askPrices, $askSizes, $levels, $decayBps);
		return self::asRatio($bid, $ask);
	}

	/**
	 * ln(V_bid / V_ask): the ratio made symmetric around zero, so one threshold
	 * covers both directions.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 */
	public static function logRatio(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		int $levels = 5,
		float $decayBps = 0.0,
	): float {
		[$bid, $ask] = self::sides($bidPrices, $bidSizes, $askPrices, $askSizes, $levels, $decayBps);
		return self::asLogRatio($bid, $ask);
	}

	/**
	 * Volume on one side, cut at `levels` and optionally weighted by distance
	 * from the mid.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 */
	public static function sideVolume(array $prices, array $sizes, float $mid, int $levels = 5, float $decayBps = 0.0): float
	{
		$n = \count($prices);
		if (\count($sizes) !== $n) {
			throw new InvalidArgument('OrderBookImbalance needs prices and sizes of equal length');
		}
		if ($levels > 0 && $levels < $n) {
			$n = $levels;
		}
		if ($n === 0) {
			return 0.0;
		}
		if (!($decayBps > 0.0)) {
			$sum = 0.0;
			for ($i = 0; $i < $n; $i++) {
				$sum += $sizes[$i];
			}
			return $sum;
		}
		if (!($mid > 0.0)) {
			return \NAN;
		}
		$sum = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$distanceBps = \abs($prices[$i] - $mid) / $mid * 10000.0;
			$sum += $sizes[$i] * \exp(-$distanceBps / $decayBps);
		}
		return $sum;
	}

	/**
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 * @return array{0: float, 1: float}
	 */
	private static function sides(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		int $levels,
		float $decayBps,
	): array {
		if ($bidPrices === [] || $askPrices === []) {
			return [\NAN, \NAN];
		}
		$mid = ($bidPrices[0] + $askPrices[0]) / 2.0;
		return [
			self::sideVolume($bidPrices, $bidSizes, $mid, $levels, $decayBps),
			self::sideVolume($askPrices, $askSizes, $mid, $levels, $decayBps),
		];
	}

	private static function normalise(float $bid, float $ask): float
	{
		$total = $bid + $ask;
		return $total > 0.0 ? ($bid - $ask) / $total : \NAN;
	}

	private static function asRatio(float $bid, float $ask): float
	{
		return $ask > 0.0 ? $bid / $ask : \NAN;
	}

	private static function asLogRatio(float $bid, float $ask): float
	{
		return $bid > 0.0 && $ask > 0.0 ? \log($bid / $ask) : \NAN;
	}
}
