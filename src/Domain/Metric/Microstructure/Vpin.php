<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Microstructure;

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\TradeMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\VolumeClock;

/**
 * Volume-synchronised probability of informed trading (ROADMAP §4.9 M-30;
 * Easley, López de Prado and O'Hara, 2012).
 *
 *   VPIN = Σ_{i=1..n} |V^buy_i − V^sell_i| / (n · V)
 *
 * The tape is cut into buckets of equal volume `V`, each bucket's volume is
 * split into buyer- and seller-initiated parts, and VPIN is the average
 * absolute imbalance over the last `n` buckets, normalised to [0, 1].
 *
 * Two design choices carry the idea. First, **volume time**: a bucket is a
 * fixed quantity of trading, not a fixed number of seconds, so a quiet hour
 * and a violent minute are the same length and the measure does not need to be
 * told which is which. Second, **absolute** imbalance: toxicity has no
 * direction. Persistent one-sided flow is dangerous to a market maker whether
 * it is buying or selling, because it is the signature of someone who knows
 * something.
 *
 * High VPIN precedes widening spreads and volatility bursts; it was one of the
 * measures examined after the 2010 Flash Crash. Its predictive power has been
 * disputed in the literature — some of the apparent forecasting ability is
 * attributable to the volume clock itself rather than to the classification —
 * so it is worth treating as a regime flag rather than as a probability in the
 * strict sense, whatever its name suggests.
 *
 * This is the only metric in the library that does not run on calendar time.
 */
final class Vpin implements TradeMetric
{
	private VolumeClock $clock;

	public function __construct(
		public readonly float $bucketSize = 1000.0,
		public readonly int $buckets = 50,
	) {
		if ($buckets < 1) {
			throw new InvalidArgument('VPIN needs at least one bucket');
		}
		$this->clock = new VolumeClock($bucketSize, $buckets);
	}

	public static function type(): string
	{
		return 'vpin';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-30',
			symbol: 'VPIN',
			category: MetricCategory::Microstructure,
			inputs: [MetricInput::Trades],
			kernels: ['Vpin::compute()', 'Vpin::fromBuckets()'],
			nameEn: 'Flow toxicity',
			nameRu: 'Токсичность потока',
			algoEn: 'Tells a market maker when to widen or step away: it rises before spreads widen and volatility bursts. Treat it as a regime flag rather than a probability.',
			algoRu: 'Подсказывает маркет-мейкеру, когда расширить котировку или отойти: растёт перед расширением спреда и всплесками волатильности. Стоит считать его флагом режима, а не вероятностью.',
			plainEn: 'Average absolute buy-sell imbalance across buckets of equal traded volume, normalised to a fraction.',
			plainRu: 'Средний абсолютный дисбаланс покупок и продаж по бакетам равного торгового объёма, нормированный в долю.',
			example: 'examples/micro-vpin.php',
		);
	}

	public function updateTrade(Trade $trade): void
	{
		$sign = $trade->side->sign();
		// An unclassified trade is split evenly rather than dropped: dropping
		// it would shrink the bucket's volume and inflate the imbalance of
		// whatever remains.
		$buyFraction = $sign > 0.0 ? 1.0 : ($sign < 0.0 ? 0.0 : 0.5);
		$this->clock->add($trade->size, $trade->size * $buyFraction);
	}

	/** Adds volume already split into buyer- and seller-initiated parts. */
	public function addVolume(float $volume, float $buyVolume): void
	{
		$this->clock->add($volume, $buyVolume);
	}

	public function isReady(): bool
	{
		return $this->clock->imbalances()->isFull();
	}

	public function value(): float
	{
		$imbalances = $this->clock->imbalances();
		$n = $imbalances->count();
		if ($n < $this->buckets) {
			return \NAN;
		}
		$sum = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$sum += \abs($imbalances->at($i));
		}
		return $sum / ($n * $this->bucketSize);
	}

	/** @return array{vpin: float, buckets: float, pending: float} */
	public function values(): array
	{
		return [
			'vpin' => $this->value(),
			'buckets' => (float) $this->clock->completedBuckets(),
			'pending' => $this->clock->pending(),
		];
	}

	public function reset(): void
	{
		$this->clock->reset();
	}

	/** @return array{type: string, bucketSize: float, buckets: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'bucketSize' => $this->bucketSize, 'buckets' => $this->buckets];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['bucketSize']) && (\is_float($config['bucketSize']) || \is_int($config['bucketSize']))
				? (float) $config['bucketSize']
				: 1000.0,
			isset($config['buckets']) && \is_int($config['buckets']) ? $config['buckets'] : 50,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * VPIN over a trade series.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $sizes
	 * @param list<float> $signs +1 buyer-initiated, −1 seller-initiated, 0 unknown (split evenly)
	 * @return list<float> aligned with the input, NAN until `buckets` buckets have closed
	 */
	public static function compute(array $sizes, array $signs, float $bucketSize = 1000.0, int $buckets = 50): array
	{
		$n = \count($sizes);
		if (\count($signs) !== $n) {
			throw new InvalidArgument('VPIN needs sizes and signs of equal length');
		}
		$metric = new self($bucketSize, $buckets);
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$size = $sizes[$i];
			$sign = $signs[$i];
			$buyFraction = $sign > 0.0 ? 1.0 : ($sign < 0.0 ? 0.0 : 0.5);
			$metric->addVolume($size, $size * $buyFraction);
			$out[] = $metric->value();
		}
		return $out;
	}

	/**
	 * VPIN from buckets that are already formed and split — the form to use
	 * with bulk volume classification, which produces a buy fraction per
	 * bucket rather than a side per trade.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $buyVolumes
	 * @param list<float> $sellVolumes
	 * @return list<float>
	 */
	public static function fromBuckets(array $buyVolumes, array $sellVolumes, int $buckets = 50): array
	{
		$n = \count($buyVolumes);
		if (\count($sellVolumes) !== $n) {
			throw new InvalidArgument('VPIN needs buy and sell volumes of equal length');
		}
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			if ($i + 1 < $buckets) {
				$out[] = \NAN;
				continue;
			}
			$sum = 0.0;
			$total = 0.0;
			$first = $i - $buckets + 1;
			for ($k = $first < 0 ? 0 : $first; $k <= $i; $k++) {
				$buy = $buyVolumes[$k];
				$sell = $sellVolumes[$k];
				$sum += \abs($buy - $sell);
				$total += $buy + $sell;
			}
			$out[] = $total > 0.0 ? $sum / $total : \NAN;
		}
		return $out;
	}
}
