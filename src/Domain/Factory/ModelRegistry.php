<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Factory;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Maps serialisable model type names to classes so that a model config
 * array can be rebuilt in another process (worker, queue consumer).
 * Built-in models are registered lazily; applications may register their own.
 */
final class ModelRegistry
{
	/** @var array<string, class-string<SerializableModel>>|null */
	private static ?array $types = null;

	private function __construct()
	{
	}

	/** @param class-string<SerializableModel> $class */
	public static function register(string $class): void
	{
		self::ensureBuiltins();
		if (!\is_subclass_of($class, SerializableModel::class)) {
			throw new InvalidArgument(\sprintf('%s does not implement %s', $class, SerializableModel::class));
		}
		self::$types[$class::type()] = $class;
	}

	/** @return class-string<SerializableModel> */
	public static function classFor(string $type): string
	{
		self::ensureBuiltins();
		if (!isset(self::$types[$type])) {
			throw new InvalidArgument(\sprintf('Unknown model type "%s"', $type));
		}
		return self::$types[$type];
	}

	/** @param array<string, mixed> $config */
	public static function build(array $config): SerializableModel
	{
		if (!isset($config['type']) || !\is_string($config['type'])) {
			throw new InvalidArgument('Model config requires a string "type" key');
		}
		$class = self::classFor($config['type']);
		return $class::fromArray($config);
	}

	/** @return list<string> */
	public static function types(): array
	{
		self::ensureBuiltins();
		return \array_keys(self::$types ?? []);
	}

	private static function ensureBuiltins(): void
	{
		if (self::$types !== null) {
			return;
		}
		self::$types = [];
		foreach (self::builtins() as $class) {
			self::$types[$class::type()] = $class;
		}
	}

	/** @return list<class-string<SerializableModel>> */
	private static function builtins(): array
	{
		$classes = [
			\OpenCCK\Kalman\Domain\Model\Generic\RandomWalk::class,
			\OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity::class,
			\OpenCCK\Kalman\Domain\Model\Generic\ConstantAcceleration::class,
			\OpenCCK\Kalman\Domain\Model\Generic\OrnsteinUhlenbeck::class,
			\OpenCCK\Kalman\Domain\Model\Generic\Singer::class,
			\OpenCCK\Kalman\Domain\Model\Generic\BlockDiagonal::class,
			\OpenCCK\Kalman\Domain\Model\Generic\DenseLinear::class,
			\OpenCCK\Kalman\Domain\Model\Generic\DiscreteLinear::class,
			\OpenCCK\Kalman\Domain\Model\Generic\DiscreteWhiteNoiseAcceleration::class,
			\OpenCCK\Kalman\Domain\Model\Observation\StaticObservation::class,
			\OpenCCK\Kalman\Domain\Model\Observation\MutableObservation::class,
			\OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend::class,
			\OpenCCK\Kalman\Domain\Model\Finance\EtfBasket::class,
			\OpenCCK\Kalman\Domain\Model\Finance\PairsHedge::class,
			\OpenCCK\Kalman\Domain\Model\Finance\MultiVenue::class,
			\OpenCCK\Kalman\Domain\Model\Finance\TimeVaryingBeta::class,
			\OpenCCK\Kalman\Domain\Model\Finance\StochasticVolatility::class,
			\OpenCCK\Kalman\Domain\Model\Finance\Microprice::class,
			\OpenCCK\Kalman\Domain\Model\Finance\NelsonSiegel::class,
			\OpenCCK\Kalman\Domain\Model\Finance\BidAskBounce::class,
		];
		/** @var list<class-string<SerializableModel>> $existing */
		$existing = [];
		foreach ($classes as $class) {
			if (\class_exists($class)) {
				$existing[] = $class;
			}
		}
		return $existing;
	}
}
