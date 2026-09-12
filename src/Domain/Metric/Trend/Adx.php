<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Trend;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Contract\BarMetric;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricCategory;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;
use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricInput;

/**
 * Average directional index and the directional movement pair (ROADMAP §4.3
 * M-13).
 *
 *   TR   = max(H − L, |H − C_prev|, |L − C_prev|)
 *   +DM  = up move   if it exceeds the down move and is positive, else 0
 *   −DM  = down move if it exceeds the up move   and is positive, else 0
 *   ±DI  = 100 · RMA_N(±DM) / RMA_N(TR)
 *   DX   = 100 · |+DI − −DI| / (+DI + −DI)
 *   ADX  = RMA_N(DX)
 *
 * ADX measures how *directional* the market is, not which way it is going:
 * above 25 a trend-following rule has an edge, below 20 it is being chopped
 * up. The direction comes from the ±DI pair, and their crossing is the entry.
 *
 * The warm-up is the part implementations get wrong. ADX is Wilder-smoothed
 * twice — once into the DI pair, once into the index itself — so with the
 * default period it needs on the order of 150 bars before its value stops
 * depending on where the series happened to start. `isReady()` accounts for
 * both stages, and `isSettled()` reports the stricter condition.
 *
 * Only one directional move can be counted per bar: if both the high and the
 * low extend beyond the previous bar, the larger move wins and the other is
 * zero. An outside bar is not evidence of direction.
 */
final class Adx implements BarMetric
{
	/** ADX with the default period needs ~150 bars before its seed stops showing. */
	private const SETTLING_MULTIPLE = 11;

	private MovingAverage $trSmoother;

	private MovingAverage $plusSmoother;

	private MovingAverage $minusSmoother;

	private MovingAverage $dxSmoother;

	private float $previousHigh = \NAN;

	private float $previousLow = \NAN;

	private float $previousClose = \NAN;

	private int $bars = 0;

	public function __construct(public readonly int $period = 14)
	{
		if ($period < 1) {
			throw new InvalidArgument(\sprintf('ADX period must be >= 1, got %d', $period));
		}
		$this->trSmoother = new MovingAverage($period, MaType::Rma);
		$this->plusSmoother = new MovingAverage($period, MaType::Rma);
		$this->minusSmoother = new MovingAverage($period, MaType::Rma);
		$this->dxSmoother = new MovingAverage($period, MaType::Rma);
	}

	public static function type(): string
	{
		return 'adx';
	}

	public static function describe(): MetricDescriptor
	{
		return new MetricDescriptor(
			type: self::type(),
			id: 'M-13',
			symbol: 'ADX/+DI/-DI',
			category: MetricCategory::Trend,
			inputs: [MetricInput::Bars],
			kernels: ['Adx::compute()', 'Adx::directional()'],
			nameEn: 'Average directional index',
			nameRu: 'Индекс направленного движения',
			algoEn: 'Above 25 trend-follow, below 20 stay out; the DI pair gives the direction. Double Wilder smoothing means it needs roughly ten periods of warm-up before it is meaningful.',
			algoRu: 'Выше 25 идти за трендом, ниже 20 не входить; направление даёт пара DI. Двойное сглаживание Уайлдера требует примерно десяти периодов прогрева, прежде чем значение станет осмысленным.',
			plainEn: 'Wilder-smoothed strength of directional movement, indifferent to direction, with the directional indicator pair it is built from.',
			plainRu: 'Сглаженная по Уайлдеру сила направленного движения, безразличная к направлению, вместе с парой направленных индикаторов, из которых она построена.',
			example: 'examples/trend-adx.php',
		);
	}

	public function updateBar(Bar $bar): void
	{
		$this->feed($bar->high, $bar->low, $bar->close);
	}

