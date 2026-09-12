<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Filtered;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;

/**
 * The rate of change of any metric, estimated by a filter instead of by
 * subtraction (ROADMAP §4.1 M-02).
 *
 * This one class replaces every `dX` in the reference catalogue — fourteen of
 * them: dOBI, dSMA, ddSMA, dLD, dRSI, dCCI, dMACD, dNV, dNP, ddP, dNdT, dMSI,
 * dFMI, dARD_VWAP. Feed it the values of any metric and it returns the level,
 * the rate and the uncertainty of both.
 *
 * Why it is strictly better than the two alternatives in use:
 *
 *  - A **finite difference** `(x_t − x_{t−1})/Δt` of a series with noise σ has
 *    an error of σ√2/Δt. Shrinking the step to get a more local derivative
 *    makes it worse, without limit. It is the worst estimator of a derivative
 *    that exists, and it is what most of the reference formulas use.
 *  - Prometheus' **`deriv()`** fits a least-squares line over the window,
 *    which is better, but it weights every point equally, assumes the trend is
 *    linear across the whole window, returns no uncertainty, and — with a
 *    subquery written as `[5m:]` — runs on however many samples the server's
 *    evaluation interval happens to produce.
 *  - A **constant-velocity Kalman filter** is the minimum-variance estimator
 *    of level and rate together under its model, weights the past by how much
 *    it still knows, needs no window at all, and reports `√P_vv` so a rate can
 *    be tested for significance rather than eyeballed.
 *
 * Noise is calibrated automatically unless given. Over the first
 * `calibrationWindow` values the class measures the standard deviation of
 * successive differences; for a series that is level plus white noise that
 * quantity is σ√2, so σ_r = sd(Δx)/√2.
 *
 * The process noise follows from one dimensionless knob, the tracking index
 * λ: the position uncertainty the model injects in one step, divided by the
 * measurement noise. For the continuous white-noise-acceleration model that
 * position term is σ_a·√(T³/3), so
 *
 *   λ = σ_a·√(T³/3) / σ_r      ⟺      σ_a = λ·σ_r·√3 / T^{3/2}
 *
 * λ is the same quantity Kalata's alpha-beta gains are tabulated against, and
 * it alone decides the trade-off: 0.001 is very heavy smoothing with a long
 * lag, 0.01 (the default) suits an indicator whose rate is wanted rather than
 * its jitter, and 1.0 follows a move within a few observations at the cost of
 * tracking most of the noise with it.
 *
 * The input series may contain NAN while its own source metric warms up;
 * those are skipped rather than fed to the filter as observations.
 */
final class Derivative implements PriceMetric
{
	private ?KalmanFilter $filter = null;

	private RollingMoments $differences;

	private float $previousValue = \NAN;

	private int $previousNs = 0;

	private RollingMoments $intervals;

	private float $noise = \NAN;

	private float $processNoise = \NAN;

	public function __construct(
		public readonly float $trackingIndex = 0.01,
		public readonly ?float $noiseStd = null,
		public readonly int $calibrationWindow = 50,
	) {
		if (!($trackingIndex > 0.0) || !\is_finite($trackingIndex)) {
			throw new InvalidArgument('Derivative tracking index must be finite and > 0');
		}
		if ($noiseStd !== null && (!($noiseStd > 0.0) || !\is_finite($noiseStd))) {
			throw new InvalidArgument('Derivative noise standard deviation must be finite and > 0');
		}
		if ($calibrationWindow < 2) {
			throw new InvalidArgument('Derivative calibration window must be >= 2');
		}
		$this->differences = new RollingMoments($calibrationWindow);
		$this->intervals = new RollingMoments($calibrationWindow);
	}

