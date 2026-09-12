<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Liquidity;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BookMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;

/**
 * Liquidity density: how much size rests within a narrow band around the mid
 * (ROADMAP §4.5 M-17).
 *
 *   LD = Σ_levels size_i · w(|p_i − mid| / mid)
 *
 * High density means a tight spread and small slippage; a sudden collapse in
 * density is one of the cleanest warnings that a fast move is coming, because
 * makers pull quotes before price moves, not after.
 *
 * This class exists to replace a reference implementation that had three
 * defects, all of which are addressed here rather than reproduced:
 *
 * 1. **The mid was wrong.** The reference read the best bid and ask with
 *    `array_slice($side, 0, 1)` from a map keyed by price and mutated in
 *    place, which returns the first level *by insertion order*. After any
 *    update that adds a level, that is an arbitrary price, and every figure
 *    derived from the mid was centred on it. `OrderBook` sorts, so the best
 *    quote is the best quote.
 * 2. **The band was a step function.** `distance <= 0.1 %` counts a level
 *    fully or not at all, so a level sitting at the boundary switches the
 *    reading on and off as the mid ticks. `DensityKernel::Triangular` and
 *    `::Exponential` make the measure continuous in price.
 * 3. **The result had no scale.** A bare sum of sizes cannot be compared
 *    between instruments, or between two days of the same instrument.
 *    `share` divides by the whole book, `notional` expresses it in quote
 *    currency; both are dimensionally meaningful.
 *
 * The default band of 10 basis points is the reference's 0.1 %, so
 * `DensityKernel::Rectangular` with `raw` scaling reproduces the original
 * figure exactly — on a correctly sorted book.
 */
final class LiquidityDensity implements BookMetric
{
	private float $density = \NAN;

	private float $notional = \NAN;

	private float $total = \NAN;

	public function __construct(
		public readonly float $bandBps = 10.0,
		public readonly DensityKernel $kernel = DensityKernel::Rectangular,
	) {
		if (!($bandBps > 0.0) || !\is_finite($bandBps)) {
			throw new InvalidArgument('LiquidityDensity band must be finite and > 0');
		}
	}

