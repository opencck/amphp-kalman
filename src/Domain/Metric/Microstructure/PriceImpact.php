<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Microstructure;

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\TradeMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * How much the price moves per unit of order flow (ROADMAP §4.9 M-31).
 *
 *   Kyle's λ:  Δp_t = λ · (signed volume) + ε      the regression slope
 *   Amihud:    ILLIQ = mean of |r| / notional
 *
 * Kyle's λ is a structural parameter: in his model an informed trader's order
 * moves the price by exactly λ per unit, and 1/λ is the market's depth. Amihud
 * is the practical cousin — no regression, just the average price move per
 * dollar traded — and it correlates well enough with λ to have become the
 * standard illiquidity proxy in the asset-pricing literature.
 *
 * Both answer the question that caps order size. Impact multiplied by size is
 * a cost paid before any signal pays off, so a strategy whose edge is four
 * basis points cannot trade a size whose impact is six. λ also sets the decay
 * of that cost over time, which is what an execution schedule optimises
 * against.
 *
 * λ here is a rolling ordinary least squares through the origin — there is no
 * intercept, because zero net flow must imply zero expected price change:
 *
 *   λ = Σ (v_i · Δp_i) / Σ v_i²
 *
 * The first trade establishes the reference price and produces no
 * observation, so a series of n trades yields n−1 of them: a window equal to
 * the length of the series never fills.
 *
 * That is the batch estimator. The library can do better: treating λ as a
 * random-walk state with Δp as the observation and signed flow as the
 * regressor turns it into exactly the problem `PairsHedge` solves for a hedge
 * ratio, giving λ_t in real time with a variance attached instead of a number
 * per window. `Filtered\MetricObservation` is the bridge; the rolling form
 * stays here because it is the one every published result uses.
 */
final class PriceImpact implements TradeMetric
{
	private RollingMoments $crossProducts;

	private RollingMoments $squaredFlow;

	private RollingMoments $amihud;

	private float $previousPrice = \NAN;

	public function __construct(public readonly int $window = 500)
	{
		if ($window < 2) {
			throw new InvalidArgument('PriceImpact window must be >= 2');
		}
		$this->crossProducts = new RollingMoments($window);
		$this->squaredFlow = new RollingMoments($window);
		$this->amihud = new RollingMoments($window);
	}

	public static function type(): string
	{
		return 'price-impact';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-31',
			symbol: 'λ/ILLIQ',
			category: MetricCategory::Microstructure,
			inputs: [MetricInput::Trades],
			kernels: ['PriceImpact::kyleLambda()', 'PriceImpact::amihud()', 'PriceImpact::compute()'],
			nameEn: 'Price impact',
			nameRu: 'Влияние на цену',
			algoEn: 'Caps your order size: impact times size is a cost paid before any signal pays off. An edge of four basis points cannot trade a size whose impact is six.',
			algoRu: 'Ограничивает размер заявки: влияние, умноженное на объём, — издержка, которую платят до того, как сигнал начнёт окупаться. Преимущество в четыре базисных пункта не торгуется объёмом с влиянием в шесть.',
			plainEn: 'Regression slope of price change on signed order flow, and the average absolute return per unit of turnover.',
			plainRu: 'Наклон регрессии изменения цены на подписанный поток заявок и средняя абсолютная доходность на единицу оборота.',
			example: 'examples/micro-price-impact.php',
		);
	}

	public function updateTrade(Trade $trade): void
	{
		$this->feed($trade->price, $trade->signedSize());
	}

	private function feed(float $price, float $signedSize): void
	{
		if (\is_nan($this->previousPrice)) {
			$this->previousPrice = $price;
			return;
		}
		$change = $price - $this->previousPrice;
		$notional = \abs($signedSize) * $price;
		$this->previousPrice = $price;

		$this->crossProducts->push($signedSize * $change);
		$this->squaredFlow->push($signedSize * $signedSize);
		if ($notional > 0.0 && $price > 0.0) {
			$this->amihud->push(\abs($change / $price) / $notional);
		}
	}

	public function isReady(): bool
	{
		return $this->crossProducts->isFull();
	}

	/** Kyle's λ. */
	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		$denominator = $this->squaredFlow->mean();
		// No flow in the window means no slope is identified — not a slope of
		// zero, which would claim the market has infinite depth.
		return $denominator > 0.0 ? $this->crossProducts->mean() / $denominator : \NAN;
	}

	/** @return array{lambda: float, depth: float, amihud: float} */
	public function values(): array
	{
		$lambda = $this->value();
		return [
			'lambda' => $lambda,
			'depth' => \is_nan($lambda) || !($lambda > 0.0) ? \NAN : 1.0 / $lambda,
			'amihud' => $this->amihud->count() > 0 ? $this->amihud->mean() : \NAN,
		];
	}

	public function reset(): void
	{
		$this->crossProducts->reset();
		$this->squaredFlow->reset();
		$this->amihud->reset();
		$this->previousPrice = \NAN;
	}

	/** @return array{type: string, window: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'window' => $this->window];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(isset($config['window']) && \is_int($config['window']) ? $config['window'] : 500);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Rolling Kyle's λ.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $signedSizes +size bought, −size sold
	 * @return list<float>
	 */
	public static function kyleLambda(array $prices, array $signedSizes, int $window = 500): array
	{
		return self::compute($prices, $signedSizes, $window)['lambda'];
	}

	/**
	 * Rolling Amihud illiquidity: mean |return| per unit of notional traded.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $signedSizes
	 * @return list<float>
	 */
	public static function amihud(array $prices, array $signedSizes, int $window = 500): array
	{
		return self::compute($prices, $signedSizes, $window)['amihud'];
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $signedSizes
	 * @return array{lambda: list<float>, depth: list<float>, amihud: list<float>}
	 */
	public static function compute(array $prices, array $signedSizes, int $window = 500): array
	{
		$n = \count($prices);
		if (\count($signedSizes) !== $n) {
			throw new InvalidArgument('PriceImpact needs prices and signed sizes of equal length');
		}
		$metric = new self($window);
		$lambda = [];
		$depth = [];
		$amihud = [];
		for ($i = 0; $i < $n; $i++) {
			$metric->feed($prices[$i], $signedSizes[$i]);
			$values = $metric->values();
			$lambda[] = $values['lambda'];
			$depth[] = $values['depth'];
			$amihud[] = $values['amihud'];
		}
		return ['lambda' => $lambda, 'depth' => $depth, 'amihud' => $amihud];
	}
}