	public static function type(): string
	{
		return 'filtered-derivative';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-02',
			symbol: 'dX',
			category: MetricCategory::FilterState,
			inputs: [MetricInput::AnyMetric],
			kernels: ['Derivative::ofSeries()', 'Derivative::rateOf()'],
			nameEn: 'Filtered derivative',
			nameRu: 'Фильтрованная производная',
			algoEn: 'Replaces all fourteen finite differences in the reference catalogue with one estimator that has strictly lower error and reports its own uncertainty, so a rate can be tested for significance.',
			algoRu: 'Заменяет все четырнадцать конечных разностей референсного каталога одним оценивателем со строго меньшей ошибкой, который сообщает собственную неопределённость, так что скорость можно проверить на значимость.',
			plainEn: 'Any metric passed through a constant-velocity Kalman filter to obtain its level, its rate of change and the uncertainty of both.',
			plainRu: 'Любая метрика, пропущенная через фильтр Калмана с постоянной скоростью, чтобы получить её уровень, скорость изменения и неопределённость обоих.',
			example: 'examples/filtered-derivative.php',
		);
	}

	/** `$price` is the metric reading to filter; the name comes from the contract. */
	public function updatePrice(int $timestampNs, float $price): void
	{
		if (\is_nan($price)) {
			return;
		}
		$filter = $this->filter;
		if ($filter !== null) {
			$filter->stepRaw($timestampNs, [0 => $price]);
			$this->previousNs = $timestampNs;
			$this->previousValue = $price;
			return;
		}
		if (!\is_nan($this->previousValue)) {
			$this->differences->push($price - $this->previousValue);
			$dt = ($timestampNs - $this->previousNs) / 1e9;
			if ($dt > 0.0) {
				$this->intervals->push($dt);
			}
		}
		$this->previousValue = $price;
		$this->previousNs = $timestampNs;
		if ($this->differences->isFull()) {
			$this->start($price, $timestampNs);
		}
	}

	private function start(float $value, int $timestampNs): void
	{
		$noise = $this->noiseStd;
		if ($noise === null) {
			$sd = $this->differences->stdDev();
			$noise = \is_nan($sd) || !($sd > 0.0) ? \NAN : $sd / \M_SQRT2;
		}
		if (\is_nan($noise) || !($noise > 0.0)) {
			// A perfectly constant calibration window carries no information
			// about the noise; wait for the series to move rather than invent
			// a scale.
			$this->differences->reset();
			return;
		}
		$interval = $this->intervals->count() > 0 ? $this->intervals->mean() : 1.0;
		if (!($interval > 0.0)) {
			$interval = 1.0;
		}
		$this->noise = $noise;
		$this->processNoise = $this->trackingIndex * $noise * \M_SQRT3 / ($interval ** 1.5);
		// A prior of one measurement noise per interval: wide enough that the
		// first observations set the rate, narrow enough to converge quickly.
		$velocityPrior = $noise / $interval;
		$filter = (new LocalLinearTrend($this->processNoise, $noise))->filter($value, null, $velocityPrior);
		// The model factory seeds the state but not the clock; resetting with
		// the timestamp makes the first real update produce a dt instead of
		// treating this seed as time zero.
		$filter->reset([$value, 0.0], [$noise * $noise, 0.0, 0.0, $velocityPrior * $velocityPrior], $timestampNs);
		$this->filter = $filter;
	}

	public function isReady(): bool
	{
		return $this->filter !== null && $this->filter->steps() > 0;
	}

	/** The rate of change, per second. */
	public function value(): float
	{
		return $this->isReady() && $this->filter !== null ? $this->filter->meanAt(1) : \NAN;
	}

	/** The filtered level — the smoothed metric itself. */
	public function level(): float
	{
		return $this->isReady() && $this->filter !== null ? $this->filter->meanAt(0) : \NAN;
	}

	/** @return array{rate: float, level: float, rateStd: float, levelStd: float, tStat: float} */
	public function values(): array
	{
		$filter = $this->filter;
		if ($filter === null || $filter->steps() === 0) {
			return ['rate' => \NAN, 'level' => \NAN, 'rateStd' => \NAN, 'levelStd' => \NAN, 'tStat' => \NAN];
		}
		$rate = $filter->meanAt(1);
		$rateVariance = $filter->variance(1);
		$rateStd = $rateVariance > 0.0 ? \sqrt($rateVariance) : \NAN;
		$levelVariance = $filter->variance(0);
		return [
			'rate' => $rate,
			'level' => $filter->meanAt(0),
			'rateStd' => $rateStd,
			'levelStd' => $levelVariance > 0.0 ? \sqrt($levelVariance) : \NAN,
			'tStat' => \is_nan($rateStd) ? \NAN : $rate / $rateStd,
		];
	}

	/** The calibrated measurement noise, or NAN before calibration finishes. */
	public function measurementNoise(): float
	{
		return $this->noise;
	}

	public function reset(): void
	{
		$this->filter = null;
		$this->differences->reset();
		$this->intervals->reset();
		$this->previousValue = \NAN;
		$this->previousNs = 0;
		$this->noise = \NAN;
		$this->processNoise = \NAN;
	}

	/** @return array{type: string, trackingIndex: float, noiseStd: float|null, calibrationWindow: int} */
	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'trackingIndex' => $this->trackingIndex,
			'noiseStd' => $this->noiseStd,
			'calibrationWindow' => $this->calibrationWindow,
		];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		$noise = null;
		if (isset($config['noiseStd']) && (\is_float($config['noiseStd']) || \is_int($config['noiseStd']))) {
			$noise = (float) $config['noiseStd'];
		}
		return new self(
			isset($config['trackingIndex']) && (\is_float($config['trackingIndex']) || \is_int($config['trackingIndex']))
				? (float) $config['trackingIndex']
				: 0.01,
			$noise,
			isset($config['calibrationWindow']) && \is_int($config['calibrationWindow']) ? $config['calibrationWindow'] : 50,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Level, rate and uncertainties for a whole metric series.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<int> $timestampsNs
	 * @param list<float> $values may contain NAN while the source metric warms up
	 * @return array{level: list<float>, rate: list<float>, levelStd: list<float>, rateStd: list<float>, tStat: list<float>}
	 */
	public static function ofSeries(
		array $timestampsNs,
		array $values,
		float $trackingIndex = 0.01,
		int $calibrationWindow = 50,
	): array {
		$n = \count($values);
		if (\count($timestampsNs) !== $n) {
			throw new InvalidArgument('Derivative needs one timestamp per value');
		}
		$metric = new self($trackingIndex, null, $calibrationWindow);
		$level = [];
		$rate = [];
		$levelStd = [];
		$rateStd = [];
		$tStat = [];
		for ($i = 0; $i < $n; $i++) {
			$metric->updatePrice($timestampsNs[$i], $values[$i]);
			$out = $metric->values();
			$level[] = $out['level'];
			$rate[] = $out['rate'];
			$levelStd[] = $out['levelStd'];
			$rateStd[] = $out['rateStd'];
			$tStat[] = $out['tStat'];
		}
		return ['level' => $level, 'rate' => $rate, 'levelStd' => $levelStd, 'rateStd' => $rateStd, 'tStat' => $tStat];
	}

	/**
	 * Just the rate, for callers that do not need the rest.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<int> $timestampsNs
	 * @param list<float> $values
	 * @return list<float>
	 */
	public static function rateOf(array $timestampsNs, array $values, float $trackingIndex = 0.01): array
	{
		return self::ofSeries($timestampsNs, $values, $trackingIndex)['rate'];
	}
}
