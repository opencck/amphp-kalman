<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Cross;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\Metric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RingBuffer;

/**
 * Relative strength: how much of an asset's move was its own and how much was
 * the market's (ROADMAP §4.7 M-25, the reference DD).
 *
 * Two forms, and the difference between them is the whole point:
 *
 *   plain          DD = r_asset − r_basket
 *   beta-adjusted  DD = r_asset − β · r_basket,   β = Cov(r_a, r_b) / Var(r_b)
 *
 * **Only the beta-adjusted form is market-neutral.** The plain difference is
 * the reference's definition and it silently assumes β = 1 — that the asset
 * moves one-for-one with the basket. Almost nothing does. An asset with
 * β = 1.6 in a market that rises 1 % is expected to rise 1.6 %, so the plain
 * form credits it with +0.6 % of "strength" that is nothing but leverage on
 * the same market move; in a falling market the identical asset looks weak for
 * the identical reason. Sorted by plain DD, a cross-section of assets sorts
 * mostly by beta, and a strategy built on it is a leveraged market bet wearing
 * a market-neutral label. Subtracting β · r_basket removes exactly that.
 *
 * `value()` is therefore the **beta-adjusted** figure. The reference form is
 * available as `values()['excess']`, and it is defined earlier — as soon as
 * the basket has a return — because it needs no estimation window at all.
 *
 * **A rolling OLS β is not the best β this library can offer.** The window
 * here is a flat one: every observation inside it counts the same, the oldest
 * one counts as much as the newest, and then it drops out and the estimate
 * steps for no reason (the same defect the flat window has in
 * `RealizedVolatility`). β is not constant — it moves with the regime, and a
 * 60-observation window can only ever report where it was on average over
 * those 60 observations. The library's `TimeVaryingBeta` model estimates β as
 * a **filter state**: it updates on every observation, carries its own
 * variance and therefore a confidence band, and its innovation ỹ is already
 * the market-cleaned excess return with the √S to standardise it. Use it when
 * the β itself matters; see `docs/models/time-varying-beta.md`. The rolling
 * regression is kept because it is what everyone else computes, and a number
 * you can reconcile with the rest of the industry has its own value.
 *
 * Like `BasketReturn` this is not a `PriceMetric`: it needs the asset and
 * every constituent in the same update.
 */
final class RelativeStrength implements Metric
{
	/** Reserved key under which the kernels feed the asset into the price map. */
	private const ASSET_KEY = '@asset';

	/** @var list<string> */
	public readonly array $constituents;

	private BasketReturn $basket;

	private RingBuffer $assetWindow;

	private RingBuffer $basketWindow;

	private float $previousAsset = \NAN;

	private float $assetReturn = \NAN;

	private float $basketReturn = \NAN;

	private float $beta = \NAN;

	private bool $betaStale = true;

	/**
	 * @param string $asset the instrument being measured; it may or may not be part of the basket
	 * @param list<string> $constituents the explicit basket
	 * @param int $betaWindow observations in the rolling OLS regression
	 * @param int $window window of the basket's own weighting
	 */
	public function __construct(
		public readonly string $asset,
		array $constituents,
		public readonly BasketWeighting $weighting = BasketWeighting::Equal,
		public readonly int $betaWindow = 60,
		public readonly int $window = 60,
	) {
		if ($asset === '') {
			throw new InvalidArgument('RelativeStrength needs a non-empty asset symbol');
		}
		if ($betaWindow < 2) {
			throw new InvalidArgument(\sprintf('RelativeStrength beta window must be >= 2, got %d', $betaWindow));
		}
		$this->constituents = $constituents;
		$this->basket = new BasketReturn($constituents, $weighting, $window);
		$this->assetWindow = new RingBuffer($betaWindow);
		$this->basketWindow = new RingBuffer($betaWindow);
	}

