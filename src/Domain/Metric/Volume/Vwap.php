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
 * Volume-weighted average price, with its dispersion bands (ROADMAP §4.4
 * M-15).
 *
 *   VWAP  = Σ p·v / Σ v
 *   σ²_vw = Σ p²·v / Σ v − VWAP²          volume-weighted variance
 *   bands = VWAP ± k·σ_vw
 *
 * Two distinct indicators share the name, and the reference conflates them:
 *
 *  - **anchored** (`window = 0`): accumulation restarts at a chosen moment —
 *    the session open, the day, a news event. This is the execution benchmark
 *    institutions are measured against, and it is why VWAP acts as support:
 *    everyone who bought today is above water only above it.
 *  - **rolling** (`window > 0`): a moving window, which never resets. A
 *    perfectly good mean-reversion reference, but it is not the benchmark and
 *    does not carry that meaning.
 *
 * The reference computes the rolling form over 30 minutes while calling it
 * VWAP, and provides no bands. Without bands VWAP is only a level: the
 * tradable statement is "price is two volume-weighted standard deviations
 * above the average everyone paid", which needs σ_vw.
 *
 * The variance is computed from the volume-weighted second moment; that form
 * can go slightly negative through cancellation when all trades sit on one
 * price, and is clamped at zero rather than allowed to produce a NAN band.
 */
final class Vwap implements TradeMetric
{
	private ?RollingMoments $pv = null;

	private ?RollingMoments $v = null;

	private ?RollingMoments $p2v = null;

	private float $sumPv = 0.0;

	private float $sumV = 0.0;

	private float $sumP2v = 0.0;

	private float $price = \NAN;

	private int $anchorNs = 0;

	public function __construct(
		public readonly int $window = 0,
		public readonly float $bandK = 2.0,
	) {
		if ($window < 0) {
			throw new InvalidArgument('VWAP window must be >= 0 (0 means anchored)');
		}
		if (!($bandK > 0.0)) {
			throw new InvalidArgument('VWAP band multiplier must be > 0');
		}
		if ($window > 0) {
			$this->pv = new RollingMoments($window);
			$this->v = new RollingMoments($window);
			$this->p2v = new RollingMoments($window);
		}
	}

	/** Session-anchored VWAP: the execution benchmark. */
	public static function anchored(float $bandK = 2.0): self
	{
		return new self(0, $bandK);
	}

	/** Rolling VWAP over the last `window` trades. */
	public static function rolling(int $window, float $bandK = 2.0): self
	{
		return new self($window, $bandK);
	}

