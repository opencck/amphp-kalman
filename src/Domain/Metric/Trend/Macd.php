<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Trend;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\PriceMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;

/**
 * Moving average convergence/divergence (ROADMAP §4.3 M-11).
 *
 *   MACD      = EMA_fast(P) − EMA_slow(P)      12 and 26 by default
 *   Signal    = EMA_signal(MACD)               9 by default
 *   Histogram = MACD − Signal
 *
 * Appel's indicator is built from **exponential** averages, and it has three
 * outputs, each carrying its own trading rule: MACD crossing zero (the fast
 * average overtakes the slow one), MACD crossing the signal line, and the
 * histogram diverging from price. The reference PromQL expression differs on
 * both counts (§4.3):
 *
 *  - it uses `avg_over_time`, a flat rolling average, in place of both EMAs;
 *  - it collapses the algebra to the histogram alone, so neither line is
 *    available and two of the three rules cannot be evaluated;
 *  - and its windows are 8 and 17 rather than 12 and 26. Matching centres of
 *    mass, an SMA over M samples sits where an EMA of period M sits, so those
 *    windows are roughly two thirds of the standard ones: the indicator is
 *    faster than the one a trader sees in a terminal, and its crossings happen
 *    at different times.
 *
 * `referenceEquivalent()` builds the reference's configuration so the two can
 * be plotted side by side — see `examples/trend-macd.php`.
 */
final class Macd implements PriceMetric
{
	private MovingAverage $fastMa;

	private MovingAverage $slowMa;

	private MovingAverage $signalMa;

	private float $macd = \NAN;

	public function __construct(
		public readonly int $fast = 12,
		public readonly int $slow = 26,
		public readonly int $signal = 9,
		public readonly MaType $type = MaType::Ema,
	) {
		if ($fast < 1 || $slow < 1 || $signal < 1) {
			throw new InvalidArgument('MACD periods must be >= 1');
		}
		if ($fast >= $slow) {
			throw new InvalidArgument(\sprintf('MACD fast period (%d) must be shorter than the slow one (%d)', $fast, $slow));
		}
		$this->fastMa = new MovingAverage($fast, $type);
		$this->slowMa = new MovingAverage($slow, $type);
		$this->signalMa = new MovingAverage($signal, $type);
	}

	/**
	 * The reference PromQL configuration: flat rolling averages over 8 and 17
	 * samples with a 9-sample signal. Not the MACD, but what the existing
	 * dashboards plot.
	 */
	public static function referenceEquivalent(): self
	{
		return new self(8, 17, 9, MaType::Sma);
	}