	public static function type(): string
	{
		return 'relative-strength';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-25',
			symbol: 'DD',
			category: MetricCategory::CrossAsset,
			inputs: [MetricInput::Multi],
			kernels: ['RelativeStrength::excess()', 'RelativeStrength::betaAdjusted()', 'RelativeStrength::rollingBeta()'],
			nameEn: 'Relative strength',
			nameRu: 'Относительная сила',
			algoEn: 'What the asset did that the market did not. The plain difference assumes beta = 1 and therefore ranks assets by leverage, not by strength; subtracting beta times the market removes it, and TimeVaryingBeta does it better still.',
			algoRu: 'То, что сделал актив, а рынок — нет. Простая разность подразумевает β = 1 и потому ранжирует активы по плечу, а не по силе; вычитание β·рынок это убирает, а TimeVaryingBeta делает это ещё лучше.',
			plainEn: 'Excess return of one instrument over its basket, plain and beta-adjusted, with the rolling beta itself.',
			plainRu: 'Избыточная доходность инструмента над корзиной, простая и с поправкой на β, вместе с самой скользящей β.',
			example: 'examples/cross-relative-strength.php',
		);
	}

	/**
	 * @param array<string, float> $prices symbol => price; the asset and every constituent must be present
	 * @param array<string, float> $sizes symbol => traded size; required by BasketWeighting::Volume
	 */
	public function update(int $timestampNs, array $prices, array $sizes = []): void
	{
		if (!isset($prices[$this->asset])) {
			throw new InvalidArgument(\sprintf('RelativeStrength has no price for asset "%s"', $this->asset));
		}
		$price = $prices[$this->asset];
		if (!($price > 0.0)) {
			throw new InvalidArgument(\sprintf('RelativeStrength needs strictly positive prices, got %g for "%s"', $price, $this->asset));
		}
		$previous = $this->previousAsset;
		$this->previousAsset = $price;
		$this->assetReturn = \is_nan($previous) ? \NAN : \log($price / $previous);
		$this->basket->update($timestampNs, $prices, $sizes);
		$this->basketReturn = $this->basket->value();
		if (!\is_nan($this->assetReturn) && !\is_nan($this->basketReturn)) {
			$this->assetWindow->push($this->assetReturn);
			$this->basketWindow->push($this->basketReturn);
			$this->betaStale = true;
		}
	}

	/** True once the regression window is full; the plain excess is defined earlier. */
	public function isReady(): bool
	{
		return $this->assetWindow->isFull();
	}

	/** The beta-adjusted excess return — the market-neutral one. */
	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		$beta = $this->beta();
		return \is_nan($beta) || \is_nan($this->assetReturn) || \is_nan($this->basketReturn)
			? \NAN
			: $this->assetReturn - $beta * $this->basketReturn;
	}

	/** The reference form, r_asset − r_basket; defined as soon as the basket is. */
	public function excessValue(): float
	{
		return \is_nan($this->assetReturn) || \is_nan($this->basketReturn)
			? \NAN
			: $this->assetReturn - $this->basketReturn;
	}

	/** Rolling OLS slope of the asset's returns on the basket's; NAN until the window is full. */
	public function beta(): float
	{
		if (!$this->assetWindow->isFull()) {
			return \NAN;
		}
		if ($this->betaStale) {
			$this->beta = self::olsBeta($this->basketWindow->toList(), $this->assetWindow->toList());
			$this->betaStale = false;
		}
		return $this->beta;
	}

	/** @return array{betaAdjusted: float, excess: float, beta: float, assetReturn: float, basketReturn: float} */
	public function values(): array
	{
		return [
			'betaAdjusted' => $this->value(),
			'excess' => $this->excessValue(),
			'beta' => $this->beta(),
			'assetReturn' => $this->assetReturn,
			'basketReturn' => $this->basketReturn,
		];
	}

	public function reset(): void
	{
		$this->basket->reset();
		$this->assetWindow->reset();
		$this->basketWindow->reset();
		$this->previousAsset = \NAN;
		$this->assetReturn = \NAN;
		$this->basketReturn = \NAN;
		$this->beta = \NAN;
		$this->betaStale = true;
	}

	/** @return array{type: string, asset: string, constituents: list<string>, weighting: string, betaWindow: int, window: int} */
	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'asset' => $this->asset,
			'constituents' => $this->constituents,
			'weighting' => $this->weighting->value,
			'betaWindow' => $this->betaWindow,
			'window' => $this->window,
		];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		if (!isset($config['asset']) || !\is_string($config['asset'])) {
			throw new InvalidArgument('RelativeStrength config requires a string "asset"');
		}
		return new self(
			$config['asset'],
			BasketReturn::symbolList($config, 'constituents'),
			BasketReturn::weightingOf($config, 'weighting'),
			isset($config['betaWindow']) && \is_int($config['betaWindow']) ? $config['betaWindow'] : 60,
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 60,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * The reference form r_asset − r_basket over whole series.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $assetPrices
	 * @param array<string, list<float>> $basket symbol => price series, same length as the asset series
	 * @param array<string, list<float>> $sizes symbol => traded size series; required by the "volume" weighting
	 * @return list<float>
	 */
	public static function excess(
		array $assetPrices,
		array $basket,
		string $weighting = 'equal',
		int $window = 60,
		array $sizes = [],
	): array {
		return self::run($assetPrices, $basket, $weighting, 2, $window, $sizes)['excess'];
	}

	/**
	 * r_asset − β · r_basket with β from a rolling regression; NAN until the
	 * regression window is full.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $assetPrices
	 * @param array<string, list<float>> $basket
	 * @param array<string, list<float>> $sizes
	 * @return list<float>
	 */
	public static function betaAdjusted(
		array $assetPrices,
		array $basket,
		int $betaWindow = 60,
		string $weighting = 'equal',
		int $window = 60,
		array $sizes = [],
	): array {
		return self::run($assetPrices, $basket, $weighting, $betaWindow, $window, $sizes)['betaAdjusted'];
	}

	/**
	 * The rolling OLS slope of one return series on another — the primitive
	 * the streaming object uses, exposed on its own because a β is worth
	 * looking at directly.
	 *
	 * Returns are log returns, i.e. centred near zero, which is the one regime
	 * where the raw sums behind a covariance are safe; the window is
	 * recomputed exactly at every step, so nothing accumulates.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $assetReturns
	 * @param list<float> $basketReturns
	 * @return list<float> aligned with the input, NAN for the first window−1 entries
	 */
	public static function rollingBeta(array $assetReturns, array $basketReturns, int $window = 60): array
	{
		if ($window < 2) {
			throw new InvalidArgument(\sprintf('RelativeStrength beta window must be >= 2, got %d', $window));
		}
		$n = \count($assetReturns);
		if (\count($basketReturns) !== $n) {
			throw new InvalidArgument('RelativeStrength needs asset and basket return series of equal length');
		}
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$out[] = $i + 1 < $window
				? \NAN
				: self::olsBeta(
					\array_slice($basketReturns, $i + 1 - $window, $window),
					\array_slice($assetReturns, $i + 1 - $window, $window),
				);
		}
		return $out;
	}

	/**
	 * Cov(y, x) / Var(x) over the whole array, which is the window. NAN when
	 * the regressor does not move: a flat market carries no information about
	 * β, and reporting a zero there would be a fabricated answer.
	 *
	 * @param list<float> $x the regressor, i.e. the basket returns
	 * @param list<float> $y the regressand, i.e. the asset returns
	 */
	private static function olsBeta(array $x, array $y): float
	{
		$n = \count($x);
		if ($n < 2 || \count($y) !== $n) {
			return \NAN;
		}
		$meanX = 0.0;
		$meanY = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$meanX += $x[$i];
			$meanY += $y[$i];
		}
		$meanX /= $n;
		$meanY /= $n;
		$covariance = 0.0;
		$variance = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$dx = $x[$i] - $meanX;
			$covariance += $dx * ($y[$i] - $meanY);
			$variance += $dx * $dx;
		}
		return $variance > 0.0 ? $covariance / $variance : \NAN;
	}

	/**
	 * Drives the streaming object over whole series, so the batch and live
	 * paths cannot drift apart.
	 *
	 * @param list<float> $assetPrices
	 * @param array<string, list<float>> $basket
	 * @param array<string, list<float>> $sizes
	 * @return array{excess: list<float>, betaAdjusted: list<float>, beta: list<float>}
	 */
	private static function run(
		array $assetPrices,
		array $basket,
		string $weighting,
		int $betaWindow,
		int $window,
		array $sizes,
	): array {
		if (isset($basket[self::ASSET_KEY])) {
			throw new InvalidArgument(\sprintf('"%s" is reserved and cannot be a basket constituent', self::ASSET_KEY));
		}
		$symbols = \array_keys($basket);
		$length = \count($assetPrices);
		$mode = BasketWeighting::from($weighting);
		$useSizes = $mode->needsSizes();
		foreach ($symbols as $symbol) {
			$column = $basket[$symbol] ?? [];
			if (\count($column) !== $length) {
				throw new InvalidArgument('RelativeStrength needs basket series as long as the asset series');
			}
			if ($useSizes && (!isset($sizes[$symbol]) || \count($sizes[$symbol]) !== $length)) {
				throw new InvalidArgument(\sprintf('RelativeStrength volume weighting needs a size series of length %d for "%s"', $length, $symbol));
			}
		}
		$metric = new self(self::ASSET_KEY, $symbols, $mode, $betaWindow, $window);
		$excess = [];
		$betaAdjusted = [];
		$beta = [];
		for ($i = 0; $i < $length; $i++) {
			$prices = [self::ASSET_KEY => $assetPrices[$i] ?? \NAN];
			$row = [];
			foreach ($symbols as $symbol) {
				$column = $basket[$symbol] ?? [];
				$prices[$symbol] = $column[$i] ?? \NAN;
				if ($useSizes) {
					$sizeColumn = $sizes[$symbol] ?? [];
					$row[$symbol] = $sizeColumn[$i] ?? \NAN;
				}
			}
			$metric->update(0, $prices, $row);
			$excess[] = $metric->excessValue();
			$betaAdjusted[] = $metric->value();
			$beta[] = $metric->beta();
		}
		return ['excess' => $excess, 'betaAdjusted' => $betaAdjusted, 'beta' => $beta];
	}
}
