<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Microstructure;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BookMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Order-flow imbalance at the best quotes (ROADMAP §4.9 M-29; Cont, Kukanov
 * and Stoikov, 2010).
 *
 * For each pair of consecutive best-quote snapshots:
 *
 *   e_n = 1[P^b_n ≥ P^b_{n−1}]·q^b_n − 1[P^b_n ≤ P^b_{n−1}]·q^b_{n−1}
 *       − 1[P^a_n ≤ P^a_{n−1}]·q^a_n + 1[P^a_n ≥ P^a_{n−1}]·q^a_{n−1}
 *
 *   OFI = Σ e_n over the window,      Δmid ≈ OFI / (2·D)
 *
 * One number that counts every way the top of book can change — a new order,
 * a cancellation, an execution — with the right sign. A bid improving in price
 * adds its whole size; a bid being pulled removes the size that was there; and
 * the ask side enters with the opposite sign.
 *
 * The finding that makes it worth computing is that the relationship to the
 * price change is **linear**, with a slope inversely proportional to depth,
 * and that it explains on the order of two thirds of short-horizon price
 * variation in equity markets. That is a strong result by the standards of
 * this field, and it is the reason OFI belongs in a filtering library: it is
 * an observation of the same hidden fair value the filter tracks, arriving
 * faster than the trade prints do.
 *
 * OFI is a *flow*, so it depends on the update rate of the feed — comparing it
 * across venues or across subscription tiers is meaningless unless the windows
 * are matched. `impliedMove()` divides by twice the average depth, which is the
 * comparable form.
 *
 * **Units.** `impliedMove()` is in **ticks**, not in the instrument's price:
 * the model is stated in tick units, and the depth in the denominator is a
 * size. Multiply by the tick size to compare it with a price change.
 * `examples/micro-ofi.php` regresses it against the realised move and finds a
 * slope near 0.72 in ticks — the right order, since the coefficient absorbs
 * everything the linear model leaves out.
 */
final class OrderFlowImbalance implements BookMetric
{
	private RollingMoments $flow;

	private RollingMoments $depth;

	private float $previousBidPrice = \NAN;

	private float $previousBidSize = \NAN;

	private float $previousAskPrice = \NAN;

	private float $previousAskSize = \NAN;

	private float $lastEvent = \NAN;

	public function __construct(public readonly int $window = 50)
	{
		if ($window < 1) {
			throw new InvalidArgument('OrderFlowImbalance window must be >= 1');
		}
		$this->flow = new RollingMoments($window);
		$this->depth = new RollingMoments($window);
	}

