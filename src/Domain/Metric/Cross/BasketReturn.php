<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Cross;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\Metric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;
use OpenCCK\Kalman\Domain\Metric\Volatility\RealizedVolatility;

/**
 * The market factor: the weighted average log return of a basket of
 * instruments (ROADMAP §4.7 M-24, the reference ADC).
 *
 *   r_i = ln(P_i,t / P_i,t−1)
 *   ADC = Σ w_i · r_i,        Σ w_i = 1
 *
 * with the weights chosen by `BasketWeighting`: equal, by traded volume, or by
 * inverse volatility.
 *
 * **Log returns, not simple ones.** A basket is an average of returns, and
 * only log returns are additive across time, so a basket built from simple
 * returns is wrong by a second-order term that grows precisely when
 * volatility — and therefore the signal — is largest (see `PriceDelta`).
 *
 * **Two corrections to the reference.** The reference PromQL is
 * `avg(((avg(P{ticker!~"…"}[5m:]) / avg((P{ticker!~"…"} offset 2h)[5m:])) − 1))`,
 * and both of its choices are worth naming:
 *
 *  1. `avg` over constituents is an equal weighting. It hands the most
 *     influence to the smallest and noisiest names: an asset with three times
 *     the volatility of its peers contributes three times the variance to the
 *     basket while carrying the same 1/N weight, so the "market" reading ends
 *     up tracking whatever microcap moved today. `BasketWeighting::Volume`
 *     and `BasketWeighting::InverseVolatility` are the practical answers — the
 *     first weights by where the money actually traded, the second equalises
 *     risk contributions rather than notionals.
 *  2. `ticker!~"…"` defines the basket by exclusion. Every ticker a venue
 *     lists tomorrow silently joins the basket at its first print, usually at
 *     its most illiquid and most volatile moment, and nothing in the query
 *     says so. This class therefore takes an **explicit constituent list**;
 *     a symbol missing from an update is an error, not a quietly smaller
 *     basket, so a feed outage cannot masquerade as a market move.
 *
 * This is not a `PriceMetric`: it consumes several instruments at once, so it
 * has its own `update(int $timestampNs, array $prices, array $sizes)` taking
 * `symbol => price` (and `symbol => traded size` for volume weighting) rather
 * than a single number.
 *
 * `values()` also reports the weighted cross-sectional dispersion
 * `√(Σ w_i (r_i − ADC)²)`: a basket return of zero with a wide dispersion is
 * rotation between constituents, which is the opposite of a quiet market, and
 * the level alone cannot tell the two apart.
 */
final class BasketReturn implements Metric
{
	/** @var list<string> */
	public readonly array $constituents;

	/** @var array<string, float> last price seen per constituent */
	private array $previous = [];

	/** @var array<string, float> last log return per constituent */
	private array $returns = [];

	/** @var array<string, float> weight actually applied at the last update */
	private array $weights = [];

	/** @var array<string, RollingMoments> mean traded size per constituent, for volume weighting */
	private array $sizes = [];

	/** @var array<string, RealizedVolatility> per constituent, for inverse-volatility weighting */
	private array $volatility = [];

	private float $basket = \NAN;

	private float $dispersion = \NAN;

	private bool $ready = false;

	/**
	 * @param list<string> $constituents the explicit basket; never a pattern
	 * @param int $window observations used by the volume and volatility weightings
	 */
	public function __construct(
		array $constituents,
		public readonly BasketWeighting $weighting = BasketWeighting::Equal,
		public readonly int $window = 60,
	) {
		if ($constituents === []) {
			throw new InvalidArgument('BasketReturn needs at least one constituent');
		}
		if ($window < 2) {
			throw new InvalidArgument(\sprintf('BasketReturn window must be >= 2, got %d', $window));
		}
		foreach ($constituents as $symbol) {
			if ($symbol === '') {
				throw new InvalidArgument('BasketReturn constituent symbols must not be empty');
			}
		}
		if (\count(\array_unique($constituents)) !== \count($constituents)) {
			throw new InvalidArgument('BasketReturn constituents must be unique');
		}
		$this->constituents = $constituents;
		foreach ($constituents as $symbol) {
			if ($this->weighting->needsSizes()) {
				$this->sizes[$symbol] = new RollingMoments($window);
			}
			if ($this->weighting->needsVolatility()) {
				$this->volatility[$symbol] = new RealizedVolatility($window);
			}
		}
	}

