<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Cross;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\Metric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Price\PriceDelta;
use OpenCCK\Kalman\Domain\Metric\Volatility\RealizedVolatility;

/**
 * Market strength: how broad a move is, and how large it is relative to its
 * own noise (ROADMAP §4.7 M-26, the reference MSI).
 *
 * **This definition is this library's, not a reference implementation.** The
 * source spreadsheet names the indicator and gives no formula; the ROADMAP
 * records that gap and proposes the composite implemented here. Nothing below
 * reproduces anyone else's MSI, and a number produced by this class must not
 * be compared against one produced by someone else's under the same name.
 *
 *   breadth  = 2·(#{i : r_i(N) > 0} / N) − 1         in [−1, +1]
 *   z_i      = r_i(N) / (σ_i · √N)                   standardised momentum
 *   momentum = Σ w_i · z_i                           weights from BasketWeighting
 *   MSI      = blend · breadth + (1 − blend) · tanh(momentum)
 *
 * where r_i(N) = ln(P_i,t / P_i,t−N) is the window return of constituent i and
 * σ_i its realised volatility per observation over the same window.
 *
 * **Why two components.** Breadth alone cannot tell a broad 0.1 % drift from a
 * broad 5 % rally: it is a count, and counts discard magnitude. Momentum alone
 * cannot tell a whole market rising together from one constituent rising
 * enough to carry the weighted average on its own — which is the failure mode
 * that matters, because the second is a single-name story and the first is a
 * market. Each covers the other's blind spot, and reporting them separately in
 * `values()` is not optional garnish: when the two disagree, the disagreement
 * *is* the signal.
 *
 * **Why σ and why tanh.** Dividing the window return by σ_i · √N puts every
 * constituent on the same scale before averaging, so a quiet large-cap and a
 * violent microcap contribute comparable numbers instead of the microcap
 * drowning the sum. The √N assumes returns inside the window are roughly
 * independent — the same assumption `RealizedVolatility` makes when it
 * annualises, and it understates the tails whenever volatility clusters, which
 * it does. `tanh` then maps the standardised momentum onto the same bounded
 * [−1, +1] range breadth already lives on; without it a single 6σ print would
 * dominate a term that is bounded by construction, and the blend weight would
 * stop meaning what it says.
 *
 * `blend` is a constructor parameter (default 0.5) because there is no
 * defensible universal value: it encodes how much a desk trusts participation
 * over magnitude, and that is a preference, not a fact.
 */
final class MarketStrength implements Metric
{
	/** @var list<string> */
	public readonly array $constituents;

	private BasketReturn $basket;

	/** @var array<string, PriceDelta> window return per constituent */
	private array $momentumOf = [];

	/** @var array<string, RealizedVolatility> window volatility per constituent */
	private array $volatilityOf = [];

	private float $breadth = \NAN;

	private float $momentum = \NAN;

	/**
	 * @param list<string> $constituents the explicit basket; never a pattern
	 * @param int $window observations behind the window return, the volatility and the weights
	 * @param float $blend weight of the breadth term; 1.0 is breadth only, 0.0 momentum only
	 */
	public function __construct(
		array $constituents,
		public readonly int $window = 60,
		public readonly float $blend = 0.5,
		public readonly BasketWeighting $weighting = BasketWeighting::Volume,
	) {
		if ($window < 2) {
			throw new InvalidArgument(\sprintf('MarketStrength window must be >= 2, got %d', $window));
		}
		if (!($blend >= 0.0) || $blend > 1.0) {
			throw new InvalidArgument('MarketStrength blend must be in [0, 1]');
		}
		$this->basket = new BasketReturn($constituents, $weighting, $window);
		$this->constituents = $this->basket->constituents;
		foreach ($this->constituents as $symbol) {
			$this->momentumOf[$symbol] = new PriceDelta($window);
			$this->volatilityOf[$symbol] = new RealizedVolatility($window);
		}
	}

