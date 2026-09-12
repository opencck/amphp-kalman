<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Microstructure;

use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Entity\TradeSide;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\TradeMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;
use OpenCCK\Kalman\Domain\Metric\Support\RollingMoments;

/**
 * Who was the aggressor (ROADMAP §4.9 M-32).
 *
 * Every flow measure in this namespace — order-flow imbalance, VPIN, Kyle's
 * lambda, volume delta — is a function of trade direction, and most public
 * tapes do not publish it. It has to be inferred, and the inference is a
 * documented algorithm rather than a guess:
 *
 *  - **Lee–Ready**: above the prevailing mid it is a buy, below it a sell, and
 *    exactly at the mid the tick rule decides. Needs the quote that was
 *    standing when the trade printed, which is why `observeMid()` exists.
 *  - **Tick rule**: an uptick is a buy, a downtick a sell, an unchanged price
 *    inherits the previous classification. Needs no quotes at all, and is the
 *    fallback when only a tape is available.
 *  - **Bulk volume classification**: does not classify individual trades at
 *    all. It assigns a *fraction* of a bucket's volume to buyers from the
 *    standardised price change across the bucket, Φ(Δp/σ). Tick-level rules
 *    degrade badly when one order is chopped into hundreds of prints by a
 *    matching engine; BVC sidesteps that by never looking at a single print.
 *
 * When the mid is unknown, Lee–Ready silently becomes the tick rule; that is
 * the standard fallback and it is recorded in `values()` so a caller can see
 * how much of a sample was classified on quotes and how much on ticks.
 */
final class TradeClassifier implements TradeMetric
{
	private float $previousPrice = \NAN;

	private float $mid = \NAN;

	private float $sign = 0.0;

	private int $classified = 0;

	private int $byQuote = 0;

	private RollingMoments $signs;

	public function __construct(
		public readonly bool $useQuotes = true,
		public readonly int $window = 100,
	) {
		if ($window < 1) {
			throw new InvalidArgument('TradeClassifier window must be >= 1');
		}
		$this->signs = new RollingMoments($window);
	}