	private function feed(float $high, float $low, float $close): void
	{
		$this->bars++;
		if (\is_nan($this->previousClose)) {
			$this->previousHigh = $high;
			$this->previousLow = $low;
			$this->previousClose = $close;
			return;
		}

		$range = $high - $low;
		$up = \abs($high - $this->previousClose);
		$down = \abs($low - $this->previousClose);
		if ($up > $range) {
			$range = $up;
		}
		if ($down > $range) {
			$range = $down;
		}

		$upMove = $high - $this->previousHigh;
		$downMove = $this->previousLow - $low;
		$plusDm = $upMove > $downMove && $upMove > 0.0 ? $upMove : 0.0;
		$minusDm = $downMove > $upMove && $downMove > 0.0 ? $downMove : 0.0;

		$this->previousHigh = $high;
		$this->previousLow = $low;
		$this->previousClose = $close;

		$this->trSmoother->updatePrice(0, $range);
		$this->plusSmoother->updatePrice(0, $plusDm);
		$this->minusSmoother->updatePrice(0, $minusDm);

		$dx = $this->directionalIndex();
		if (!\is_nan($dx)) {
			$this->dxSmoother->updatePrice(0, $dx);
		}
	}

	private function directionalIndex(): float
	{
		$tr = $this->trSmoother->value();
		if (\is_nan($tr) || $tr <= 0.0) {
			return \NAN;
		}
		$plus = 100.0 * $this->plusSmoother->value() / $tr;
		$minus = 100.0 * $this->minusSmoother->value() / $tr;
		$sum = $plus + $minus;
		// No directional movement at all in the window: the index is 0, not 0/0.
		return $sum > 0.0 ? 100.0 * \abs($plus - $minus) / $sum : 0.0;
	}

	public function isReady(): bool
	{
		return $this->dxSmoother->isReady();
	}

	/**
	 * True once the double smoothing has forgotten its seed: roughly ten
	 * periods past the point where a value first appears. Below that the ADX
	 * is a number, but it is still largely an artefact of initialisation.
	 */
	public function isSettled(): bool
	{
		return $this->bars >= self::SETTLING_MULTIPLE * $this->period;
	}

	public function value(): float
	{
		return $this->dxSmoother->value();
	}

	/** @return array{adx: float, plusDi: float, minusDi: float, dx: float} */
	public function values(): array
	{
		$tr = $this->trSmoother->value();
		$plus = \is_nan($tr) || $tr <= 0.0 ? \NAN : 100.0 * $this->plusSmoother->value() / $tr;
		$minus = \is_nan($tr) || $tr <= 0.0 ? \NAN : 100.0 * $this->minusSmoother->value() / $tr;
		return [
			'adx' => $this->value(),
			'plusDi' => $plus,
			'minusDi' => $minus,
			'dx' => $this->directionalIndex(),
		];
	}

	public function reset(): void
	{
		$this->trSmoother->reset();
		$this->plusSmoother->reset();
		$this->minusSmoother->reset();
		$this->dxSmoother->reset();
		$this->previousHigh = \NAN;
		$this->previousLow = \NAN;
		$this->previousClose = \NAN;
		$this->bars = 0;
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
	 * @return list<float> the ADX, aligned with the input and NAN during warm-up
	 */
	public static function compute(array $highs, array $lows, array $closes, int $period = 14): array
	{
		$result = self::directional($highs, $lows, $closes, $period);
		return $result['adx'];
	}

	/**
	 * Every output: the index, the directional pair and the raw DX.
	 *
	 * @deterministic
	 * @offloadable
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return array{adx: list<float>, plusDi: list<float>, minusDi: list<float>, dx: list<float>}
	 */
	public static function directional(array $highs, array $lows, array $closes, int $period = 14): array
	{
		$n = \count($closes);
		if (\count($highs) !== $n || \count($lows) !== $n) {
			throw new InvalidArgument('ADX needs highs, lows and closes of equal length');
		}
		$metric = new self($period);
		$adx = [];
		$plus = [];
		$minus = [];
		$dx = [];
		for ($i = 0; $i < $n; $i++) {
			$metric->feed($highs[$i], $lows[$i], $closes[$i]);
			$values = $metric->values();
			$adx[] = $values['adx'];
			$plus[] = $values['plusDi'];
			$minus[] = $values['minusDi'];
			$dx[] = $values['dx'];
		}
		return ['adx' => $adx, 'plusDi' => $plus, 'minusDi' => $minus, 'dx' => $dx];
	}
}
