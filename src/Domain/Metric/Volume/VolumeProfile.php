<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Volume;

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\TradeMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Traded volume, standardised, and the buy/sell split inside it (ROADMAP §4.4
 * M-14, the reference NV / dNV / NVD).
 *
 * Outputs, all measured over the last `window` trades against a much longer
 * `reference` window:
 *
 *   volume     Σ size
 *   normalized volume / (window · mean size)     ≈ 1 in normal conditions
 *   z          (volume − expected) / sd          standardised surprise
 *   delta      buy volume − sell volume
 *   imbalance  delta / volume                    in [−1, +1]
 *
 * **This metric consumes the trade tape, and that is the correction it
 * embodies.** The reference computes volume as `sum_over_time` of a volume
 * gauge sampled on a scrape interval. What that sum means depends entirely on
 * what the gauge holds: a cumulative counter (the sum is meaningless —
 * `increase()` is the operator for that), volume since the last scrape (the
 * subquery step must equal the scrape interval exactly, or volume is
 * double-counted or dropped), or the size of the last trade (the sum then
 * measures how often the scraper ran). None of those is the market's volume.
 * Reading executions directly removes the question.
 *
 * The z-score treats trade sizes in the window as roughly independent, so the
 * window total has standard deviation √window · sd. Volume does cluster, so
 * that understates the tails — but it is a stated approximation, and it beats
 * comparing a raw sum against a mean taken over a different length.
 */
final class VolumeProfile implements TradeMetric
{
	private RollingMoments $sizes;

	private RollingMoments $buys;

	private RollingMoments $notionals;

	private RollingMoments $referenceSizes;

	public function __construct(
		public readonly int $window = 100,
		public readonly int $reference = 2000,
	) {
		if ($window < 1) {
			throw new InvalidArgument(\sprintf('VolumeProfile window must be >= 1, got %d', $window));
		}
		if ($reference < $window) {
			throw new InvalidArgument('VolumeProfile reference window must be at least as long as the window');
		}
		$this->sizes = new RollingMoments($window);
		$this->buys = new RollingMoments($window);
		$this->notionals = new RollingMoments($window);
		$this->referenceSizes = new RollingMoments($reference);
	}