	public static function type(): string
	{
		return 'order-flow-imbalance';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-29',
			symbol: 'OFI',
			category: MetricCategory::Microstructure,
			inputs: [MetricInput::Book],
			kernels: ['OrderFlowImbalance::event()', 'OrderFlowImbalance::compute()'],
			nameEn: 'Order-flow imbalance',
			nameRu: 'Дисбаланс потока заявок',
			algoEn: 'One of the strongest short-horizon price predictors published, with a roughly linear impact whose slope falls with depth. It is an observation of fair value that arrives before the prints do.',
			algoRu: 'Один из сильнейших опубликованных предикторов цены на коротком горизонте, с почти линейным влиянием, наклон которого падает с глубиной. Это наблюдение справедливой цены, приходящее раньше сделок.',
			plainEn: 'Net order flow at the best bid and ask across consecutive book updates, summed over a window.',
			plainRu: 'Чистый поток заявок на лучших покупке и продаже между последовательными обновлениями стакана, просуммированный по окну.',
			example: 'examples/micro-ofi.php',
		);
	}

	public function updateBook(OrderBook $book): void
	{
		if ($book->isEmpty()) {
			return;
		}
		$this->feed($book->bestBid(), $book->bestBidSize(), $book->bestAsk(), $book->bestAskSize());
	}

	private function feed(float $bidPrice, float $bidSize, float $askPrice, float $askSize): void
	{
		if (!\is_nan($this->previousBidPrice)) {
			$this->lastEvent = self::event(
				$this->previousBidPrice,
				$this->previousBidSize,
				$this->previousAskPrice,
				$this->previousAskSize,
				$bidPrice,
				$bidSize,
				$askPrice,
				$askSize,
			);
			$this->flow->push($this->lastEvent);
			$this->depth->push(($bidSize + $askSize) / 2.0);
		}
		$this->previousBidPrice = $bidPrice;
		$this->previousBidSize = $bidSize;
		$this->previousAskPrice = $askPrice;
		$this->previousAskSize = $askSize;
	}

	public function isReady(): bool
	{
		return $this->flow->isFull();
	}

	/** Summed flow over the window. */
	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		return $this->flow->mean() * $this->flow->count();
	}

	/**
	 * Flow divided by twice the average depth: the mid move the model implies,
	 * measured in **ticks**. Multiply by the tick size for a price.
	 */
	public function impliedMove(): float
	{
		$ofi = $this->value();
		if (\is_nan($ofi)) {
			return \NAN;
		}
		$depth = $this->depth->mean();
		return $depth > 0.0 ? $ofi / (2.0 * $depth) : \NAN;
	}

	/** @return array{ofi: float, impliedMove: float, lastEvent: float, depth: float} */
	public function values(): array
	{
		return [
			'ofi' => $this->value(),
			'impliedMove' => $this->impliedMove(),
			'lastEvent' => $this->lastEvent,
			'depth' => $this->depth->count() > 0 ? $this->depth->mean() : \NAN,
		];
	}

	public function reset(): void
	{
		$this->flow->reset();
		$this->depth->reset();
		$this->previousBidPrice = \NAN;
		$this->previousBidSize = \NAN;
		$this->previousAskPrice = \NAN;
		$this->previousAskSize = \NAN;
		$this->lastEvent = \NAN;
	}

	/** @return array{type: string, window: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'window' => $this->window];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(isset($config['window']) && \is_int($config['window']) ? $config['window'] : 50);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * The contribution of one book update.
	 *
	 * @deterministic
	 * @offloadable
	 */
	public static function event(
		float $previousBidPrice,
		float $previousBidSize,
		float $previousAskPrice,
		float $previousAskSize,
		float $bidPrice,
		float $bidSize,
		float $askPrice,
		float $askSize,
	): float {
		$e = 0.0;
		if ($bidPrice >= $previousBidPrice) {
			$e += $bidSize;
		}
		if ($bidPrice <= $previousBidPrice) {
			$e -= $previousBidSize;
		}
		if ($askPrice <= $previousAskPrice) {
			$e -= $askSize;
		}
		if ($askPrice >= $previousAskPrice) {
			$e += $previousAskSize;
		}
		return $e;
	}

	/**
	 * Windowed OFI over a series of best-quote snapshots.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 * @return array{ofi: list<float>, event: list<float>, impliedMove: list<float>}
	 */
	public static function compute(array $bidPrices, array $bidSizes, array $askPrices, array $askSizes, int $window = 50): array
	{
		$n = \count($bidPrices);
		if (\count($bidSizes) !== $n || \count($askPrices) !== $n || \count($askSizes) !== $n) {
			throw new InvalidArgument('OrderFlowImbalance needs four arrays of equal length');
		}
		$metric = new self($window);
		$ofi = [];
		$event = [];
		$implied = [];
		for ($i = 0; $i < $n; $i++) {
			$metric->feed($bidPrices[$i], $bidSizes[$i], $askPrices[$i], $askSizes[$i]);
			$values = $metric->values();
			$ofi[] = $values['ofi'];
			$event[] = $values['lastEvent'];
			$implied[] = $values['impliedMove'];
		}
		return ['ofi' => $ofi, 'event' => $event, 'impliedMove' => $implied];
	}
}
