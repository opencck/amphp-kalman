<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\Metric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;

/**
 * Maps metric type names to classes, so a metric configuration can cross a
 * process boundary, and holds the machine-readable catalogue of measurements
 * (ROADMAP §5).
 *
 * The catalogue matters as much as the factory. The summary tables in
 * `examples/README.md` and `examples/README.ru.md` are **generated** from
 * `describeAll()` by `tools/generate-metric-index.php`, and
 * `tests/Architecture/MetricCatalogueTest` fails the build when the committed
 * tables no longer match the code or when a row points at an example file that
 * does not exist. Documentation that is derived cannot drift from what it
 * documents; documentation that is transcribed always does.
 *
 * Applications register their own metrics with `register()`; the built-ins are
 * loaded lazily on first use, exactly as `Factory\ModelRegistry` does for
 * models.
 */
final class MetricRegistry
{
	private const BUILTINS = [
		Price\NormalizedPrice::class,
		Price\PriceDelta::class,
		Price\MeanPriceDifference::class,
		Momentum\Rsi::class,
		Momentum\Stochastic::class,
		Momentum\Cci::class,
		Momentum\Momentum::class,
		Trend\MovingAverage::class,
		Trend\Macd::class,
		Trend\MaSpread::class,
		Trend\Adx::class,
		Volume\VolumeProfile::class,
		Volume\Vwap::class,
		Liquidity\OrderBookImbalance::class,
		Liquidity\LiquidityDensity::class,
		Liquidity\BookSlope::class,
		Liquidity\CostToTrade::class,
		Liquidity\WeightedPrices::class,
		Liquidity\Spread::class,
		Volatility\Atr::class,
		Volatility\OhlcVolatility::class,
		Volatility\RealizedVolatility::class,
		Volatility\RangeVolatility::class,
		Microstructure\TradeClassifier::class,
		Microstructure\OrderFlowImbalance::class,
		Microstructure\Vpin::class,
		Microstructure\PriceImpact::class,
		Cross\BasketReturn::class,
		Cross\RelativeStrength::class,
		Cross\MarketStrength::class,
		Filtered\Derivative::class,
		Regime\FlatMarket::class,
	];

	/** @var array<string, class-string<Metric>>|null */
	private static ?array $types = null;

	private function __construct()
	{
	}

	/** @param class-string<Metric> $class */
	public static function register(string $class): void
	{
		self::ensureBuiltins();
		if (!\is_subclass_of($class, Metric::class)) {
			throw new InvalidArgument(\sprintf('%s does not implement %s', $class, Metric::class));
		}
		self::$types[$class::type()] = $class;
	}

	/** @return class-string<Metric> */
	public static function classFor(string $type): string
	{
		self::ensureBuiltins();
		if (!isset(self::$types[$type])) {
			throw new InvalidArgument(\sprintf('Unknown metric type "%s"', $type));
		}
		return self::$types[$type];
	}

	/** @param array<string, mixed> $config */
	public static function build(array $config): Metric
	{
		if (!isset($config['type']) || !\is_string($config['type'])) {
			throw new InvalidArgument('Metric config requires a string "type" key');
		}
		return self::classFor($config['type'])::fromArray($config);
	}

	/** @return list<string> */
	public static function types(): array
	{
		self::ensureBuiltins();
		return \array_keys(self::$types ?? []);
	}

	public static function describe(string $type): MetricDescriptor
	{
		return self::classFor($type)::describe();
	}

	/**
	 * Every catalogue card, ordered by category and then by catalogue number —
	 * the order the generated tables appear in.
	 *
	 * @return list<MetricDescriptor>
	 */
	public static function describeAll(): array
	{
		self::ensureBuiltins();
		$cards = [];
		foreach (self::$types ?? [] as $class) {
			$cards[] = $class::describe();
		}
		\usort($cards, static function (MetricDescriptor $a, MetricDescriptor $b): int {
			return [$a->category->rank(), $a->id] <=> [$b->category->rank(), $b->id];
		});
		return $cards;
	}

	private static function ensureBuiltins(): void
	{
		if (self::$types !== null) {
			return;
		}
		self::$types = [];
		foreach (self::BUILTINS as $class) {
			/** @var class-string<Metric> $class */
			self::$types[$class::type()] = $class;
		}
	}
}