	public static function type(): string
	{
		return 'volume-profile';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-14',
			symbol: 'NV/NVD',
			category: MetricCategory::Volume,
			inputs: [MetricInput::Trades],
			kernels: ['VolumeProfile::compute()', 'VolumeProfile::normalized()', 'VolumeProfile::delta()'],
			nameEn: 'Volume profile',
			nameRu: 'Профиль объёма',
			algoEn: 'A volume spike confirms a breakout and its absence marks a fade. Computed from executions, because summing a sampled volume gauge measures the scrape interval rather than the market.',
			algoRu: 'Всплеск объёма подтверждает пробой, его отсутствие — признак ложного. Считается по сделкам, потому что сумма сэмплов гейджа объёма измеряет интервал скрейпа, а не рынок.',
			plainEn: 'Traded volume over a window, standardised against a longer reference window, with the buyer/seller split inside it.',
			plainRu: 'Объём торгов за окно, стандартизованный относительно длинного опорного окна, с разделением на покупки и продажи внутри него.',
			example: 'examples/volume-profile.php',
		);
	}

	public function updateTrade(Trade $trade): void
	{
		$this->feed($trade->size, $trade->side->sign() > 0.0 ? $trade->size : 0.0, $trade->notional());
	}

	private function feed(float $size, float $buySize, float $notional): void
	{
		$this->sizes->push($size);
		$this->buys->push($buySize);
		$this->notionals->push($notional);
		$this->referenceSizes->push($size);
	}

	/** Total size over the window. */
	public function volume(): float
	{
		return $this->sizes->count() === 0 ? 0.0 : $this->sizes->mean() * $this->sizes->count();
	}

	public function isReady(): bool
	{
		return $this->referenceSizes->isFull();
	}

	/** The standardised volume surprise. */
	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		$sd = $this->referenceSizes->populationStdDev();
		if (!($sd > 0.0)) {
			return \NAN;
		}
		$expected = $this->window * $this->referenceSizes->mean();
		return ($this->volume() - $expected) / (\sqrt((float) $this->window) * $sd);
	}

	/** @return array{z: float, volume: float, notional: float, normalized: float, buyVolume: float, sellVolume: float, delta: float, imbalance: float} */
	public function values(): array
	{
		$volume = $this->volume();
		$buyVolume = $this->buys->count() === 0 ? 0.0 : $this->buys->mean() * $this->buys->count();
		$sellVolume = $volume - $buyVolume;
		$delta = $buyVolume - $sellVolume;
		$expected = $this->isReady() ? $this->window * $this->referenceSizes->mean() : \NAN;
		return [
			'z' => $this->value(),
			'volume' => $volume,
			'notional' => $this->notionals->count() === 0 ? 0.0 : $this->notionals->mean() * $this->notionals->count(),
			'normalized' => $expected > 0.0 ? $volume / $expected : \NAN,
			'buyVolume' => $buyVolume,
			'sellVolume' => $sellVolume,
			'delta' => $delta,
			'imbalance' => $volume > 0.0 ? $delta / $volume : \NAN,
		];
	}

	public function reset(): void
	{
		$this->sizes->reset();
		$this->buys->reset();
		$this->notionals->reset();
		$this->referenceSizes->reset();
	}

	/** @return array{type: string, window: int, reference: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'window' => $this->window, 'reference' => $this->reference];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 100,
			isset($config['reference']) && \is_int($config['reference']) ? $config['reference'] : 2000,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Every output over a trade series.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @param list<float> $signs +1 buyer-initiated, −1 seller-initiated, 0 unknown
	 * @return array{z: list<float>, volume: list<float>, normalized: list<float>, delta: list<float>, imbalance: list<float>}
	 */
	public static function compute(array $prices, array $sizes, array $signs, int $window = 100, int $reference = 2000): array
	{
		$n = \count($sizes);
		if (\count($prices) !== $n || \count($signs) !== $n) {
			throw new InvalidArgument('VolumeProfile needs prices, sizes and signs of equal length');
		}
		$metric = new self($window, $reference);
		$z = [];
		$volume = [];
		$normalized = [];
		$delta = [];
		$imbalance = [];
		for ($i = 0; $i < $n; $i++) {
			$size = $sizes[$i];
			$metric->feed($size, $signs[$i] > 0.0 ? $size : 0.0, $prices[$i] * $size);
			$values = $metric->values();
			$z[] = $values['z'];
			$volume[] = $values['volume'];
			$normalized[] = $values['normalized'];
			$delta[] = $values['delta'];
			$imbalance[] = $values['imbalance'];
		}
		return ['z' => $z, 'volume' => $volume, 'normalized' => $normalized, 'delta' => $delta, 'imbalance' => $imbalance];
	}

	/**
	 * Window volume divided by its long-run expectation: 1 is normal, 3 is a
	 * burst.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $sizes
	 * @return list<float>
	 */
	public static function normalized(array $sizes, int $window = 100, int $reference = 2000): array
	{
		$n = \count($sizes);
		$prices = \array_fill(0, $n, 1.0);
		$signs = \array_fill(0, $n, 0.0);
		return self::compute($prices, $sizes, $signs, $window, $reference)['normalized'];
	}

	/**
	 * Buyer-initiated minus seller-initiated volume over the window.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $sizes
	 * @param list<float> $signs
	 * @return list<float>
	 */
	public static function delta(array $sizes, array $signs, int $window = 100): array
	{
		$n = \count($sizes);
		$prices = \array_fill(0, $n, 1.0);
		return self::compute($prices, $sizes, $signs, $window, $window)['delta'];
	}
}