	public static function type(): string
	{
		return 'vwap';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-15',
			symbol: 'VWAP',
			category: MetricCategory::Volume,
			inputs: [MetricInput::Trades],
			kernels: ['Vwap::anchoredSeries()', 'Vwap::rollingSeries()', 'Vwap::bands()', 'Vwap::relativeDeviation()'],
			nameEn: 'VWAP and bands',
			nameRu: 'VWAP и полосы',
			algoEn: 'The execution benchmark: above it you are paying up. The bands turn a level into a mean-reversion signal, which the reference formula cannot produce at all.',
			algoRu: 'Бенчмарк исполнения: выше него вы переплачиваете. Полосы превращают уровень в сигнал возврата к среднему, чего референсная формула не даёт вовсе.',
			plainEn: 'Average price weighted by traded volume, anchored to a session or rolling over a window, with volume-weighted dispersion bands.',
			plainRu: 'Средняя цена, взвешенная объёмом торгов, привязанная к сессии или скользящая по окну, с полосами объёмно-взвешенного разброса.',
			example: 'examples/volume-vwap.php',
		);
	}

	/** Restarts an anchored VWAP — session open, day boundary, news event. */
	public function anchor(int $timestampNs): void
	{
		$this->anchorNs = $timestampNs;
		$this->sumPv = 0.0;
		$this->sumV = 0.0;
		$this->sumP2v = 0.0;
	}

	public function anchorTimestampNs(): int
	{
		return $this->anchorNs;
	}

	public function updateTrade(Trade $trade): void
	{
		$this->feed($trade->price, $trade->size);
	}

	private function feed(float $price, float $size): void
	{
		$this->price = $price;
		if ($size <= 0.0) {
			return;
		}
		$pv = $price * $size;
		$p2v = $price * $pv;
		if ($this->window > 0) {
			$pvWindow = $this->pv;
			$vWindow = $this->v;
			$p2vWindow = $this->p2v;
			\assert($pvWindow !== null && $vWindow !== null && $p2vWindow !== null);
			$pvWindow->push($pv);
			$vWindow->push($size);
			$p2vWindow->push($p2v);
			return;
		}
		$this->sumPv += $pv;
		$this->sumV += $size;
		$this->sumP2v += $p2v;
	}

	private function totals(): float
	{
		if ($this->window === 0) {
			return $this->sumV;
		}
		$vWindow = $this->v;
		\assert($vWindow !== null);
		return $vWindow->count() === 0 ? 0.0 : $vWindow->mean() * $vWindow->count();
	}

	private function weighted(RollingMoments|null $window, float $fallback): float
	{
		if ($this->window === 0) {
			return $fallback;
		}
		\assert($window !== null);
		return $window->count() === 0 ? 0.0 : $window->mean() * $window->count();
	}

	public function isReady(): bool
	{
		if ($this->window > 0) {
			$vWindow = $this->v;
			\assert($vWindow !== null);
			return $vWindow->isFull() && $this->totals() > 0.0;
		}
		return $this->sumV > 0.0;
	}

	public function value(): float
	{
		$volume = $this->totals();
		if (!($volume > 0.0)) {
			return \NAN;
		}
		return $this->weighted($this->pv, $this->sumPv) / $volume;
	}

	/** Volume-weighted standard deviation of the trade prices in scope. */
	public function dispersion(): float
	{
		$volume = $this->totals();
		if (!($volume > 0.0)) {
			return \NAN;
		}
		$vwap = $this->weighted($this->pv, $this->sumPv) / $volume;
		$second = $this->weighted($this->p2v, $this->sumP2v) / $volume;
		$variance = $second - $vwap * $vwap;
		// Cancellation of two nearly equal large numbers can leave a tiny
		// negative; a variance is not negative, and a NAN band is worse than a
		// zero one.
		return $variance > 0.0 ? \sqrt($variance) : 0.0;
	}

	/** @return array{vwap: float, sigma: float, upper: float, lower: float, deviation: float, z: float} */
	public function values(): array
	{
		$vwap = $this->value();
		$sigma = $this->dispersion();
		return [
			'vwap' => $vwap,
			'sigma' => $sigma,
			'upper' => \is_nan($vwap) ? \NAN : $vwap + $this->bandK * $sigma,
			'lower' => \is_nan($vwap) ? \NAN : $vwap - $this->bandK * $sigma,
			'deviation' => \is_nan($vwap) || !($this->price > 0.0) ? \NAN : ($this->price - $vwap) / $this->price,
			'z' => \is_nan($vwap) || !($sigma > 0.0) ? \NAN : ($this->price - $vwap) / $sigma,
		];
	}

	public function reset(): void
	{
		$this->pv?->reset();
		$this->v?->reset();
		$this->p2v?->reset();
		$this->sumPv = 0.0;
		$this->sumV = 0.0;
		$this->sumP2v = 0.0;
		$this->price = \NAN;
		$this->anchorNs = 0;
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
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 0,
			isset($config['bandK']) && (\is_float($config['bandK']) || \is_int($config['bandK'])) ? (float) $config['bandK'] : 2.0,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Anchored VWAP accumulated from the first trade of the series.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return list<float>
	 */
	public static function anchoredSeries(array $prices, array $sizes): array
	{
		return self::bands($prices, $sizes, 0, 2.0)['vwap'];
	}

	/**
	 * Rolling VWAP over the last `window` trades.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return list<float>
	 */
	public static function rollingSeries(array $prices, array $sizes, int $window): array
	{
		return self::bands($prices, $sizes, $window, 2.0)['vwap'];
	}

	/**
	 * VWAP with its dispersion bands — the form that actually produces a signal.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return array{vwap: list<float>, upper: list<float>, lower: list<float>, sigma: list<float>}
	 */
	public static function bands(array $prices, array $sizes, int $window = 0, float $bandK = 2.0): array
	{
		$n = \count($prices);
		if (\count($sizes) !== $n) {
			throw new InvalidArgument('VWAP needs prices and sizes of equal length');
		}
		$metric = new self($window, $bandK);
		$vwap = [];
		$upper = [];
		$lower = [];
		$sigma = [];
		for ($i = 0; $i < $n; $i++) {
			$metric->feed($prices[$i], $sizes[$i]);
			$values = $metric->values();
			$vwap[] = $values['vwap'];
			$upper[] = $values['upper'];
			$lower[] = $values['lower'];
			$sigma[] = $values['sigma'];
		}
		return ['vwap' => $vwap, 'upper' => $upper, 'lower' => $lower, 'sigma' => $sigma];
	}

	/**
	 * (P − VWAP) / P — the reference RD_VWAP (M-28), dimensionless and
	 * comparable across instruments.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $sizes
	 * @return list<float>
	 */
	public static function relativeDeviation(array $prices, array $sizes, int $window = 0): array
	{
		$n = \count($prices);
		if (\count($sizes) !== $n) {
			throw new InvalidArgument('VWAP needs prices and sizes of equal length');
		}
		$metric = new self($window);
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$metric->feed($prices[$i], $sizes[$i]);
			$out[] = $metric->values()['deviation'];
		}
		return $out;
	}
}