	public static function type(): string
	{
		return 'macd';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-11',
			symbol: 'MACD',
			category: MetricCategory::Trend,
			inputs: [MetricInput::Ticks, MetricInput::Bars],
			kernels: ['Macd::compute()', 'Macd::computeTimed()'],
			nameEn: 'MACD',
			nameRu: 'MACD',
			algoEn: 'Three tradable lines: the zero crossing, the signal crossing and histogram divergence. The reference expression exposes only the histogram, and builds it from flat averages over 8 and 17 samples instead of EMAs of 12 and 26.',
			algoRu: 'Три торгуемые линии: пересечение нуля, пересечение сигнальной и дивергенция гистограммы. Референсное выражение отдаёт только гистограмму и строит её на простых средних 8 и 17 вместо EMA 12 и 26.',
			plainEn: 'Difference between a fast and a slow exponential average of the price, together with an exponential average of that difference.',
			plainRu: 'Разность быстрой и медленной экспоненциальных средних цены вместе с экспоненциальной средней этой разности.',
			example: 'examples/trend-macd.php',
		);
	}

	public function updatePrice(int $timestampNs, float $price): void
	{
		$this->fastMa->updatePrice($timestampNs, $price);
		$this->slowMa->updatePrice($timestampNs, $price);
		$fast = $this->fastMa->value();
		$slow = $this->slowMa->value();
		if (\is_nan($fast) || \is_nan($slow)) {
			$this->macd = \NAN;
			return;
		}
		$this->macd = $fast - $slow;
		$this->signalMa->updatePrice($timestampNs, $this->macd);
	}

	public function isReady(): bool
	{
		return $this->signalMa->isReady();
	}

	/** The MACD line itself. */
	public function value(): float
	{
		return $this->macd;
	}

	/** @return array{macd: float, signal: float, histogram: float} */
	public function values(): array
	{
		$signal = $this->signalMa->value();
		return [
			'macd' => $this->macd,
			'signal' => $signal,
			'histogram' => \is_nan($signal) || \is_nan($this->macd) ? \NAN : $this->macd - $signal,
		];
	}

	public function reset(): void
	{
		$this->fastMa->reset();
		$this->slowMa->reset();
		$this->signalMa->reset();
		$this->macd = \NAN;
	}

	/** @return array{type: string, fast: int, slow: int, signal: int, ma: string} */
	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'fast' => $this->fast,
			'slow' => $this->slow,
			'signal' => $this->signal,
			'ma' => $this->type->value,
		];
	}

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self
	{
		return new self(
			isset($config['fast']) && \is_int($config['fast']) ? $config['fast'] : 12,
			isset($config['slow']) && \is_int($config['slow']) ? $config['slow'] : 26,
			isset($config['signal']) && \is_int($config['signal']) ? $config['signal'] : 9,
			isset($config['ma']) && \is_string($config['ma']) ? MaType::from($config['ma']) : MaType::Ema,
		);
	}

	// ---------------------------------------------------------------- kernels

	/**
	 * All three outputs over a price series.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $prices
	 * @return array{macd: list<float>, signal: list<float>, histogram: list<float>}
	 */
	public static function compute(array $prices, int $fast = 12, int $slow = 26, int $signal = 9): array
	{
		$metric = new self($fast, $slow, $signal);
		$macd = [];
		$signalLine = [];
		$histogram = [];
		foreach ($prices as $price) {
			$metric->updatePrice(0, $price);
			$values = $metric->values();
			$macd[] = $values['macd'];
			$signalLine[] = $values['signal'];
			$histogram[] = $values['histogram'];
		}
		return ['macd' => $macd, 'signal' => $signalLine, 'histogram' => $histogram];
	}

	/**
	 * The same construction on an irregular stream: the three periods become
	 * three time constants in seconds, so the indicator spans a fixed amount of
	 * market time rather than a fixed number of ticks.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<int> $timestampsNs
	 * @param list<float> $prices
	 * @return array{macd: list<float>, signal: list<float>, histogram: list<float>}
	 */
	public static function computeTimed(
		array $timestampsNs,
		array $prices,
		float $tauFast,
		float $tauSlow,
		float $tauSignal,
	): array {
		if (\count($timestampsNs) !== \count($prices)) {
			throw new InvalidArgument('computeTimed needs one timestamp per price');
		}
		if (!($tauFast > 0.0) || !($tauSlow > 0.0) || !($tauSignal > 0.0)) {
			throw new InvalidArgument('computeTimed time constants must be > 0');
		}
		if ($tauFast >= $tauSlow) {
			throw new InvalidArgument('computeTimed fast tau must be shorter than the slow one');
		}
		$fastMa = MovingAverage::timed($tauFast);
		$slowMa = MovingAverage::timed($tauSlow);
		$signalMa = MovingAverage::timed($tauSignal);
		$macd = [];
		$signalLine = [];
		$histogram = [];
		foreach ($prices as $i => $price) {
			$ts = $timestampsNs[$i];
			$fastMa->updatePrice($ts, $price);
			$slowMa->updatePrice($ts, $price);
			$fast = $fastMa->value();
			$slow = $slowMa->value();
			$line = \is_nan($fast) || \is_nan($slow) ? \NAN : $fast - $slow;
			if (!\is_nan($line)) {
				$signalMa->updatePrice($ts, $line);
			}
			$signalValue = $signalMa->value();
			$macd[] = $line;
			$signalLine[] = $signalValue;
			$histogram[] = \is_nan($line) || \is_nan($signalValue) ? \NAN : $line - $signalValue;
		}
		return ['macd' => $macd, 'signal' => $signalLine, 'histogram' => $histogram];
	}
}