	public static function type(): string
	{
		return 'market-strength';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-26',
			symbol: 'MSI',
			category: MetricCategory::CrossAsset,
			inputs: [MetricInput::Multi],
			kernels: ['MarketStrength::compute()', 'MarketStrength::breadth()'],
			nameEn: 'Market strength index',
			nameRu: 'Индекс силы рынка',
			algoEn: 'How many constituents are rising, combined with how large the move is against its own noise. The reference names this indicator without defining it; the composite here is this library\'s own definition, not a reproduction.',
			algoRu: 'Сколько бумаг корзины растёт, вместе с тем, насколько движение велико относительно собственного шума. Референс называет индикатор, но не определяет его; композит здесь — собственное определение библиотеки, а не воспроизведение.',
			plainEn: 'Share of a basket that is rising, mapped to [−1, +1], blended with its volume-weighted standardised momentum.',
			plainRu: 'Доля растущих инструментов корзины, отображённая в [−1, +1], в смеси со взвешенным по объёму стандартизованным импульсом.',
			example: 'examples/cross-market-strength.php',
		);
	}

	/**
	 * @param array<string, float> $prices symbol => price; every constituent must be present
	 * @param array<string, float> $sizes symbol => traded size; required by BasketWeighting::Volume
	 */
	public function update(int $timestampNs, array $prices, array $sizes = []): void
	{
		$this->basket->update($timestampNs, $prices, $sizes);
		foreach ($this->constituents as $symbol) {
			$price = $prices[$symbol] ?? \NAN;
			$momentum = $this->momentumOf[$symbol] ?? null;
			$volatility = $this->volatilityOf[$symbol] ?? null;
			if ($momentum === null || $volatility === null) {
				continue;
			}
			$momentum->updatePrice($timestampNs, $price);
			$volatility->updatePrice($timestampNs, $price);
		}
		$this->breadth = $this->computeBreadth();
		$this->momentum = $this->computeMomentum();
	}

	public function isReady(): bool
	{
		return !\is_nan($this->breadth) && !\is_nan($this->momentum);
	}

	/** The composite, in [−1, +1]. */
	public function value(): float
	{
		return $this->isReady()
			? $this->blend * $this->breadth + (1.0 - $this->blend) * \tanh($this->momentum)
			: \NAN;
	}

	/** Share of the basket that is up over the window, mapped to [−1, +1]. */
	public function breadthValue(): float
	{
		return $this->breadth;
	}

	/** Weighted mean standardised momentum, unbounded; `tanh` of this enters the composite. */
	public function momentumValue(): float
	{
		return $this->momentum;
	}

	/** @return array{msi: float, breadth: float, momentum: float, momentumScaled: float} */
	public function values(): array
	{
		return [
			'msi' => $this->value(),
			'breadth' => $this->breadth,
			'momentum' => $this->momentum,
			'momentumScaled' => \is_nan($this->momentum) ? \NAN : \tanh($this->momentum),
		];
	}

	public function reset(): void
	{
		$this->basket->reset();
		foreach ($this->momentumOf as $momentum) {
			$momentum->reset();
		}
		foreach ($this->volatilityOf as $volatility) {
			$volatility->reset();
		}
		$this->breadth = \NAN;
		$this->momentum = \NAN;
	}

	/** @return array{type: string, constituents: list<string>, window: int, blend: float, weighting: string} */
	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'constituents' => $this->constituents,
			'window' => $this->window,
			'blend' => $this->blend,
			'weighting' => $this->weighting->value,
		];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		$blend = 0.5;
		if (isset($config['blend']) && (\is_float($config['blend']) || \is_int($config['blend']))) {
			$blend = (float) $config['blend'];
		}
		$weighting = isset($config['weighting'])
			? BasketReturn::weightingOf($config, 'weighting')
			: BasketWeighting::Volume;
		return new self(
			BasketReturn::symbolList($config, 'constituents'),
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 60,
			$blend,
			$weighting,
		);
	}

	/** 2·share − 1, or NAN until every constituent has a full window. */
	private function computeBreadth(): float
	{
		$positive = 0;
		$total = 0;
		foreach ($this->constituents as $symbol) {
			$momentum = $this->momentumOf[$symbol] ?? null;
			if ($momentum === null || !$momentum->isReady()) {
				return \NAN;
			}
			$r = $momentum->value();
			if (\is_nan($r)) {
				return \NAN;
			}
			if ($r > 0.0) {
				$positive++;
			}
			$total++;
		}
		return $total === 0 ? \NAN : 2.0 * ($positive / $total) - 1.0;
	}

	/** Σ w_i · r_i(N) / (σ_i · √N), or NAN if any constituent or weight is undefined. */
	private function computeMomentum(): float
	{
		$weights = $this->basket->weights();
		$scale = \sqrt((float) $this->window);
		$sum = 0.0;
		foreach ($this->constituents as $symbol) {
			$momentum = $this->momentumOf[$symbol] ?? null;
			$volatility = $this->volatilityOf[$symbol] ?? null;
			if ($momentum === null || $volatility === null || !$momentum->isReady() || !$volatility->isReady()) {
				return \NAN;
			}
			$w = $weights[$symbol] ?? \NAN;
			$r = $momentum->value();
			$sigma = $volatility->value();
			if (\is_nan($w) || \is_nan($r) || \is_nan($sigma) || $sigma <= 0.0) {
				return \NAN;
			}
			$sum += $w * $r / ($sigma * $scale);
		}
		return $sum;
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * The composite and both of its components over whole series.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<string, list<float>> $series symbol => price series, all of the same length and aligned in time
	 * @param array<string, list<float>> $sizes symbol => traded size series; required by the "volume" weighting
	 * @param string $weighting a BasketWeighting value: "equal", "volume" or "inverse-volatility"
	 * @return array{msi: list<float>, breadth: list<float>, momentum: list<float>}
	 */
	public static function compute(
		array $series,
		array $sizes = [],
		int $window = 60,
		float $blend = 0.5,
		string $weighting = 'volume',
	): array {
		if ($series === []) {
			throw new InvalidArgument('MarketStrength needs at least one constituent');
		}
		$symbols = \array_keys($series);
		$length = -1;
		foreach ($series as $column) {
			$count = \count($column);
			if ($length < 0) {
				$length = $count;
			} elseif ($count !== $length) {
				throw new InvalidArgument('MarketStrength needs price series of equal length');
			}
		}
		if ($length < 1) {
			throw new InvalidArgument('MarketStrength needs a non-empty price series');
		}
		$mode = BasketWeighting::from($weighting);
		$useSizes = $mode->needsSizes();
		if ($useSizes) {
			foreach ($symbols as $symbol) {
				if (!isset($sizes[$symbol]) || \count($sizes[$symbol]) !== $length) {
					throw new InvalidArgument(\sprintf('MarketStrength volume weighting needs a size series of length %d for "%s"', $length, $symbol));
				}
			}
		}
		$metric = new self($symbols, $window, $blend, $mode);
		$msi = [];
		$breadth = [];
		$momentum = [];
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
			$msi[] = $metric->value();
			$breadth[] = $metric->breadthValue();
			$momentum[] = $metric->momentumValue();
		}
		return ['msi' => $msi, 'breadth' => $breadth, 'momentum' => $momentum];
	}

	/**
	 * Breadth on its own: +1 when every constituent is up over the window, −1
	 * when every one is down, 0 at an even split. Needs no sizes and no
	 * volatility, so it is defined as soon as the window is full.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<string, list<float>> $series
	 * @return list<float>
	 */
	public static function breadth(array $series, int $window = 60): array
	{
		return self::compute($series, [], $window, 1.0, BasketWeighting::Equal->value)['breadth'];
	}
}
