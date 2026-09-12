<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Liquidity;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BookMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;

/**
 * Slope of the order book: how fast depth accumulates as you walk away from
 * the mid (ROADMAP §4.5 M-17, the Næs–Skjeltorp measure).
 *
 * The idea from the literature is that a book whose depth piles up quickly
 * within a few basis points is a deep market — a given order walks a short
 * distance — while a book whose depth only appears far out is thin regardless
 * of its total size. That is a statement about the *shape* of the book, which
 * neither total volume nor imbalance captures.
 *
 * The operationalisation here is a least-squares regression of cumulative
 * depth on relative distance, forced through the origin (no depth at zero
 * distance):
 *
 *   slope = Σ (τ_i · V_i) / Σ τ_i²        τ_i = |p_i − mid| / mid,  V_i cumulative
 *
 * Units are volume per unit of relative distance, so a slope of 500 means
 * roughly 5 units of size per basis point. The published formulations differ
 * in their exact averaging; this one is stated explicitly rather than left
 * implicit, is stable when levels are unevenly spaced, and preserves the
 * ordering that matters — steeper is deeper.
 *
 * Each side gets its own slope, because they are routinely asymmetric, and
 * that asymmetry is itself a directional signal.
 */
final class BookSlope implements BookMetric
{
	private float $bidSlope = \NAN;

	private float $askSlope = \NAN;

	public function __construct(public readonly int $levels = 20)
	{
		if ($levels < 0) {
			throw new InvalidArgument('BookSlope levels must be >= 0 (0 means the whole book)');
		}
	}

	public static function type(): string
	{
		return 'book-slope';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-35',
			symbol: 'SLOPE',
			category: MetricCategory::Liquidity,
			inputs: [MetricInput::Book],
			kernels: ['BookSlope::side()', 'BookSlope::both()'],
			nameEn: 'Order-book slope',
			nameRu: 'Наклон стакана',
			algoEn: 'A steep slope means shallow price impact: depth piles up close to the touch, so an order walks a short distance. Side asymmetry is itself directional.',
			algoRu: 'Крутой наклон означает малое влияние на цену: глубина набирается близко к спреду, и заявка проходит короткий путь. Асимметрия сторон сама по себе направленный сигнал.',
			plainEn: 'Regression coefficient of cumulative depth on relative distance from the mid, computed for each side.',
			plainRu: 'Коэффициент регрессии накопленной глубины на относительное расстояние от середины спреда, посчитанный для каждой стороны.',
			example: 'examples/liquidity-density.php',
		);
	}

	public function updateBook(OrderBook $book): void
	{
		if ($book->isEmpty()) {
			$this->bidSlope = \NAN;
			$this->askSlope = \NAN;
			return;
		}
		$mid = $book->mid();
		$this->bidSlope = self::side($book->bidPrices, $book->bidSizes, $mid, $this->levels);
		$this->askSlope = self::side($book->askPrices, $book->askSizes, $mid, $this->levels);
	}

	public function isReady(): bool
	{
		return !\is_nan($this->bidSlope) && !\is_nan($this->askSlope);
	}

	/** The average of the two sides — the book's overall steepness. */
	public function value(): float
	{
		return $this->isReady() ? ($this->bidSlope + $this->askSlope) / 2.0 : \NAN;
	}

	/** @return array{slope: float, bidSlope: float, askSlope: float, asymmetry: float} */
	public function values(): array
	{
		$asymmetry = $this->isReady() && ($this->bidSlope + $this->askSlope) > 0.0
			? ($this->bidSlope - $this->askSlope) / ($this->bidSlope + $this->askSlope)
			: \NAN;
		return [
			'slope' => $this->value(),
			'bidSlope' => $this->bidSlope,
			'askSlope' => $this->askSlope,
			'asymmetry' => $asymmetry,
		];
	}

	public function reset(): void
	{
		$this->bidSlope = \NAN;
		$this->askSlope = \NAN;
	}

	/** @return array{type: string, levels: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'levels' => $this->levels];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(isset($config['levels']) && \is_int($config['levels']) ? $config['levels'] : 20);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Slope of one side.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices sorted away from the mid
	 * @param list<float> $sizes
	 */
	public static function side(array $prices, array $sizes, float $mid, int $levels = 20): float
	{
		$n = \count($prices);
		if (\count($sizes) !== $n) {
			throw new InvalidArgument('BookSlope needs prices and sizes of equal length');
		}
		if ($levels > 0 && $levels < $n) {
			$n = $levels;
		}
		if ($n === 0 || !($mid > 0.0)) {
			return \NAN;
		}
		$cumulative = 0.0;
		$numerator = 0.0;
		$denominator = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$cumulative += $sizes[$i];
			$tau = \abs($prices[$i] - $mid) / $mid;
			$numerator += $tau * $cumulative;
			$denominator += $tau * $tau;
		}
		// Every level sitting exactly at the mid leaves no distance to
		// regress against; a crossed or degenerate book has no slope.
		return $denominator > 0.0 ? $numerator / $denominator : \NAN;
	}

	/**
	 * Both sides and their asymmetry in one pass.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 * @return array{bid: float, ask: float, slope: float, asymmetry: float}
	 */
	public static function both(array $bidPrices, array $bidSizes, array $askPrices, array $askSizes, int $levels = 20): array
	{
		if ($bidPrices === [] || $askPrices === []) {
			return ['bid' => \NAN, 'ask' => \NAN, 'slope' => \NAN, 'asymmetry' => \NAN];
		}
		$mid = ($bidPrices[0] + $askPrices[0]) / 2.0;
		$bid = self::side($bidPrices, $bidSizes, $mid, $levels);
		$ask = self::side($askPrices, $askSizes, $mid, $levels);
		$sum = $bid + $ask;
		return [
			'bid' => $bid,
			'ask' => $ask,
			'slope' => $sum / 2.0,
			'asymmetry' => $sum > 0.0 ? ($bid - $ask) / $sum : \NAN,
		];
	}
}
