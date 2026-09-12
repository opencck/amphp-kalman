<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Momentum\Stochastic;
use PHPUnit\Framework\TestCase;

/**
 * Lane's oscillator against an independent naive rescan of the window, plus
 * the three anchor points of the scale (top, bottom, no range) and the
 * relationship between the fast and the slow variant.
 */
final class StochasticTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive %K: rescan the window for its extremes on every bar.
	 *
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	private static function naivePercentK(array $highs, array $lows, array $closes, int $period): array
	{
		$out = [];
		foreach ($closes as $i => $close) {
			if ($i + 1 < $period) {
				$out[] = \NAN;
				continue;
			}
			$high = -\INF;
			foreach (\array_slice($highs, $i + 1 - $period, $period) as $value) {
				if ($value > $high) {
					$high = $value;
				}
			}
			$low = \INF;
			foreach (\array_slice($lows, $i + 1 - $period, $period) as $value) {
				if ($value < $low) {
					$low = $value;
				}
			}
			$range = $high - $low;
			$out[] = $range > 0.0 ? 100.0 * ($close - $low) / $range : 50.0;
		}
		return $out;
	}

	/**
	 * Naive simple moving average over the non-NAN tail of a series, aligned
	 * with the input.
	 *
	 * @param list<float> $values
	 * @return list<float>
	 */
	private static function naiveSmaOverDefinedValues(array $values, int $period): array
	{
		$out = [];
		$window = [];
		foreach ($values as $value) {
			if (\is_nan($value)) {
				$out[] = \NAN;
				continue;
			}
			$window[] = $value;
			if (\count($window) > $period) {
				\array_shift($window);
			}
			$out[] = \count($window) === $period ? \array_sum($window) / $period : \NAN;
		}
		return $out;
	}

	/**
	 * A deterministic bar series.
	 *
	 * @return array{highs: list<float>, lows: list<float>, closes: list<float>}
	 */
	private static function bars(int $n, int $seed): array
	{
		\mt_srand($seed);
		$highs = [];
		$lows = [];
		$closes = [];
		$price = 100.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-150, 150) / 100.0;
			$up = (float) \mt_rand(1, 120) / 100.0;
			$down = (float) \mt_rand(1, 120) / 100.0;
			$high = $price + $up;
			$low = $price - $down;
			$close = $low + ($high - $low) * ((float) \mt_rand(0, 1000) / 1000.0);
			$highs[] = $high;
			$lows[] = $low;
			$closes[] = $close;
		}
		return ['highs' => $highs, 'lows' => $lows, 'closes' => $closes];
	}

	/**
	 * @param list<float> $expected
	 * @param list<float> $actual
	 */
	private static function assertSeriesMatches(array $expected, array $actual, string $label): void
	{
		self::assertSame(\count($expected), \count($actual), "$label: length");
		foreach ($expected as $i => $want) {
			if (\is_nan($want)) {
				self::assertNan($actual[$i], "$label: index $i expected NAN");
				continue;
			}
			self::assertEqualsWithDelta($want, $actual[$i], self::TOLERANCE, "$label: index $i");
		}
	}

	/** @return iterable<string, array{int}> */
	public function periods(): iterable
	{
		yield 'period 1' => [1];
		yield 'period 3' => [3];
		yield 'period 5' => [5];
		yield 'period 14' => [14];
		yield 'period 30' => [30];
	}

	/** @dataProvider periods */
	public function testPercentKMatchesANaiveRescan(int $period): void
	{
		$bars = self::bars(400, 10 + $period);
		self::assertSeriesMatches(
			self::naivePercentK($bars['highs'], $bars['lows'], $bars['closes'], $period),
			Stochastic::percentK($bars['highs'], $bars['lows'], $bars['closes'], $period),
			"percentK($period)",
		);
	}

	/**
	 * %D is the simple average of the last `dPeriod` values of %K, and nothing
	 * else. The reference formula omits it, which is what leaves its version
	 * without a crossover signal.
	 *
	 * @dataProvider periods
	 */
	public function testPercentDIsTheSimpleAverageOfTheLastKValues(int $period): void
	{
		$bars = self::bars(400, 20 + $period);
		foreach ([2, 3, 5] as $dPeriod) {
			$k = Stochastic::percentK($bars['highs'], $bars['lows'], $bars['closes'], $period);
			$d = Stochastic::percentD($bars['highs'], $bars['lows'], $bars['closes'], $period, $dPeriod);
			self::assertSeriesMatches(self::naiveSmaOverDefinedValues($k, $dPeriod), $d, "percentD($period, $dPeriod)");
		}
	}

	/**
	 * The slow oscillator is the fast one with %K smoothed before the signal
	 * line, not a different formula.
	 *
	 * @dataProvider periods
	 */
	public function testSlowVariantIsTheSmoothedFastOne(int $period): void
	{
		$bars = self::bars(400, 30 + $period);
		$fast = Stochastic::full($bars['highs'], $bars['lows'], $bars['closes'], $period, 1, 3);
		$slow = Stochastic::full($bars['highs'], $bars['lows'], $bars['closes'], $period, 3, 3);

		self::assertSeriesMatches(self::naiveSmaOverDefinedValues($fast['k'], 3), $slow['k'], "slow %K($period)");
		// the slow %D is then the 3-bar average of the slow %K
		self::assertSeriesMatches(self::naiveSmaOverDefinedValues($slow['k'], 3), $slow['d'], "slow %D($period)");
		// and the fast %D equals the slow %K, which is the classical identity
		self::assertSeriesMatches($fast['d'], $slow['k'], "fast %D == slow %K ($period)");
	}

	/** A close at the top of the window is 100, at the bottom 0. */
	public function testPercentKAtTheExtremesOfTheRange(): void
	{
		$highs = [];
		$lows = [];
		$closes = [];
		$atHigh = [];
		$atLow = [];
		for ($i = 0; $i < 14; $i++) {
			$highs[] = 110.0;
			$lows[] = 90.0;
			$closes[] = 100.0;
			// the last bar closes at the top of the window, and at the bottom
			$atHigh[] = $i === 13 ? 110.0 : 100.0;
			$atLow[] = $i === 13 ? 90.0 : 100.0;
		}

		self::assertSame(100.0, Stochastic::percentK($highs, $lows, $atHigh, 14)[13]);
		self::assertSame(0.0, Stochastic::percentK($highs, $lows, $atLow, 14)[13]);

		// the midpoint, for scale
		self::assertSame(50.0, Stochastic::percentK($highs, $lows, $closes, 14)[13]);
	}

	/**
	 * A window with no range: the close is neither at the top nor at the
	 * bottom of nothing, so the honest answer is the midpoint, not a division
	 * by zero.
	 */
	public function testZeroRangeGivesFiftyRatherThanADivisionByZero(): void
	{
		$flat = \array_fill(0, 20, 100.0);
		$k = Stochastic::percentK($flat, $flat, $flat, 14);

		self::assertFalse(\is_nan($k[19]), 'a flat window must not be NAN');
		self::assertSame(50.0, $k[19]);
		self::assertSame(50.0, Stochastic::percentD($flat, $flat, $flat, 14, 3)[19]);
	}

	public function testWarmUpAndReadiness(): void
	{
		$metric = Stochastic::fast(14, 3);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertSame(0, $metric->barsSeen());

		$bars = self::bars(20, 4242);
		for ($i = 0; $i < 20; $i++) {
			$metric->updateBar(Bar::of(
				$i * 1_000_000_000,
				$i * 1_000_000_000 + 999,
				$bars['closes'][$i],
				$bars['highs'][$i],
				$bars['lows'][$i],
				$bars['closes'][$i],
			));
			// %K appears with the 14th bar, %D two bars later
			if ($i < 13) {
				self::assertNan($metric->values()['k'], "bar $i");
			} else {
				self::assertFalse(\is_nan($metric->values()['k']), "bar $i");
			}
			self::assertSame($i >= 15, $metric->isReady(), "bar $i readiness");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertSame(0, $metric->barsSeen());
	}

	/** The streaming object and the kernel are the same code, and must agree. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		$bars = self::bars(200, 5150);
		$kernel = Stochastic::full($bars['highs'], $bars['lows'], $bars['closes'], 14, 3, 3);

		$metric = Stochastic::slow(14, 3);
		for ($i = 0; $i < 200; $i++) {
			$metric->updateBar(Bar::of(
				$i * 1_000_000_000,
				$i * 1_000_000_000 + 999,
				$bars['closes'][$i],
				$bars['highs'][$i],
				$bars['lows'][$i],
				$bars['closes'][$i],
			));
			$values = $metric->values();
			if (\is_nan($kernel['k'][$i])) {
				self::assertNan($values['k'], "bar $i");
			} else {
				self::assertSame($kernel['k'][$i], $values['k'], "bar $i");
			}
			if (\is_nan($kernel['d'][$i])) {
				self::assertNan($values['d'], "bar $i");
			} else {
				self::assertSame($kernel['d'][$i], $values['d'], "bar $i");
			}
		}
	}

	public function testPeriodsMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Stochastic(0);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		Stochastic::full([1.0, 2.0], [1.0], [1.0, 2.0]);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = Stochastic::slow(21, 5);
		self::assertSame(['type' => 'stochastic', 'kPeriod' => 21, 'kSmooth' => 3, 'dPeriod' => 5], $metric->toArray());
		self::assertSame($metric->toArray(), Stochastic::fromArray($metric->toArray())->toArray());
	}
}