	public static function type(): string
	{
		return 'liquidity-density';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-17',
			symbol: 'LD',
			category: MetricCategory::Liquidity,
			inputs: [MetricInput::Book],
			kernels: ['LiquidityDensity::within()', 'LiquidityDensity::share()', 'LiquidityDensity::notional()'],
			nameEn: 'Liquidity density',
			nameRu: 'Плотность ликвидности',
			algoEn: 'How much size rests next to the market. A sudden drop precedes fast moves, because makers pull quotes before price moves rather than after.',
			algoRu: 'Сколько объёма стоит вплотную к рынку. Резкое падение предшествует быстрым движениям, потому что маркет-мейкеры снимают котировки до движения цены, а не после.',
			plainEn: 'Volume resting within a basis-point band around the mid price, optionally weighted by distance and normalised by the whole book.',
			plainRu: 'Объём в полосе шириной в базисных пунктах вокруг середины спреда, при необходимости взвешенный по расстоянию и нормированный на весь стакан.',
			example: 'examples/liquidity-density.php',
		);
	}

	public function updateBook(OrderBook $book): void
	{
		if ($book->isEmpty()) {
			$this->density = \NAN;
			$this->notional = \NAN;
			$this->total = \NAN;
			return;
		}
		$mid = $book->mid();
		$this->density = self::accumulate($book, $mid, $this->bandBps, $this->kernel, false);
		$this->notional = self::accumulate($book, $mid, $this->bandBps, $this->kernel, true);
		$this->total = $book->bidVolume() + $book->askVolume();
	}

	public function isReady(): bool
	{
		return !\is_nan($this->density);
	}

	public function value(): float
	{
		return $this->density;
	}

	/** @return array{density: float, share: float, notional: float, bookVolume: float} */
	public function values(): array
	{
		return [
			'density' => $this->density,
			'share' => $this->total > 0.0 ? $this->density / $this->total : \NAN,
			'notional' => $this->notional,
			'bookVolume' => $this->total,
		];
	}

	public function reset(): void
	{
		$this->density = \NAN;
		$this->notional = \NAN;
		$this->total = \NAN;
	}

	/** @return array{type: string, bandBps: float, kernel: string} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'bandBps' => $this->bandBps, 'kernel' => $this->kernel->value];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['bandBps']) && (\is_float($config['bandBps']) || \is_int($config['bandBps']))
				? (float) $config['bandBps']
				: 10.0,
			isset($config['kernel']) && \is_string($config['kernel'])
				? DensityKernel::from($config['kernel'])
				: DensityKernel::Rectangular,
		);
	}

	private static function accumulate(OrderBook $book, float $mid, float $bandBps, DensityKernel $kernel, bool $asNotional): float
	{
		$sum = self::side($book->bidPrices, $book->bidSizes, $mid, $bandBps, $kernel, $asNotional);
		return $sum + self::side($book->askPrices, $book->askSizes, $mid, $bandBps, $kernel, $asNotional);
	}

	/**
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 */
	private static function side(
		array $prices,
		array $sizes,
		float $mid,
		float $bandBps,
		DensityKernel $kernel,
		bool $asNotional,
	): float {
		$n = \count($prices);
		$sum = 0.0;
		$hardCut = $kernel !== DensityKernel::Exponential;
		for ($i = 0; $i < $n; $i++) {
			$price = $prices[$i];
			$distanceBps = \abs($price - $mid) / $mid * 10000.0;
			// Sides are sorted by distance from the mid, so with a kernel that
			// truly vanishes we can stop at the first level outside the band
			// instead of scanning two hundred untouched levels per tick.
			if ($hardCut && $distanceBps > $bandBps) {
				break;
			}
			$weight = $kernel->weight($distanceBps, $bandBps);
			$size = $sizes[$i] * $weight;
			$sum += $asNotional ? $size * $price : $size;
		}
		return $sum;
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Volume within the band, in base units — the reference figure, on a
	 * correctly sorted book.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices descending
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices ascending
	 * @param list<float> $askSizes
	 * @param string $kernel "rectangular", "triangular" or "exponential"
	 */
	public static function within(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		float $bandBps = 10.0,
		string $kernel = 'rectangular',
	): float {
		if ($bidPrices === [] || $askPrices === []) {
			return \NAN;
		}
		$mid = ($bidPrices[0] + $askPrices[0]) / 2.0;
		$shape = DensityKernel::from($kernel);
		return self::side($bidPrices, $bidSizes, $mid, $bandBps, $shape, false)
			+ self::side($askPrices, $askSizes, $mid, $bandBps, $shape, false);
	}

	/**
	 * The same volume as a fraction of the whole book: dimensionless, and
	 * therefore comparable across instruments and across days.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 */
	public static function share(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		float $bandBps = 10.0,
		string $kernel = 'rectangular',
	): float {
		$inside = self::within($bidPrices, $bidSizes, $askPrices, $askSizes, $bandBps, $kernel);
		if (\is_nan($inside)) {
			return \NAN;
		}
		$total = 0.0;
		foreach ($bidSizes as $size) {
			$total += $size;
		}
		foreach ($askSizes as $size) {
			$total += $size;
		}
		return $total > 0.0 ? $inside / $total : \NAN;
	}

	/**
	 * Value resting in the band, in quote currency — the form a sizing rule
	 * can use directly ("how much can I lift inside ten basis points").
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 */
	public static function notional(
		array $bidPrices,
		array $bidSizes,
		array $askPrices,
		array $askSizes,
		float $bandBps = 10.0,
		string $kernel = 'rectangular',
	): float {
		if ($bidPrices === [] || $askPrices === []) {
			return \NAN;
		}
		$mid = ($bidPrices[0] + $askPrices[0]) / 2.0;
		$shape = DensityKernel::from($kernel);
		return self::side($bidPrices, $bidSizes, $mid, $bandBps, $shape, true)
			+ self::side($askPrices, $askSizes, $mid, $bandBps, $shape, true);
	}
}
