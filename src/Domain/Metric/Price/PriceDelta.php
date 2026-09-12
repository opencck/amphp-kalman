<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Price;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RingBuffer;

/**
 * Return over a fixed lookback (ROADMAP §4.1 M-03), in both forms:
 *
 *   simple: R = P_t / P_{t−h} − 1
 *   log:    r = ln(P_t / P_{t−h})
 *
 * The log form is the default because it is additive: the sum of two
 * consecutive hourly log returns is the two-hour log return, while the sum of
 * two simple returns is not the two-hour simple return. Anything that sums,
 * averages or regresses returns — a basket, a momentum score, a beta — is
 * wrong by a second-order term if it uses the simple form, and the error grows
 * with volatility, which is exactly when the signal matters.
 */
final class PriceDelta implements PriceMetric
{
	private RingBuffer $history;

	private float $price = \NAN;

	public function __construct(public readonly int $lag = 1)
	{
		if ($lag < 1) {
			throw new InvalidArgument(\sprintf('PriceDelta lag must be >= 1, got %d', $lag));
		}
		$this->history = new RingBuffer($lag + 1);
	}

	public static function type(): string
	{
		return 'price-delta';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-03',
			symbol: 'dP',
			category: MetricCategory::Price,
			inputs: [MetricInput::Ticks],
			kernels: ['PriceDelta::log()', 'PriceDelta::simple()'],
			nameEn: 'Price delta',
			nameRu: 'Дельта цены',
			algoEn: 'The plainest momentum reading, over any horizon. Use the log form so that returns add up across horizons and across a basket.',
			algoRu: 'Простейшая мера импульса на любом горизонте. Логарифмическая форма складывается по горизонтам и по корзине.',
			plainEn: 'Return over a fixed lookback, as a plain ratio minus one or as a logarithm.',
			plainRu: 'Доходность за фиксированный лаг, в виде отношения минус единица или логарифма.',
			example: 'examples/price-delta.php',
		);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		$this->price = $price;
		$this->history->push($price);
	}

	public function isReady(): bool
	{
		return $this->history->isFull();
	}

	/** The log return. */
	public function value(): float
	{
		return $this->isReady() ? self::logReturn($this->price, $this->history->oldest()) : \NAN;
	}

	/** @return array{log: float, simple: float} */
	public function values(): array
	{
		if (!$this->isReady()) {
			return ['log' => \NAN, 'simple' => \NAN];
		}
		$past = $this->history->oldest();
		return [
			'log' => self::logReturn($this->price, $past),
			'simple' => self::simpleReturn($this->price, $past),
		];
	}

	public function reset(): void
	{
		$this->history->reset();
		$this->price = \NAN;
	}

	/** @return array{type: string, lag: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'lag' => $this->lag];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(isset($config['lag']) && \is_int($config['lag']) ? $config['lag'] : 1);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * ln(P_t / P_{t−lag}).
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> aligned with the input, NAN for the first `lag` entries
	 */
	public static function log(array $prices, int $lag): array
	{
		self::assertLag($lag);
		$out = [];
		foreach ($prices as $i => $price) {
			$past = $i - $lag;
			$out[] = $past < 0 ? \NAN : self::logReturn($price, $prices[$past]);
		}
		return $out;
	}

	/**
	 * P_t / P_{t−lag} − 1.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function simple(array $prices, int $lag): array
	{
		self::assertLag($lag);
		$out = [];
		foreach ($prices as $i => $price) {
			$past = $i - $lag;
			$out[] = $past < 0 ? \NAN : self::simpleReturn($price, $prices[$past]);
		}
		return $out;
	}

	private static function logReturn(float $price, float $past): float
	{
		return $price > 0.0 && $past > 0.0 ? \log($price / $past) : \NAN;
	}

	private static function simpleReturn(float $price, float $past): float
	{
		return $past > 0.0 ? $price / $past - 1.0 : \NAN;
	}

	private static function assertLag(int $lag): void
	{
		if ($lag < 1) {
			throw new InvalidArgument(\sprintf('PriceDelta lag must be >= 1, got %d', $lag));
		}
	}
}
