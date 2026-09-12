<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Metric;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Metric\Contract\BarMetric;
use OpenCCK\Kalman\Domain\Metric\Contract\BookMetric;
use OpenCCK\Kalman\Domain\Metric\Contract\Metric;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Contract\TradeMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * The `Metric` contract, applied to every class under `src/Domain/Metric` that
 * implements it.
 *
 * The classes are discovered by scanning the directory rather than from a
 * hardcoded list, so a measurement that lands tomorrow is held to the same
 * rules without anyone remembering to add it here.
 */
final class MetricContractTest extends TestCase
{
	private const METRIC_DIR = __DIR__ . '/../../../src/Domain/Metric';

	private const NAMESPACE_PREFIX = 'OpenCCK\\Kalman\\Domain\\Metric\\';

	/** How many observations each metric is fed before `reset()` is exercised. */
	private const WARM_UP = 3000;

	/**
	 * Every instantiable class under src/Domain/Metric implementing Metric.
	 *
	 * @return list<class-string<Metric>>
	 */
	private static function discover(): array
	{
		$dir = \realpath(self::METRIC_DIR);
		self::assertIsString($dir, 'src/Domain/Metric must exist');

		$found = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $file) {
			if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
				continue;
			}
			$relative = \substr(\str_replace('\\', '/', $file->getPathname()), \strlen(\str_replace('\\', '/', $dir)) + 1);
			$class = self::NAMESPACE_PREFIX . \str_replace('/', '\\', \substr($relative, 0, -4));
			if (!\class_exists($class)) {
				continue;
			}
			$reflection = new \ReflectionClass($class);
			if (!$reflection->isInstantiable() || !$reflection->implementsInterface(Metric::class)) {
				continue;
			}
			/** @var class-string<Metric> $class */
			$found[] = $class;
		}
		\sort($found);
		return $found;
	}

	/** @return iterable<string, array{class-string<Metric>}> */
	public function metrics(): iterable
	{
		foreach (self::discover() as $class) {
			yield \substr($class, \strlen(self::NAMESPACE_PREFIX)) => [$class];
		}
	}

	/**
	 * Builds a metric with its documented defaults, filling in the few
	 * parameters that have none (a period, a symbol, a list of constituents)
	 * from their declared types — so the test keeps working when a metric with
	 * a different shape of constructor lands.
	 *
	 * @param class-string<Metric> $class
	 */
	private static function instantiate(string $class): Metric
	{
		$reflection = new \ReflectionClass($class);
		$constructor = $reflection->getConstructor();
		$arguments = [];
		if ($constructor !== null) {
			foreach ($constructor->getParameters() as $parameter) {
				if ($parameter->isDefaultValueAvailable()) {
					$arguments[] = $parameter->getDefaultValue();
					continue;
				}
				$arguments[] = self::placeholderFor($class, $parameter);
			}
		}
		$metric = $reflection->newInstanceArgs($arguments);
		self::assertInstanceOf(Metric::class, $metric);
		return $metric;
	}

	/** A value of the declared type for a constructor parameter with no default. */
	private static function placeholderFor(string $class, \ReflectionParameter $parameter): mixed
	{
		$type = $parameter->getType();
		self::assertInstanceOf(
			\ReflectionNamedType::class,
			$type,
			\sprintf('%s::__construct($%s) has no default and no simple type', $class, $parameter->getName()),
		);

		return match ($type->getName()) {
			// a symbol, and a basket that deliberately does not contain it
			'string' => 'AAA',
			'array' => ['BBB', 'CCC', 'DDD'],
			'int' => 14,
			'float' => 1.0,
			'bool' => true,
			default => self::fail(\sprintf(
				'%s::__construct($%s) needs a %s the contract test cannot invent',
				$class,
				$parameter->getName(),
				$type->getName(),
			)),
		};
	}

	/** @var list<array{ts: int, price: float, bar: Bar, trade: Trade, book: OrderBook}>|null */
	private static ?array $stream = null;

	/**
	 * One deterministic observation stream, built once and replayed for every
	 * metric. The prices are bounded and mean-reverting, so they stay positive
	 * for however many observations a metric needs to warm up.
	 *
	 * @return list<array{ts: int, price: float, bar: Bar, trade: Trade, book: OrderBook}>
	 */
	private static function stream(): array
	{
		if (self::$stream !== null) {
			return self::$stream;
		}
		\mt_srand(20240919);
		$rows = [];
		$ts = 1_700_000_000_000_000_000;
		$previous = 100.0;

		for ($i = 0; $i < self::WARM_UP; $i++) {
			$ts += 1_000_000_000;
			$price = 100.0 + 5.0 * \sin((float) $i / 37.0) + (float) \mt_rand(-100, 100) / 100.0;
			$spread = (float) \mt_rand(1, 20) / 100.0;
			$size = (float) \mt_rand(1, 500) / 10.0;
			$rows[] = [
				'ts' => $ts,
				'price' => $price,
				'bar' => Bar::of(
					$ts,
					$ts + 999,
					$previous,
					\max($previous, $price) + $spread,
					\min($previous, $price) - $spread,
					$price,
					$size,
					7,
				),
				'trade' => Trade::at($ts, $price, $size, $i % 2 === 0 ? TradeSide::Buy : TradeSide::Sell),
				'book' => self::book($ts, $price, $spread),
			];
			$previous = $price;
		}
		self::$stream = $rows;
		return $rows;
	}

	/** Feeds the metric through whichever input contracts it declares. */
	private static function warmUp(Metric $metric, int $observations): void
	{
		foreach (self::stream() as $i => $row) {
			if ($i >= $observations) {
				return;
			}
			if ($metric instanceof PriceMetric) {
				$metric->updatePrice($row['ts'], $row['price']);
			}
			if ($metric instanceof BarMetric) {
				$metric->updateBar($row['bar']);
			}
			if ($metric instanceof TradeMetric) {
				$metric->updateTrade($row['trade']);
			}
			if ($metric instanceof BookMetric) {
				$metric->updateBook($row['book']);
			}
		}
	}

	private static function book(int $timestampNs, float $mid, float $spread): OrderBook
	{
		$bids = [];
		$asks = [];
		for ($level = 0; $level < 20; $level++) {
			$offset = $spread / 2.0 + (float) $level * 0.01;
			$size = (float) (20 - $level) + (float) \mt_rand(1, 100) / 100.0;
			$bids[] = [$mid - $offset, $size];
			$asks[] = [$mid + $offset, $size];
		}
		return OrderBook::of($timestampNs, $bids, $asks);
	}

	public function testTheScanFindsTheMetricsThatAreKnownToBeThere(): void
	{
		$classes = self::discover();
		self::assertGreaterThan(10, \count($classes), 'the directory scan found suspiciously few metrics');

		// a handful of anchors, so a broken scan cannot pass by finding nothing
		foreach ([
			\OpenCCK\Kalman\Domain\Metric\Momentum\Rsi::class,
			\OpenCCK\Kalman\Domain\Metric\Momentum\Cci::class,
			\OpenCCK\Kalman\Domain\Metric\Momentum\Stochastic::class,
			\OpenCCK\Kalman\Domain\Metric\Trend\Adx::class,
			\OpenCCK\Kalman\Domain\Metric\Trend\Macd::class,
			\OpenCCK\Kalman\Domain\Metric\Trend\MovingAverage::class,
			\OpenCCK\Kalman\Domain\Metric\Volatility\Atr::class,
		] as $anchor) {
			self::assertContains($anchor, $classes);
		}
	}

	/** The registry key is unique across the library. */
	public function testEveryRegistryKeyIsUnique(): void
	{
		$seen = [];
		foreach (self::discover() as $class) {
			$type = $class::type();
			self::assertArrayNotHasKey($type, $seen, \sprintf('%s and %s share the key "%s"', $seen[$type] ?? '', $class, $type));
			$seen[$type] = $class;
		}
		self::assertSame(\count(self::discover()), \count($seen));
	}

	/**
	 * @dataProvider metrics
	 * @param class-string<Metric> $class
	 */
	public function testTypeIsNonEmpty(string $class): void
	{
		$type = $class::type();
		self::assertNotSame('', $type, $class . '::type() must not be empty');
		self::assertSame(
			$type,
			\strtolower($type),
			$class . '::type() is a registry key: lower case, like "rsi" or "obi"',
		);
		self::assertSame($type, $class::type(), 'type() must be stable');
	}

	/**
	 * @dataProvider metrics
	 * @param class-string<Metric> $class
	 */
	public function testDescribeReturnsADescriptorWhoseTypeMatches(string $class): void
	{
		$descriptor = $class::describe();
		self::assertInstanceOf(MetricDescriptor::class, $descriptor);
		self::assertSame($class::type(), $descriptor->type, $class . ': descriptor type must match type()');
		self::assertNotSame('', $descriptor->id);
		self::assertNotSame('', $descriptor->symbol);
		self::assertNotSame('', $descriptor->nameEn);
		self::assertNotSame('', $descriptor->nameRu);
		self::assertNotSame([], $descriptor->inputs, $class . ': a metric must declare what it consumes');
		self::assertNotSame([], $descriptor->kernels, $class . ': a metric must declare its offloadable kernels');
		self::assertSame($descriptor->type, $descriptor->toArray()['type']);
	}

	/**
	 * The set of output names is part of the contract: a consumer keyed on
	 * them must not have to re-inspect the metric after every update.
	 *
	 * @dataProvider metrics
	 * @param class-string<Metric> $class
	 */
	public function testValuesKeysAreStableBetweenCalls(string $class): void
	{
		$metric = self::instantiate($class);

		$keys = \array_keys($metric->values());
		self::assertNotSame([], $keys, $class . '::values() must expose at least one output');
		foreach ($keys as $key) {
			self::assertNotSame('', $key, $class . ': an output name must not be empty');
		}
		self::assertSame($keys, \array_keys($metric->values()), $class . ': two calls in a row disagree');

		self::warmUp($metric, 1);
		self::assertSame($keys, \array_keys($metric->values()), $class . ': keys changed after one observation');

		self::warmUp($metric, self::WARM_UP);
		self::assertSame($keys, \array_keys($metric->values()), $class . ': keys changed once warm');

		$metric->reset();
		self::assertSame($keys, \array_keys($metric->values()), $class . ': keys changed after reset()');
	}

	/**
	 * `Metric::values()` is declared `array<string, float>`, and a consumer
	 * reading it — a Prometheus exporter, a JSON snapshot — depends on that:
	 * an integer 0 where 0.0 was promised serialises differently and compares
	 * differently.
	 *
	 * @dataProvider metrics
	 * @param class-string<Metric> $class
	 */
	public function testEveryOutputIsAFloat(string $class): void
	{
		$metric = self::instantiate($class);
		foreach ($metric->values() as $name => $value) {
			self::assertIsFloat($value, \sprintf('%s::values()["%s"] before warm-up', $class, $name));
		}

		self::warmUp($metric, self::WARM_UP);
		foreach ($metric->values() as $name => $value) {
			self::assertIsFloat($value, \sprintf('%s::values()["%s"] once warm', $class, $name));
		}
	}

	/**
	 * `reset()` drops all accumulated state: the metric is back in its
	 * pre-warm-up state and has to fill its window again.
	 *
	 * @dataProvider metrics
	 * @param class-string<Metric> $class
	 */
	public function testResetReturnsTheMetricToItsPreWarmUpState(string $class): void
	{
		$metric = self::instantiate($class);
		self::assertFalse($metric->isReady(), $class . ' must not be ready before it has seen anything');
		self::assertNan($metric->value(), $class . '::value() must be NAN, never a silent zero, before warm-up');

		self::warmUp($metric, self::WARM_UP);

		$metric->reset();
		self::assertFalse($metric->isReady(), $class . '::reset() must return it to the pre-warm-up state');
		self::assertNan($metric->value(), $class . '::value() must be NAN again after reset()');

		// and it warms up again from scratch, reaching the same state as a
		// freshly constructed instance fed the same stream
		self::warmUp($metric, self::WARM_UP);
		$fresh = self::instantiate($class);
		self::warmUp($fresh, self::WARM_UP);
		self::assertSame($fresh->isReady(), $metric->isReady(), $class . ': a reset metric must behave like a new one');
		$message = $class . ': a reset metric must produce the same value as a new one';
		if (\is_nan($fresh->value())) {
			self::assertNan($metric->value(), $message);
		} else {
			self::assertEqualsWithDelta($fresh->value(), $metric->value(), 1e-9, $message);
		}
	}

	/**
	 * `toArray()` carries the constructor parameters across a process
	 * boundary, so `fromArray(toArray())` has to come back with the same
	 * configuration.
	 *
	 * @dataProvider metrics
	 * @param class-string<Metric> $class
	 */
	public function testFromArrayRoundTripsToArray(string $class): void
	{
		$metric = self::instantiate($class);
		$config = $metric->toArray();

		self::assertArrayHasKey('type', $config, $class . '::toArray() must name the metric');
		self::assertSame($class::type(), $config['type']);

		$restored = $class::fromArray($config);
		self::assertInstanceOf($class, $restored);
		self::assertSame($config, $restored->toArray(), $class . ': fromArray(toArray()) must round-trip');

		// and once more, to catch a configuration that decays on each hop
		self::assertSame($config, $class::fromArray($restored->toArray())->toArray());

		// the JSON hop the config actually makes (BRIEF §2 p.1)
		$json = \json_encode($config, \JSON_THROW_ON_ERROR);
		$decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		self::assertSame($config, $class::fromArray($decoded)->toArray());
	}

	/**
	 * The warm-up stream has to actually warm metrics up, or every assertion
	 * about readiness above would pass vacuously.
	 */
	public function testTheWarmUpStreamMakesMostMetricsReady(): void
	{
		$ready = [];
		$notReady = [];
		foreach (self::discover() as $class) {
			$metric = self::instantiate($class);
			self::warmUp($metric, self::WARM_UP);
			if ($metric->isReady()) {
				$ready[] = $class;
			} else {
				$notReady[] = $class;
			}
		}
		self::assertGreaterThan(
			\count($notReady),
			\count($ready),
			'most metrics must warm up on ' . self::WARM_UP . ' observations; not ready: ' . \implode(', ', $notReady),
		);
	}
}
