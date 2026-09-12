<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Price;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RingBuffer;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Difference between a smoothed price and the same smoothed price a lag ago,
 * divided by a longer-run level (ROADMAP §4.1 M-04, the reference NdT):
 *
 *   NdT = (SMA_w(P)_t − SMA_w(P)_{t−L}) / SMA_n(P)_t
 *
 * This is a numerical derivative in disguise, and it carries the flaw of the
 * reference formula, which this class reports rather than hides: with w = 30
 * minutes and L = 15 minutes the two smoothed terms share half their window,
 * so their correlation is about 0.5. Correlated halves cancel in the numerator
 * while their independent noise does not, and the signal-to-noise ratio comes
 * out worse than a direct slope estimate over the same span.
 *
 * `overlap()` returns that fraction, so a caller can see what it is paying.
 * The library's answer is `Filtered\Derivative`: one local-linear-trend filter
 * gives the same quantity with strictly lower error and an uncertainty to go
 * with it.
 */
final class MeanPriceDifference implements PriceMetric
{
	private RollingMoments $window;

	private RingBuffer $history;

	private RollingMoments $level;

	public function __construct(
		public readonly int $smoothWindow = 30,
		public readonly int $lag = 15,
		public readonly int $levelWindow = 120,
	) {
		if ($smoothWindow < 1 || $lag < 1 || $levelWindow < 1) {
			throw new InvalidArgument('MeanPriceDifference windows and lag must be >= 1');
		}
		$this->window = new RollingMoments($smoothWindow);
		$this->history = new RingBuffer($lag + 1);
		$this->level = new RollingMoments($levelWindow);
	}

	public static function type(): string
	{
		return 'mean-price-difference';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-04',
			symbol: 'NdT',
			category: MetricCategory::Price,
			inputs: [MetricInput::Ticks],
			kernels: ['MeanPriceDifference::compute()', 'MeanPriceDifference::overlapFor()'],
			nameEn: 'Mean-price difference',
			nameRu: 'Разность средних цен',
			algoEn: 'Kept for compatibility with the PromQL stack. Its two smoothed terms overlap, which cancels signal and keeps noise; prefer the filtered derivative.',
			algoRu: 'Оставлена для совместимости со стеком PromQL. Два её сглаженных члена перекрываются, что гасит сигнал и сохраняет шум; лучше использовать фильтрованную производную.',
			plainEn: 'Difference between two lagged moving averages of the price, normalised by a longer-run average level.',
			plainRu: 'Разность двух сдвинутых скользящих средних цены, нормированная на средний уровень за длинное окно.',
			example: 'examples/price-delta.php',
		);
	}

	/**
	 * Fraction of the smoothing window shared by the two terms: 0 means
	 * disjoint windows, 1 means they are the same window. Anything above 0 is
	 * signal cancelled on purpose.
	 */
	public function overlap(): float
	{
		return self::overlapFor($this->smoothWindow, $this->lag);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		$this->window->push($price);
		$this->level->push($price);
		$this->history->push($this->window->isFull() ? $this->window->mean() : \NAN);
	}

	public function isReady(): bool
	{
		return $this->history->isFull() && $this->level->isFull() && !\is_nan($this->history->oldest());
	}

	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		return self::normalise($this->history->newest(), $this->history->oldest(), $this->level->mean());
	}

	/** @return array{value: float, overlap: float} */
	public function values(): array
	{
		return ['value' => $this->value(), 'overlap' => $this->overlap()];
	}

	public function reset(): void
	{
		$this->window->reset();
		$this->history->reset();
		$this->level->reset();
	}

	/** @return array{type: string, smoothWindow: int, lag: int, levelWindow: int} */
	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'smoothWindow' => $this->smoothWindow,
			'lag' => $this->lag,
			'levelWindow' => $this->levelWindow,
		];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['smoothWindow']) && \is_int($config['smoothWindow']) ? $config['smoothWindow'] : 30,
			isset($config['lag']) && \is_int($config['lag']) ? $config['lag'] : 15,
			isset($config['levelWindow']) && \is_int($config['levelWindow']) ? $config['levelWindow'] : 120,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> aligned with the input, NAN during warm-up
	 */
	public static function compute(array $prices, int $smoothWindow, int $lag, int $levelWindow): array
	{
		if ($smoothWindow < 1 || $lag < 1 || $levelWindow < 1) {
			throw new InvalidArgument('MeanPriceDifference windows and lag must be >= 1');
		}
		$window = new RollingMoments($smoothWindow);
		$level = new RollingMoments($levelWindow);
		$history = new RingBuffer($lag + 1);
		$out = [];
		foreach ($prices as $price) {
			$window->push($price);
			$level->push($price);
			$history->push($window->isFull() ? $window->mean() : \NAN);
			$ready = $history->isFull() && $level->isFull() && !\is_nan($history->oldest());
			$out[] = $ready ? self::normalise($history->newest(), $history->oldest(), $level->mean()) : \NAN;
		}
		return $out;
	}

	/**
	 * Shared fraction of the two smoothing windows, for a given configuration.
	 *
	 * @deterministic
	 * @offloadable
	 */
	public static function overlapFor(int $smoothWindow, int $lag): float
	{
		$shared = $smoothWindow - $lag;
		return $shared <= 0 ? 0.0 : (float) $shared / $smoothWindow;
	}

	private static function normalise(float $now, float $past, float $level): float
	{
		return $level > 0.0 ? ($now - $past) / $level : \NAN;
	}
}