	public static function type(): string
	{
		return 'trade-classifier';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-32',
			symbol: 'b',
			category: MetricCategory::Microstructure,
			inputs: [MetricInput::Trades],
			kernels: ['TradeClassifier::leeReady()', 'TradeClassifier::tickRule()', 'TradeClassifier::bulkVolume()'],
			nameEn: 'Trade classification',
			nameRu: 'Классификация сделок',
			algoEn: 'The prerequisite for every flow measure: who lifted whom. Public tapes rarely publish it, so it is inferred by a stated algorithm rather than assumed.',
			algoRu: 'Предпосылка любой метрики потока: кто кого снял. Публичные ленты редко публикуют это, поэтому направление выводится заявленным алгоритмом, а не предполагается.',
			plainEn: 'Buyer- or seller-initiated label for each trade, from the quote it crossed or from the direction of the price change.',
			plainRu: 'Метка сделки как инициированной покупателем или продавцом, по пересечённой котировке или по направлению изменения цены.',
			example: 'examples/micro-trade-sign.php',
		);
	}

	/** Records the mid that is standing now, for the trades that follow. */
	public function observeMid(float $mid): void
	{
		$this->mid = $mid;
	}

	public function updateTrade(Trade $trade): void
	{
		$this->feed($trade->price);
	}

	private function feed(float $price): void
	{
		$sign = 0.0;
		$fromQuote = false;
		if ($this->useQuotes && !\is_nan($this->mid)) {
			if ($price > $this->mid) {
				$sign = 1.0;
				$fromQuote = true;
			} elseif ($price < $this->mid) {
				$sign = -1.0;
				$fromQuote = true;
			}
		}
		if ($sign === 0.0) {
			$sign = self::tick($price, $this->previousPrice, $this->sign);
		}
		$this->previousPrice = $price;
		$this->sign = $sign;
		$this->classified++;
		if ($fromQuote) {
			$this->byQuote++;
		}
		$this->signs->push($sign);
	}

	/** The side of the trade just classified. */
	public function side(): TradeSide
	{
		return TradeSide::fromSign($this->sign);
	}

	public function isReady(): bool
	{
		return $this->classified > 0;
	}

	/** +1 buyer-initiated, −1 seller-initiated, 0 undetermined. */
	public function value(): float
	{
		return $this->isReady() ? $this->sign : \NAN;
	}

	/** @return array{sign: float, meanSign: float, quoteShare: float, classified: float} */
	public function values(): array
	{
		return [
			'sign' => $this->value(),
			'meanSign' => $this->signs->count() > 0 ? $this->signs->mean() : \NAN,
			'quoteShare' => $this->classified > 0 ? (float) $this->byQuote / $this->classified : \NAN,
			'classified' => (float) $this->classified,
		];
	}

	public function reset(): void
	{
		$this->previousPrice = \NAN;
		$this->mid = \NAN;
		$this->sign = 0.0;
		$this->classified = 0;
		$this->byQuote = 0;
		$this->signs->reset();
	}

	/** @return array{type: string, useQuotes: bool, window: int} */
	public function toArray(): array
	{
		return ['type' => self::type(), 'useQuotes' => $this->useQuotes, 'window' => $this->window];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['useQuotes']) && \is_bool($config['useQuotes']) ? $config['useQuotes'] : true,
			isset($config['window']) && \is_int($config['window']) ? $config['window'] : 100,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * Lee–Ready: the quote decides, the tick rule breaks ties at the mid.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @param list<float> $mids the mid standing when each trade printed; NAN where unknown
	 * @return list<float> +1, −1 or 0
	 */
	public static function leeReady(array $prices, array $mids): array
	{
		$n = \count($prices);
		if (\count($mids) !== $n) {
			throw new InvalidArgument('leeReady needs one mid per trade');
		}
		$out = [];
		$previous = \NAN;
		$last = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$price = $prices[$i];
			$mid = $mids[$i];
			$sign = 0.0;
			if (!\is_nan($mid)) {
				if ($price > $mid) {
					$sign = 1.0;
				} elseif ($price < $mid) {
					$sign = -1.0;
				}
			}
			if ($sign === 0.0) {
				$sign = self::tick($price, $previous, $last);
			}
			$previous = $price;
			$last = $sign;
			$out[] = $sign;
		}
		return $out;
	}

	/**
	 * The tick rule alone: no quotes needed.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return list<float>
	 */
	public static function tickRule(array $prices): array
	{
		$out = [];
		$previous = \NAN;
		$last = 0.0;
		foreach ($prices as $price) {
			$sign = self::tick($price, $previous, $last);
			$previous = $price;
			$last = $sign;
			$out[] = $sign;
		}
		return $out;
	}

	/**
	 * Bulk volume classification: the buyer-initiated *fraction* of each
	 * bucket's volume, from the standardised price change across the bucket.
	 *
	 * Φ is approximated with the logistic form `1/(1+exp(−1.702·z))`, which is
	 * within 0.01 of the normal CDF everywhere — far tighter than the sampling
	 * error of σ in the denominator, and it avoids depending on `erf()`.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $bucketCloses closing price of each bucket
	 * @return list<float> buy fraction in [0, 1]; the first bucket is NAN
	 */
	public static function bulkVolume(array $bucketCloses, int $sigmaWindow = 50): array
	{
		if ($sigmaWindow < 2) {
			throw new InvalidArgument('bulkVolume sigma window must be >= 2');
		}
		$changes = new RollingMoments($sigmaWindow);
		$out = [];
		$previous = \NAN;
		foreach ($bucketCloses as $close) {
			if (\is_nan($previous)) {
				$previous = $close;
				$out[] = \NAN;
				continue;
			}
			$change = $close - $previous;
			$previous = $close;
			$changes->push($change);
			$sigma = $changes->stdDev();
			if (\is_nan($sigma) || !($sigma > 0.0)) {
				$out[] = \NAN;
				continue;
			}
			$out[] = 1.0 / (1.0 + \exp(-1.702 * $change / $sigma));
		}
		return $out;
	}

	private static function tick(float $price, float $previous, float $lastSign): float
	{
		if (\is_nan($previous)) {
			return $lastSign;
		}
		if ($price > $previous) {
			return 1.0;
		}
		return $price < $previous ? -1.0 : $lastSign;
	}
}