	public static function type(): string
	{
		return 'basket-return';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-24',
			symbol: 'ADC',
			category: MetricCategory::CrossAsset,
			inputs: [MetricInput::Multi],
			kernels: ['BasketReturn::equalWeight()', 'BasketReturn::volumeWeighted()', 'BasketReturn::inverseVolWeighted()'],
			nameEn: 'Basket return',
			nameRu: 'Доходность корзины',
			algoEn: 'The market factor everything else is measured against. Equal weights give the loudest vote to the smallest names, so volume and inverse-volatility weightings are offered; the constituent list is explicit, never an exclusion pattern that a new listing can slip through.',
			algoRu: 'Рыночный фактор, относительно которого меряется всё остальное. Равные веса дают решающий голос самым мелким бумагам, поэтому есть взвешивание по объёму и по обратной волатильности; состав задаётся явным списком, а не исключающим шаблоном, в который молча попадает новый тикер.',
			plainEn: 'Weighted average of the log returns of several instruments, with the weighted spread between them.',
			plainRu: 'Взвешенное среднее лог-доходностей нескольких инструментов и взвешенный разброс между ними.',
			example: 'examples/cross-basket-return.php',
		);
	}

	/**
	 * One synchronised observation of the whole basket.
	 *
	 * @param array<string, float> $prices symbol => price; every constituent must be present
	 * @param array<string, float> $sizes symbol => traded size; required by BasketWeighting::Volume, ignored otherwise
	 */
	public function update(int $timestampNs, array $prices, array $sizes = []): void
	{
		$returns = [];
		$complete = true;
		foreach ($this->constituents as $symbol) {
			if (!isset($prices[$symbol])) {
				throw new InvalidArgument(\sprintf('BasketReturn has no price for constituent "%s"', $symbol));
			}
			$price = $prices[$symbol];
			if (!($price > 0.0)) {
				throw new InvalidArgument(\sprintf('BasketReturn needs strictly positive prices, got %g for "%s"', $price, $symbol));
			}
			$previous = $this->previous[$symbol] ?? \NAN;
			$this->previous[$symbol] = $price;
			$r = \is_nan($previous) ? \NAN : \log($price / $previous);
			$returns[$symbol] = $r;
			if (\is_nan($r)) {
				$complete = false;
			}
			if (isset($this->volatility[$symbol])) {
				$this->volatility[$symbol]->updatePrice($timestampNs, $price);
			}
			if (isset($this->sizes[$symbol])) {
				if (!isset($sizes[$symbol])) {
					throw new InvalidArgument(\sprintf('BasketReturn volume weighting has no size for "%s"', $symbol));
				}
				$size = $sizes[$symbol];
				if ($size < 0.0) {
					throw new InvalidArgument(\sprintf('BasketReturn needs non-negative sizes, got %g for "%s"', $size, $symbol));
				}
				$this->sizes[$symbol]->push($size);
			}
		}
		$this->returns = $returns;
		$this->weights = $this->computeWeights();
		$this->aggregate($complete);
	}

	public function isReady(): bool
	{
		return $this->ready;
	}

	/** The weighted basket log return over the last observation. */
	public function value(): float
	{
		return $this->ready ? $this->basket : \NAN;
	}

	/** @return array{basket: float, dispersion: float} */
	public function values(): array
	{
		return [
			'basket' => $this->value(),
			'dispersion' => $this->ready ? $this->dispersion : \NAN,
		];
	}

	/**
	 * The weights actually applied at the last update, summing to one.
	 *
	 * @return array<string, float> empty before the first update
	 */
	public function weights(): array
	{
		return $this->weights;
	}

	/**
	 * The constituent log returns behind the last basket reading.
	 *
	 * @return array<string, float> empty before the first update
	 */
	public function returns(): array
	{
		return $this->returns;
	}

	public function reset(): void
	{
		$this->previous = [];
		$this->returns = [];
		$this->weights = [];
		foreach ($this->sizes as $moments) {
			$moments->reset();
		}
		foreach ($this->volatility as $estimator) {
			$estimator->reset();
		}
		$this->basket = \NAN;
		$this->dispersion = \NAN;
		$this->ready = false;
	}

	/** @return array{type: string, constituents: list<string>, weighting: string, window: int} */
	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'constituents' => $this->constituents,
			'weighting' => $this->weighting->value,
			'window' => $this->window,
		];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			self::symbolList($config, 'constituents'),
			self::weightingOf($config, 'weighting'),
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 60,
		);
	}

	/**
	 * Reads a `list<string>` out of an untyped config.
	 *
	 * @param array<string, mixed> $config
	 * @return list<string>
	 */
	public static function symbolList(array $config, string $key): array
	{
		if (!isset($config[$key]) || !\is_array($config[$key])) {
			throw new InvalidArgument(\sprintf('Config key "%s" must be a list of symbols', $key));
		}
		$symbols = [];
		foreach ($config[$key] as $symbol) {
			if (!\is_string($symbol)) {
				throw new InvalidArgument(\sprintf('Config key "%s" must contain strings only', $key));
			}
			$symbols[] = $symbol;
		}
		return $symbols;
	}

	/** @param array<string, mixed> $config */
	public static function weightingOf(array $config, string $key): BasketWeighting
	{
		if (!isset($config[$key])) {
			return BasketWeighting::Equal;
		}
		if (!\is_string($config[$key])) {
			throw new InvalidArgument(\sprintf('Config key "%s" must be a weighting name', $key));
		}
		return BasketWeighting::from($config[$key]);
	}

	/**
	 * Weights for the current update, summing to one, or all-NAN when the
	 * chosen weighting is not yet defined — never a silent fallback to equal
	 * weights, which would quietly change what the number means.
	 *
	 * @return array<string, float>
	 */
	private function computeWeights(): array
	{
		$weights = [];
		if ($this->weighting === BasketWeighting::Equal) {
			$w = 1.0 / \count($this->constituents);
			foreach ($this->constituents as $symbol) {
				$weights[$symbol] = $w;
			}
			return $weights;
		}
		$raw = [];
		$total = 0.0;
		foreach ($this->constituents as $symbol) {
			$value = $this->weighting === BasketWeighting::Volume
				? $this->meanSize($symbol)
				: $this->inverseSigma($symbol);
			if (\is_nan($value)) {
				foreach ($this->constituents as $each) {
					$weights[$each] = \NAN;
				}
				return $weights;
			}
			$raw[$symbol] = $value;
			$total += $value;
		}
		foreach ($this->constituents as $symbol) {
			$weights[$symbol] = $total > 0.0 ? ($raw[$symbol] ?? \NAN) / $total : \NAN;
		}
		return $weights;
	}

	/**
	 * Mean traded size over the window rather than the size of the last print:
	 * a single large fill must not reshuffle the whole basket for one update.
	 */
	private function meanSize(string $symbol): float
	{
		$moments = $this->sizes[$symbol] ?? null;
		if ($moments === null || $moments->count() === 0) {
			return \NAN;
		}
		return $moments->mean();
	}

	private function inverseSigma(string $symbol): float
	{
		$estimator = $this->volatility[$symbol] ?? null;
		if ($estimator === null || !$estimator->isReady()) {
			return \NAN;
		}
		$sigma = $estimator->value();
		return \is_nan($sigma) || $sigma <= 0.0 ? \NAN : 1.0 / $sigma;
	}

	private function aggregate(bool $complete): void
	{
		$basket = 0.0;
		$ready = $complete;
		foreach ($this->constituents as $symbol) {
			$w = $this->weights[$symbol] ?? \NAN;
			$r = $this->returns[$symbol] ?? \NAN;
			if (\is_nan($w) || \is_nan($r)) {
				$ready = false;
				break;
			}
			$basket += $w * $r;
		}
		if (!$ready) {
			$this->ready = false;
			$this->basket = \NAN;
			$this->dispersion = \NAN;
			return;
		}
		$variance = 0.0;
		foreach ($this->constituents as $symbol) {
			$d = ($this->returns[$symbol] ?? \NAN) - $basket;
			$variance += ($this->weights[$symbol] ?? \NAN) * $d * $d;
		}
		$this->ready = true;
		$this->basket = $basket;
		$this->dispersion = $variance > 0.0 ? \sqrt($variance) : 0.0;
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Equal weights — the reference form, kept because it is the reference
	 * form and because it is the right choice when the constituents really are
	 * comparable in size and volatility.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<string, list<float>> $series symbol => price series, all of the same length and aligned in time
	 * @return list<float> the basket log return at each step, NAN at the first one
	 */
	public static function equalWeight(array $series): array
	{
		return self::run($series, [], BasketWeighting::Equal->value, 2);
	}

	/**
	 * Weights proportional to the mean traded size of each constituent over
	 * the window.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<string, list<float>> $series symbol => price series
	 * @param array<string, list<float>> $sizes symbol => traded size series, same keys and length
	 * @return list<float>
	 */
	public static function volumeWeighted(array $series, array $sizes, int $window = 60): array
	{
		return self::run($series, $sizes, BasketWeighting::Volume->value, $window);
	}

	/**
	 * Weights proportional to 1/σ_i, so every constituent contributes the same
	 * risk. NAN until every constituent has a full volatility window.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<string, list<float>> $series symbol => price series
	 * @return list<float>
	 */
	public static function inverseVolWeighted(array $series, int $window = 60): array
	{
		return self::run($series, [], BasketWeighting::InverseVolatility->value, $window);
	}

	/**
	 * Drives the streaming object over whole series, so the batch and live
	 * paths cannot drift apart.
	 *
	 * @param array<string, list<float>> $series
	 * @param array<string, list<float>> $sizes
	 * @return list<float>
	 */
	private static function run(array $series, array $sizes, string $weighting, int $window): array
	{
		$symbols = \array_keys($series);
		$length = self::seriesLength($series);
		$mode = BasketWeighting::from($weighting);
		$metric = new self($symbols, $mode, $window);
		$useSizes = $mode->needsSizes();
		if ($useSizes) {
			foreach ($symbols as $symbol) {
				if (!isset($sizes[$symbol]) || \count($sizes[$symbol]) !== $length) {
					throw new InvalidArgument(\sprintf('BasketReturn volume weighting needs a size series of length %d for "%s"', $length, $symbol));
				}
			}
		}
		$out = [];
		for ($i = 0; $i < $length; $i++) {
			$prices = [];
			$row = [];
			foreach ($symbols as $symbol) {
				$column = $series[$symbol] ?? [];
				$prices[$symbol] = $column[$i] ?? \NAN;
				if ($useSizes) {
					$sizeColumn = $sizes[$symbol] ?? [];
					$row[$symbol] = $sizeColumn[$i] ?? \NAN;
				}
			}
			$metric->update(0, $prices, $row);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * @param array<string, list<float>> $series
	 * @return int the common length of every series
	 */
	private static function seriesLength(array $series): int
	{
		if ($series === []) {
			throw new InvalidArgument('BasketReturn needs at least one constituent');
		}
		$length = -1;
		foreach ($series as $column) {
			$count = \count($column);
			if ($length < 0) {
				$length = $count;
			} elseif ($count !== $length) {
				throw new InvalidArgument('BasketReturn needs price series of equal length');
			}
		}
		if ($length < 1) {
			throw new InvalidArgument('BasketReturn needs a non-empty price series');
		}
		return $length;
	}
}
