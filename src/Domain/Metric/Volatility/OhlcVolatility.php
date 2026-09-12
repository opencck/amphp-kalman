<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Volatility;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BarMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Range-based volatility estimators over a rolling window of bars (ROADMAP
 * §4.6 M-22).
 *
 *   Parkinson        σ² = ln²(H/L) / (4 ln 2)
 *   Garman–Klass     σ² = ½·ln²(H/L) − (2 ln2 − 1)·ln²(C/O)
 *   Rogers–Satchell  σ² = ln(H/C)·ln(H/O) + ln(L/C)·ln(L/O)
 *   Yang–Zhang       σ² = σ²_overnight + k·σ²_openClose + (1−k)·σ²_RS
 *                    k  = 0.34 / (1.34 + (n+1)/(n−1))
 *   Close-to-close   σ² = sample variance of ln(C_t/C_{t−1})
 *
 * All five return **variance per bar**; `value()` is its square root and
 * `annualised()` scales by the number of bars in a year.
 *
 * Why bother, when close-to-close is one line: a bar's high and low carry the
 * path the price actually took, and ignoring them throws away most of the
 * information in the bar. Rogers–Satchell reaches the same precision from an
 * eighth of the history, which is the difference between noticing a volatility
 * regime change within the hour and noticing it tomorrow.
 *
 * These estimators are also the natural observation for the library's
 * `StochasticVolatility` model. That model currently takes `ln r²` as its
 * measurement, whose noise variance is π²/2 ≈ 4.93 — enormous. Feeding it
 * `ln σ̂²` from a range estimator instead cuts the measurement noise by the
 * estimator's efficiency factor, and the filtered volatility improves by the
 * same factor. `Filtered\MetricObservation` wires that up.
 */
final class OhlcVolatility implements BarMetric
{
	private const TWO_LN2_MINUS_ONE = 2.0 * \M_LN2 - 1.0;

	private RollingMoments $terms;

	private RollingMoments $overnight;

	private RollingMoments $openClose;

	private RollingMoments $rogersSatchell;

	private RollingMoments $closeReturns;

	private float $previousClose = \NAN;

	public function __construct(
		public readonly int $window = 20,
		public readonly VolatilityEstimator $estimator = VolatilityEstimator::YangZhang,
	) {
		if ($window < 2) {
			throw new InvalidArgument(\sprintf('OhlcVolatility window must be >= 2, got %d', $window));
		}
		$this->terms = new RollingMoments($window);
		$this->overnight = new RollingMoments($window);
		$this->openClose = new RollingMoments($window);
		$this->rogersSatchell = new RollingMoments($window);
		$this->closeReturns = new RollingMoments($window);
	}

