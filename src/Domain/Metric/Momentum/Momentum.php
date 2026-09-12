<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Momentum;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RingBuffer;

/**
 * Normalised average momentum (ROADMAP §4.2 M-09, the reference NAPM).
 *
 * The lookback is cut into `count` consecutive blocks of `step` observations,
 * one log return is taken per block, and the average return is divided by the
 * standard deviation across blocks:
 *
 *   r_k  = ln(P_{t−(k−1)h} / P_{t−k·h}),  k = 1…K
 *   NAPM = mean(r) / sd(r)
 *
 * That ratio is return per unit of risk over the window — a Sharpe ratio
 * computed on the instrument's own recent path. Dividing by the dispersion is
 * what makes the number comparable at all: a raw average return of 0.4 % means
 * something entirely different on a stablecoin pair than on a small-cap
 * altcoin, and a trading rule with a fixed threshold needs them on one scale.
 *
 * The blocks do not overlap, unlike the reference `NdT` construction: each
 * return is an independent piece of evidence, so the dispersion in the
 * denominator is an honest estimate rather than one deflated by shared data.
 */
final class Momentum implements PriceMetric
{
	private RingBuffer $history;

	public function __construct(
		public readonly int $step = 60,
		public readonly int $count = 8,
	) {
		if ($step < 1) {
			throw new InvalidArgument(\sprintf('Momentum step must be >= 1, got %d', $step));
		}
		if ($count < 2) {
			throw new InvalidArgument(\sprintf('Momentum needs at least 2 blocks, got %d', $count));
		}
		$this->history = new RingBuffer($step * $count + 1);
	}

	public static function type(): string
	{
		return 'normalized-momentum';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-09',
			symbol: 'NAPM',
			category: MetricCategory::Momentum,
			inputs: [MetricInput::Ticks],
			kernels: ['Momentum::normalized()', 'Momentum::blockReturns()'],
			nameEn: 'Normalised momentum',
			nameRu: 'Нормализованный моментум',
			algoEn: 'Return per unit of risk over the window, so one threshold works across instruments of very different volatility.',
			algoRu: 'Доходность на единицу риска за окно, поэтому один порог работает на инструментах с совершенно разной волатильностью.',
			plainEn: 'Average log return across equal non-overlapping blocks, divided by the standard deviation across those blocks.',
			plainRu: 'Средняя лог-доходность по равным непересекающимся блокам, делённая на стандартное отклонение по этим блокам.',
			example: 'examples/momentum-normalized.php',
		);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		$this->history->push($price);
	}

	public function isReady(): bool
	{
		return $this->history->isFull();
	}

	public function value(): float
	{
		if (!$this->isReady()) {
			return \NAN;
		}
		return self::score($this->blockReturnsFromWindow());
	}

	/** @return array{napm: float, mean: float, sd: float} */
	public function values(): array
	{
		if (!$this->isReady()) {
			return ['napm' => \NAN, 'mean' => \NAN, 'sd' => \NAN];
		}
		$returns = $this->blockReturnsFromWindow();
		$mean = self::mean($returns);
		$sd = self::standardDeviation($returns, $mean);
		return ['napm' => $sd > 0.0 ? $mean / $sd : \NAN, 'mean' => $mean, 'sd' => $sd];
	}

	public function reset(): void
	{
		$this->history->reset();
	}

	/** @return array{type: string, step: int, count: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'step' => $this->step, 'count' => $this->count];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['step']) && \is_int($config['step']) ? $config['step'] : 60,
			isset($config['count']) && \is_int($config['count']) ? $config['count'] : 8,
		);
	}

	/** @return list<float> */
	private function blockReturnsFromWindow(): array
	{
		$out = [];
		for ($k = 0; $k < $this->count; $k++) {
			$newer = $this->history->at($this->step * ($k + 1));
			$older = $this->history->at($this->step * $k);
			$out[] = $newer > 0.0 && $older > 0.0 ? \log($newer / $older) : \NAN;
		}
		return $out;
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float> aligned with the input, NAN until the window is full
	 */
	public static function normalized(array $prices, int $step = 60, int $count = 8): array
	{
		$metric = new self($step, $count);
		$out = [];
		foreach ($prices as $price) {
			$metric->updatePrice(0, $price);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * The K non-overlapping block log returns ending at the last price, oldest
	 * block first. Exposed because the dispersion across them is itself a
	 * usable volatility reading.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function blockReturns(array $prices, int $step, int $count): array
	{
		$needed = $step * $count + 1;
		$n = \count($prices);
		if ($n < $needed) {
			throw new InvalidArgument(\sprintf('blockReturns needs %d prices, got %d', $needed, $n));
		}
		$offset = $n - $needed;
		$out = [];
		for ($k = 0; $k < $count; $k++) {
			$newerIndex = $offset + $step * ($k + 1);
			$olderIndex = $offset + $step * $k;
			if ($olderIndex < 0 || $newerIndex < 0) {
				$out[] = \NAN;
				continue;
			}
			$newer = $prices[$newerIndex];
			$older = $prices[$olderIndex];
			$out[] = $newer > 0.0 && $older > 0.0 ? \log($newer / $older) : \NAN;
		}
		return $out;
	}

	/** @param list<float> $returns */
	private static function score(array $returns): float
	{
		$mean = self::mean($returns);
		$sd = self::standardDeviation($returns, $mean);
		return $sd > 0.0 ? $mean / $sd : \NAN;
	}

	/** @param list<float> $values */
	private static function mean(array $values): float
	{
		$sum = 0.0;
		foreach ($values as $value) {
			$sum += $value;
		}
		return $sum / \count($values);
	}

	/** @param list<float> $values */
	private static function standardDeviation(array $values, float $mean): float
	{
		$n = \count($values);
		if ($n < 2) {
			return \NAN;
		}
		$sum = 0.0;
		foreach ($values as $value) {
			$d = $value - $mean;
			$sum += $d * $d;
		}
		return \sqrt($sum / ($n - 1));
	}
}
