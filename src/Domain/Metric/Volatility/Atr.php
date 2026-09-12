<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Volatility;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BarMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Trend\MaType;
use OpenCCK\Kalman\Domain\Metric\Trend\MovingAverage;

/**
 * Average true range (ROADMAP §4.6 M-23) — volatility in price units.
 *
 *   TR  = max(H − L, |H − C_prev|, |L − C_prev|)
 *   ATR = RMA_N(TR)
 *
 * The true range counts the overnight gap that the bar range alone misses: a
 * market that opens three percent below yesterday's close and then trades in a
 * narrow band had a violent day, and `H − L` does not know it.
 *
 * It is absent from the reference catalogue, which is surprising, because
 * every risk rule needs it: stop distance, position size and the trailing exit
 * are all naturally expressed in ATR units, and doing so makes one set of
 * parameters work across instruments priced in completely different numbers.
 */
final class Atr implements BarMetric
{
	private MovingAverage $smoother;

	private float $previousClose = \NAN;

	private float $trueRange = \NAN;

	public function __construct(public readonly int $period = 14)
	{
		if ($period < 1) {
			throw new InvalidArgument(\sprintf('ATR period must be >= 1, got %d', $period));
		}
		$this->smoother = new MovingAverage($period, MaType::Rma);
	}

	public static function type(): string
	{
		return 'atr';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-23',
			symbol: 'ATR',
			category: MetricCategory::Volatility,
			inputs: [MetricInput::Bars],
			kernels: ['Atr::compute()', 'Atr::trueRanges()'],
			nameEn: 'Average true range',
			nameRu: 'Средний истинный диапазон',
			algoEn: 'Stop distance and position size in price units. Expressing both in ATR makes one parameter set work across instruments with completely different price scales.',
			algoRu: 'Дистанция стопа и размер позиции в единицах цены. Выражение того и другого в ATR позволяет одному набору параметров работать на инструментах с совершенно разными масштабами цен.',
			plainEn: 'Wilder-smoothed true range, which counts the gap to the previous close as well as the bar range.',
			plainRu: 'Сглаженный по Уайлдеру истинный диапазон, учитывающий разрыв к предыдущему закрытию, а не только размах бара.',
			example: 'examples/volatility-atr.php',
		);
	}

	public function updateBar(Bar $bar): void
	{
		$this->feed($bar->high, $bar->low, $bar->close);
	}

	private function feed(float $high, float $low, float $close): void
	{
		$range = $high - $low;
		if (!\is_nan($this->previousClose)) {
			$up = \abs($high - $this->previousClose);
			$down = \abs($low - $this->previousClose);
			if ($up > $range) {
				$range = $up;
			}
			if ($down > $range) {
				$range = $down;
			}
		}
		$this->trueRange = $range;
		$this->previousClose = $close;
		$this->smoother->updatePrice(0, $range);
	}

	public function isReady(): bool
	{
		return $this->smoother->isReady();
	}

	public function value(): float
	{
		return $this->smoother->value();
	}

	/** @return array{atr: float, trueRange: float} */
	public function values(): array
	{
		return ['atr' => $this->value(), 'trueRange' => $this->trueRange];
	}

	/** ATR as a fraction of the given price — the form used for position sizing. */
	public function relativeTo(float $price): float
	{
		$atr = $this->value();
		return $price > 0.0 && !\is_nan($atr) ? $atr / $price : \NAN;
	}

	public function reset(): void
	{
		$this->smoother->reset();
		$this->previousClose = \NAN;
		$this->trueRange = \NAN;
	}

	/** @return array{type: string, period: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'period' => $this->period];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(isset($config['period']) && \is_int($config['period']) ? $config['period'] : 14);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float> aligned with the input, NAN during warm-up
	 */
	public static function compute(array $highs, array $lows, array $closes, int $period = 14): array
	{
		$n = self::assertAligned($highs, $lows, $closes);
		$metric = new self($period);
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$metric->feed($highs[$i], $lows[$i], $closes[$i]);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * The unsmoothed true range of each bar. The first entry is the plain bar
	 * range, because there is no previous close to gap from.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	public static function trueRanges(array $highs, array $lows, array $closes): array
	{
		$n = self::assertAligned($highs, $lows, $closes);
		$out = [];
		$previousClose = \NAN;
		for ($i = 0; $i < $n; $i++) {
			$range = $highs[$i] - $lows[$i];
			if (!\is_nan($previousClose)) {
				$up = \abs($highs[$i] - $previousClose);
				$down = \abs($lows[$i] - $previousClose);
				if ($up > $range) {
					$range = $up;
				}
				if ($down > $range) {
					$range = $down;
				}
			}
			$previousClose = $closes[$i];
			$out[] = $range;
		}
		return $out;
	}

	/**
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 */
	private static function assertAligned(array $highs, array $lows, array $closes): int
	{
		$n = \count($closes);
		if (\count($highs) !== $n || \count($lows) !== $n) {
			throw new InvalidArgument('ATR needs highs, lows and closes of equal length');
		}
		return $n;
	}
}