	public static function type(): string
	{
		return 'ohlc-volatility';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-22',
			symbol: 'σ',
			category: MetricCategory::Volatility,
			inputs: [MetricInput::Bars],
			kernels: [
				'OhlcVolatility::parkinson()',
				'OhlcVolatility::garmanKlass()',
				'OhlcVolatility::rogersSatchell()',
				'OhlcVolatility::yangZhang()',
				'OhlcVolatility::closeToClose()',
			],
			nameEn: 'OHLC volatility',
			nameRu: 'Волатильность по OHLC',
			algoEn: 'Five to eight times more efficient than close-to-close, so a risk limit reacts within the hour instead of the next day. Rogers-Satchell is the one to use on a trending instrument.',
			algoRu: 'В пять-восемь раз эффективнее оценки по закрытиям, поэтому риск-лимит реагирует за час, а не на следующий день. На трендовом инструменте следует использовать Роджерса-Сатчелла.',
			plainEn: 'Variance estimators that use the intrabar high and low as well as the open and close.',
			plainRu: 'Оценки дисперсии, использующие внутрибарные максимум и минимум наряду с открытием и закрытием.',
			example: 'examples/volatility-ohlc.php',
		);
	}

	public function updateBar(Bar $bar): void
	{
		$this->feed($bar->open, $bar->high, $bar->low, $bar->close);
	}

	private function feed(float $open, float $high, float $low, float $close): void
	{
		if ($open <= 0.0 || $high <= 0.0 || $low <= 0.0 || $close <= 0.0) {
			throw new InvalidArgument('OhlcVolatility needs strictly positive prices');
		}
		$logHl = \log($high / $low);
		$logCo = \log($close / $open);

		$parkinson = $logHl * $logHl / (4.0 * \M_LN2);
		// Constants last in both products: PHP < 8.4 miscompiles a reused
		// `const * var * var` shape under the function JIT (ADR-007, JitSanity).
		$garmanKlass = $logHl * $logHl * 0.5 - $logCo * $logCo * self::TWO_LN2_MINUS_ONE;
		$rs = \log($high / $close) * \log($high / $open) + \log($low / $close) * \log($low / $open);

		$this->rogersSatchell->push($rs);
		$this->openClose->push($logCo);
		$this->overnight->push(\is_nan($this->previousClose) ? 0.0 : \log($open / $this->previousClose));
		if (!\is_nan($this->previousClose)) {
			$this->closeReturns->push(\log($close / $this->previousClose));
		}
		$this->previousClose = $close;

		$this->terms->push(match ($this->estimator) {
			VolatilityEstimator::Parkinson => $parkinson,
			VolatilityEstimator::GarmanKlass => $garmanKlass,
			VolatilityEstimator::RogersSatchell => $rs,
			VolatilityEstimator::CloseToClose, VolatilityEstimator::YangZhang => 0.0,
		});
	}

	public function isReady(): bool
	{
		return $this->estimator === VolatilityEstimator::CloseToClose
			? $this->closeReturns->isFull()
			: $this->terms->isFull();
	}

	/** Standard deviation per bar. */
	public function value(): float
	{
		$variance = $this->variance();
		return \is_nan($variance) ? \NAN : \sqrt($variance);
	}

	/** Variance per bar. */
	public function variance(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		return match ($this->estimator) {
			VolatilityEstimator::Parkinson,
			VolatilityEstimator::GarmanKlass,
			VolatilityEstimator::RogersSatchell => \max($this->terms->mean(), 0.0),
			VolatilityEstimator::CloseToClose => $this->closeReturns->variance(),
			VolatilityEstimator::YangZhang => $this->yangZhangVariance(),
		};
	}

	private function yangZhangVariance(): float
	{
		$n = $this->window;
		$k = 0.34 / (1.34 + (float) ($n + 1) / ($n - 1));
		$open = $this->overnight->variance();
		$close = $this->openClose->variance();
		$rs = $this->rogersSatchell->mean();
		if (\is_nan($open) || \is_nan($close) || \is_nan($rs)) {
			return \NAN;
		}
		$variance = $open + $k * $close + (1.0 - $k) * $rs;
		return $variance > 0.0 ? $variance : 0.0;
	}

	/** Scales the per-bar standard deviation to a year of `barsPerYear` bars. */
	public function annualised(float $barsPerYear): float
	{
		$sigma = $this->value();
		return \is_nan($sigma) ? \NAN : $sigma * \sqrt($barsPerYear);
	}

	/** @return array{sigma: float, variance: float, efficiency: float} */
	public function values(): array
	{
		return [
			'sigma' => $this->value(),
			'variance' => $this->variance(),
			'efficiency' => $this->estimator->efficiency(),
		];
	}

	public function reset(): void
	{
		$this->terms->reset();
		$this->overnight->reset();
		$this->openClose->reset();
		$this->rogersSatchell->reset();
		$this->closeReturns->reset();
		$this->previousClose = \NAN;
	}

	/** @return array{type: string, window: int, estimator: string} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'window' => $this->window, 'estimator' => $this->estimator->value];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 20,
			isset($config['estimator']) && \is_string($config['estimator'])
				? VolatilityEstimator::from($config['estimator'])
				: VolatilityEstimator::YangZhang,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Rolling per-bar standard deviation for the chosen estimator.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $opens
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @param string $estimator one of the VolatilityEstimator values
	 * @return list<float> aligned with the input, NAN until the window is full
	 */
	public static function compute(
		array $opens,
		array $highs,
		array $lows,
		array $closes,
		int $window = 20,
		string $estimator = 'yang-zhang',
	): array {
		$n = \count($closes);
		if (\count($opens) !== $n || \count($highs) !== $n || \count($lows) !== $n) {
			throw new InvalidArgument('OhlcVolatility needs opens, highs, lows and closes of equal length');
		}
		$metric = new self($window, VolatilityEstimator::from($estimator));
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$metric->feed($opens[$i], $highs[$i], $lows[$i], $closes[$i]);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * Parkinson: range only, zero drift assumed. Reads high under a trend.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @return list<float>
	 */
	public static function parkinson(array $highs, array $lows, int $window = 20): array
	{
		return self::compute($highs, $highs, $lows, $lows, $window, VolatilityEstimator::Parkinson->value);
	}

	/**
	 * Garman–Klass: range plus the open-to-close move, zero drift assumed.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $opens
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	public static function garmanKlass(array $opens, array $highs, array $lows, array $closes, int $window = 20): array
	{
		return self::compute($opens, $highs, $lows, $closes, $window, VolatilityEstimator::GarmanKlass->value);
	}

	/**
	 * Rogers–Satchell: unbiased in the presence of a drift, which is what makes
	 * it the right default on a trending instrument.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $opens
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	public static function rogersSatchell(array $opens, array $highs, array $lows, array $closes, int $window = 20): array
	{
		return self::compute($opens, $highs, $lows, $closes, $window, VolatilityEstimator::RogersSatchell->value);
	}

	/**
	 * Yang–Zhang: minimum variance among the five, and the only one that
	 * accounts for the gap between bars.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $opens
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	public static function yangZhang(array $opens, array $highs, array $lows, array $closes, int $window = 20): array
	{
		return self::compute($opens, $highs, $lows, $closes, $window, VolatilityEstimator::YangZhang->value);
	}

	/**
	 * Close-to-close: the baseline every efficiency figure is measured against.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $closes
	 * @return list<float>
	 */
	public static function closeToClose(array $closes, int $window = 20): array
	{
		return self::compute($closes, $closes, $closes, $closes, $window, VolatilityEstimator::CloseToClose->value);
	}
}
