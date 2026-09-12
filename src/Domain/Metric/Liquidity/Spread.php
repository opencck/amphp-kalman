<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Liquidity;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BookMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * The spread, in the four forms that mean different things, plus Roll's
 * estimator (ROADMAP §4.5 M-20).
 *
 *   quoted     S = P_ask − P_bid                    what is displayed
 *   relative   S / mid, in basis points             comparable across assets
 *   effective  2·|P_trade − mid|                    what a taker actually paid
 *   realised   2·D·(P_trade − mid_{t+Δ})            what the maker kept
 *   Roll       2·√(−Cov(Δp_t, Δp_{t−1}))            inferred from the tape alone
 *
 * The quoted spread is the advertised price of immediacy; the effective spread
 * is the paid one, and it is larger whenever an order walks past the top
 * level. The realised spread subtracts what the market maker lost to adverse
 * selection after the trade — the difference between the two is the price of
 * being on the wrong side of an informed trader.
 *
 * Roll's estimator is the interesting one. Under his model the only reason
 * consecutive price changes are negatively correlated is the bid-ask bounce,
 * so the spread can be recovered from trade prices with **no order book at
 * all** — useful for venues that publish only a tape, and a direct check on
 * the library's own `BidAskBounce` model, which estimates the same effect as a
 * filter state instead of a moment. When the sample autocovariance comes out
 * positive (trending prices overwhelm the bounce) the estimator has no real
 * root, and this implementation returns NAN rather than a fabricated zero.
 */
final class Spread implements BookMetric
{
	private RollingMoments $relative;

	private float $quoted = \NAN;

	private float $mid = \NAN;

	public function __construct(public readonly int $window = 100)
	{
		if ($window < 1) {
			throw new InvalidArgument('Spread window must be >= 1');
		}
		$this->relative = new RollingMoments($window);
	}

	public static function type(): string
	{
		return 'spread';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-20',
			symbol: 'S',
			category: MetricCategory::Liquidity,
			inputs: [MetricInput::Book, MetricInput::Trades],
			kernels: ['Spread::quoted()', 'Spread::relativeBps()', 'Spread::effective()', 'Spread::realized()', 'Spread::roll()'],
			nameEn: 'Spread',
			nameRu: 'Спред',
			algoEn: 'Your round-trip cost floor. The effective spread is what a taker actually pays, and Roll\'s estimator recovers the spread from the tape alone when no book is available.',
			algoRu: 'Нижняя граница издержек оборота. Эффективный спред — то, что реально платит тейкер, а оценка Ролла восстанавливает спред по одной ленте сделок, когда стакана нет.',
			plainEn: 'Quoted, relative, effective and realised spread, plus Roll\'s estimator inferred from the autocovariance of price changes.',
			plainRu: 'Котируемый, относительный, эффективный и реализованный спред плюс оценка Ролла по автоковариации изменений цены.',
			example: 'examples/liquidity-spread.php',
		);
	}

	public function updateBook(OrderBook $book): void
	{
		if ($book->isEmpty()) {
			$this->quoted = \NAN;
			$this->mid = \NAN;
			return;
		}
		$this->quoted = $book->spread();
		$this->mid = $book->mid();
		$relative = $book->relativeSpreadBps();
		if (!\is_nan($relative)) {
			$this->relative->push($relative);
		}
	}

	public function isReady(): bool
	{
		return !\is_nan($this->quoted);
	}

	/** Relative spread in basis points. */
	public function value(): float
	{
		return $this->mid > 0.0 ? $this->quoted / $this->mid * 10000.0 : \NAN;
	}

	/** @return array{relativeBps: float, quoted: float, mid: float, averageBps: float} */
	public function values(): array
	{
		return [
			'relativeBps' => $this->value(),
			'quoted' => $this->quoted,
			'mid' => $this->mid,
			'averageBps' => $this->relative->count() > 0 ? $this->relative->mean() : \NAN,
		];
	}

	public function reset(): void
	{
		$this->relative->reset();
		$this->quoted = \NAN;
		$this->mid = \NAN;
	}

	/** @return array{type: string, window: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'window' => $this->window];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(isset($config['window']) && \is_int($config['window']) ? $config['window'] : 100);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * @deterministic
	 * @offloadable
	 */
	public static function quoted(float $bidPrice, float $askPrice): float
	{
		return $askPrice - $bidPrice;
	}

	/**
	 * @deterministic
	 * @offloadable
	 */
	public static function relativeBps(float $bidPrice, float $askPrice): float
	{
		$mid = ($bidPrice + $askPrice) / 2.0;
		return $mid > 0.0 ? ($askPrice - $bidPrice) / $mid * 10000.0 : \NAN;
	}

	/**
	 * Effective spread of each trade: twice its distance from the mid that
	 * prevailed when it printed. Larger than the quoted spread whenever the
	 * order walked the book.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $tradePrices
	 * @param list<float> $mids the mid at the time of each trade
	 * @return list<float>
	 */
	public static function effective(array $tradePrices, array $mids): array
	{
		$n = \count($tradePrices);
		if (\count($mids) !== $n) {
			throw new InvalidArgument('Spread::effective needs one mid per trade');
		}
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$out[] = 2.0 * \abs($tradePrices[$i] - $mids[$i]);
		}
		return $out;
	}

	/**
	 * Realised spread: what the liquidity provider keeps once the price has
	 * moved against it. The gap between this and the effective spread is the
	 * adverse-selection cost.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $tradePrices
	 * @param list<float> $signs +1 buyer-initiated, −1 seller-initiated
	 * @param list<float> $futureMids the mid a chosen horizon after each trade
	 * @return list<float>
	 */
	public static function realized(array $tradePrices, array $signs, array $futureMids): array
	{
		$n = \count($tradePrices);
		if (\count($signs) !== $n || \count($futureMids) !== $n) {
			throw new InvalidArgument('Spread::realized needs prices, signs and future mids of equal length');
		}
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$out[] = 2.0 * $signs[$i] * ($tradePrices[$i] - $futureMids[$i]);
		}
		return $out;
	}

	/**
	 * Roll's estimator: 2·√(−Cov(Δp_t, Δp_{t−1})), or NAN when the sample
	 * autocovariance is not negative and the model therefore has no root.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices trade prices in order
	 */
	public static function roll(array $prices): float
	{
		$n = \count($prices);
		if ($n < 3) {
			return \NAN;
		}
		$changes = [];
		for ($i = 1; $i < $n; $i++) {
			$changes[] = $prices[$i] - $prices[$i - 1];
		}
		$m = \count($changes);
		$mean = 0.0;
		foreach ($changes as $change) {
			$mean += $change;
		}
		$mean /= $m;
		$covariance = 0.0;
		for ($i = 1; $i < $m; $i++) {
			$covariance += ($changes[$i] - $mean) * ($changes[$i - 1] - $mean);
		}
		$covariance /= $m - 1;
		// A positive autocovariance means trending prices dominate the bounce;
		// the estimator has no real root there, and inventing one would report
		// a spread of zero for a market that is simply trending.
		return $covariance < 0.0 ? 2.0 * \sqrt(-$covariance) : \NAN;
	}
}
