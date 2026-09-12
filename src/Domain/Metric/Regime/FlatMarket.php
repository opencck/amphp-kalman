<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Regime;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RingBuffer;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Is the market trending or going nowhere (ROADMAP §4.8 M-27, the reference
 * FMI, for which no formula was supplied).
 *
 * Two independent readings, because they fail in different places:
 *
 *   efficiency ratio  ER = |P_t − P_{t−N}| / Σ|P_i − P_{i−1}|
 *   band width        BW = 2k·σ / μ
 *
 * The efficiency ratio (Kaufman) is the share of the distance travelled that
 * ended up as displacement. A straight move gives 1; a path that wanders back
 * and forth to end where it started gives 0. It is scale-free, needs no
 * threshold calibration, and answers the question a trend strategy actually
 * asks — not "how volatile is it" but "did any of that volatility go
 * anywhere".
 *
 * Band width is the Bollinger squeeze: a contraction in dispersion often
 * precedes an expansion. It is a different statement from the ratio — a market
 * can be quiet and directional, or violent and directionless — and having both
 * separates those cases.
 *
 * The filter can answer the same question, with a caveat worth stating.
 * `Filtered\Derivative` reports the velocity's t-statistic `|v̂|/√P_vv`, and
 * below about 2 the trend is not statistically distinguishable from zero —
 * the same conclusion with a significance level attached instead of a
 * threshold someone picked. But that test is only as good as the model behind
 * it: a constant-velocity filter is misspecified against a random walk, and at
 * a heavily smoothed setting it will call a significant trend in most chop
 * samples, because it is confident about a velocity that does not exist.
 * `examples/regime-flat-market.php` measures it — 76 % false positives at a
 * tracking index of 0.005, correct separation at 0.05.
 *
 * So: the efficiency ratio needs no tuning and cannot over-claim, the filter's
 * t-statistic is sharper once tuned, and `InteractingMultipleModel` with a calm
 * and a volatile regime gives a probability rather than a test. Reading two of
 * them together costs nothing and is the honest choice.
 */
final class FlatMarket implements PriceMetric
{
	private RingBuffer $prices;

	private RollingMoments $moments;

	private RollingMoments $steps;

	public function __construct(
		public readonly int $window = 60,
		public readonly float $bandK = 2.0,
	) {
		if ($window < 2) {
			throw new InvalidArgument(\sprintf('FlatMarket window must be >= 2, got %d', $window));
		}
		if (!($bandK > 0.0)) {
			throw new InvalidArgument('FlatMarket band multiplier must be > 0');
		}
		$this->prices = new RingBuffer($window + 1);
		$this->steps = new RollingMoments($window);
		$this->moments = new RollingMoments($window);
	}

	public static function type(): string
	{
		return 'flat-market';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-27',
			symbol: 'FMI',
			category: MetricCategory::Regime,
			inputs: [MetricInput::Ticks],
			kernels: ['FlatMarket::efficiencyRatio()', 'FlatMarket::bandWidth()', 'FlatMarket::compute()'],
			nameEn: 'Flat-market indicator',
			nameRu: 'Индикатор флэта',
			algoEn: 'The gate that keeps trend strategies out of chop, which is where they lose most of their money. Scale-free, so it needs no per-instrument calibration.',
			algoRu: 'Фильтр, не пускающий трендовые стратегии в боковик, где они и теряют большую часть денег. Безразмерный, поэтому не требует калибровки под инструмент.',
			plainEn: 'Share of the distance travelled that became net displacement, together with the width of the dispersion band around the mean.',
			plainRu: 'Доля пройденного пути, ставшая чистым смещением, вместе с шириной полосы разброса вокруг среднего.',
			example: 'examples/regime-flat-market.php',
		);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		$previous = $this->prices->newest();
		$this->prices->push($price);
		$this->moments->push($price);
		if (!\is_nan($previous)) {
			$this->steps->push(\abs($price - $previous));
		}
	}

	public function isReady(): bool
	{
		return $this->prices->isFull() && $this->steps->isFull();
	}

	/** The efficiency ratio: 1 is a straight move, 0 is pure chop. */
	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		$displacement = \abs($this->prices->newest() - $this->prices->oldest());
		$path = $this->pathLength();
		// A window in which the price never moved has no path to compare a
		// displacement against; it is flat by any reading, so report 0.
		return $path > 0.0 ? $displacement / $path : 0.0;
	}

	/** @return array{efficiencyRatio: float, bandWidth: float, displacement: float, pathLength: float} */
	public function values(): array
	{
		$ratio = $this->value();
		$mean = $this->moments->isFull() ? $this->moments->mean() : \NAN;
		$sd = $this->moments->isFull() ? $this->moments->populationStdDev() : \NAN;
		return [
			'efficiencyRatio' => $ratio,
			'bandWidth' => \is_nan($mean) || !($mean > 0.0) ? \NAN : 2.0 * $this->bandK * $sd / $mean,
			'displacement' => $this->isReady() ? \abs($this->prices->newest() - $this->prices->oldest()) : \NAN,
			'pathLength' => $this->isReady() ? $this->pathLength() : \NAN,
		];
	}

	/** Total absolute distance travelled inside the window. */
	private function pathLength(): float
	{
		return $this->steps->count() === 0 ? 0.0 : $this->steps->mean() * $this->steps->count();
	}

	public function reset(): void
	{
		$this->prices->reset();
		$this->steps->reset();
		$this->moments->reset();
	}

	/** @return array{type: string, window: int, bandK: float} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'window' => $this->window, 'bandK' => $this->bandK];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 60,
			isset($config['bandK']) && (\is_float($config['bandK']) || \is_int($config['bandK'])) ? (float) $config['bandK'] : 2.0,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> aligned with the input, NAN until the window is full
	 */
	public static function efficiencyRatio(array $prices, int $window = 60): array
	{
		return self::compute($prices, $window)['efficiencyRatio'];
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function bandWidth(array $prices, int $window = 60, float $bandK = 2.0): array
	{
		return self::compute($prices, $window, $bandK)['bandWidth'];
	}

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return array{efficiencyRatio: list<float>, bandWidth: list<float>}
	 */
	public static function compute(array $prices, int $window = 60, float $bandK = 2.0): array
	{
		$metric = new self($window, $bandK);
		$ratio = [];
		$width = [];
		foreach ($prices as $price) {
			$metric->updatePrice(0, $price);
			$values = $metric->values();
			$ratio[] = $values['efficiencyRatio'];
			$width[] = $values['bandWidth'];
		}
		return ['efficiencyRatio' => $ratio, 'bandWidth' => $width];
	}
}
