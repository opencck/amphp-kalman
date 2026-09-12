<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Volatility\Atr;
use PHPUnit\Framework\TestCase;

/**
 * The average true range against a naive Wilder implementation.
 *
 * The two properties the definition turns on: a series of identical ranges
 * averages to exactly that range — Wilder's smoothing has the constant as a
 * fixed point — and the true range counts the gap to the previous close, which
 * the bar range alone cannot see.
 */
final class AtrTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive true range: the bar range or either gap to the previous close.
	 * The first bar has no previous close, so it is the plain range.
	 *
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	private static function naiveTrueRanges(array $highs, array $lows, array $closes): array
	{
		$out = [];
		$previousClose = \NAN;
		foreach ($closes as $i => $close) {
			$range = $highs[$i] - $lows[$i];
			$out[] = \is_nan($previousClose)
				? $range
				: \max($range, \abs($highs[$i] - $previousClose), \abs($lows[$i] - $previousClose));
			$previousClose = $close;
		}
		return $out;
	}

	/**
	 * Naive Wilder average of the true ranges, seeded with the simple average
	 * of the first `period` of them.
	 *
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	private static function naiveAtr(array $highs, array $lows, array $closes, int $period): array
	{
		$ranges = self::naiveTrueRanges($highs, $lows, $closes);
		$out = [];
		$value = 0.0;
		$seed = 0.0;
		foreach ($ranges as $i => $range) {
			if ($i + 1 < $period) {
				$seed += $range;
				$out[] = \NAN;
				continue;
			}
			if ($i + 1 === $period) {
				$seed += $range;
				$value = $seed / $period;
				$out[] = $value;
				continue;
			}
			$value += ($range - $value) / $period;
			$out[] = $value;
		}
		return $out;
	}

	/**
	 * A deterministic bar series with gaps, so the true range is not merely
	 * the bar range.
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
			// deliberately large jumps between bars, so gaps dominate the range
			$price += (float) \mt_rand(-400, 400) / 100.0;
			$high = $price + (float) \mt_rand(5, 90) / 100.0;
			$low = $price - (float) \mt_rand(5, 90) / 100.0;
			$highs[] = $high;
			$lows[] = $low;
			$closes[] = $low + ($high - $low) * ((float) \mt_rand(0, 1000) / 1000.0);
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
		yield 'period 2' => [2];
		yield 'period 14' => [14];
		yield 'period 30' => [30];
	}

	/** @dataProvider periods */
	public function testMatchesANaiveWilderImplementation(int $period): void
	{
		$bars = self::bars(500, 800 + $period);
		self::assertSeriesMatches(
			self::naiveAtr($bars['highs'], $bars['lows'], $bars['closes'], $period),
			Atr::compute($bars['highs'], $bars['lows'], $bars['closes'], $period),
			"atr($period)",
		);
	}

	public function testTrueRangesMatchANaiveImplementation(): void
	{
		$bars = self::bars(500, 4096);
		self::assertSeriesMatches(
			self::naiveTrueRanges($bars['highs'], $bars['lows'], $bars['closes']),
			Atr::trueRanges($bars['highs'], $bars['lows'], $bars['closes']),
			'trueRanges',
		);
	}

	/**
	 * Wilder's smoothing has the constant as a fixed point: a series of bars
	 * with an identical true range averages to exactly that range, with no
	 * transient and no drift.
	 */
	public function testConstantRangesGiveExactlyThatRange(): void
	{
		$highs = \array_fill(0, 60, 101.0);
		$lows = \array_fill(0, 60, 100.0);
		$closes = \array_fill(0, 60, 100.5);

		$atr = Atr::compute($highs, $lows, $closes, 14);
		for ($i = 0; $i < 13; $i++) {
			self::assertNan($atr[$i], "index $i");
		}
		for ($i = 13; $i < 60; $i++) {
			self::assertSame(1.0, $atr[$i], "index $i");
		}

		// and with a different range, to make sure it is not the value 1 that
		// happens to be a fixed point
		$wide = Atr::compute(\array_fill(0, 60, 110.0), \array_fill(0, 60, 100.0), \array_fill(0, 60, 105.0), 14);
		self::assertSame(10.0, $wide[59]);
	}

	/**
	 * The point of the true range: a market that gaps away from yesterday's
	 * close and then trades in a narrow band had a violent day, and H − L does
	 * not know it.
	 */
	public function testTrueRangeCountsTheGapToThePreviousClose(): void
	{
		// bar 0 closes at 100.5; bar 1 gaps up and trades in a 1-wide band
		$highs = [101.0, 105.0, 96.0];
		$lows = [100.0, 104.0, 95.0];
		$closes = [100.5, 104.5, 95.5];

		$ranges = Atr::trueRanges($highs, $lows, $closes);

		// no previous close on the first bar: the plain range
		self::assertSame(1.0, $ranges[0]);
		// gap up: |105 − 100.5| = 4.5 beats the 1-wide bar range
		self::assertSame(4.5, $ranges[1]);
		// gap down: |95 − 104.5| = 9.5 beats it again
		self::assertSame(9.5, $ranges[2]);

		// the bar range alone would have reported 1 on every one of them
		foreach ([0, 1, 2] as $i) {
			self::assertSame(1.0, $highs[$i] - $lows[$i]);
		}

		// the same numbers through the entity
		self::assertSame(4.5, Bar::of(0, 1, 104.5, 105.0, 104.0, 104.5)->trueRange(100.5));
		self::assertSame(1.0, Bar::of(0, 1, 104.5, 105.0, 104.0, 104.5)->trueRange(\NAN));
	}

	/** ATR in price units, and as a fraction of the price for position sizing. */
	public function testRelativeToExpressesTheRangeAsAFractionOfThePrice(): void
	{
		$metric = new Atr(14);
		self::assertNan($metric->relativeTo(100.0), 'undefined during warm-up');

		for ($i = 0; $i < 20; $i++) {
			$metric->updateBar(Bar::of($i * 1_000_000_000, $i * 1_000_000_000 + 999, 100.5, 101.0, 100.0, 100.5));
		}
		self::assertTrue($metric->isReady());
		self::assertSame(1.0, $metric->value());
		self::assertSame(0.01, $metric->relativeTo(100.0));
		self::assertNan($metric->relativeTo(0.0), 'a non-positive price has no scale');
	}

	public function testWarmUpAndReset(): void
	{
		$bars = self::bars(30, 5005);
		$metric = new Atr(14);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['trueRange']);

		for ($i = 0; $i < 30; $i++) {
			$metric->updateBar(Bar::of(
				$i * 1_000_000_000,
				$i * 1_000_000_000 + 999,
				$bars['closes'][$i],
				$bars['highs'][$i],
				$bars['lows'][$i],
				$bars['closes'][$i],
			));
			self::assertSame($i >= 13, $metric->isReady(), "bar $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['trueRange']);
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		$bars = self::bars(300, 2718);
		$kernel = Atr::compute($bars['highs'], $bars['lows'], $bars['closes'], 14);

		$metric = new Atr(14);
		for ($i = 0; $i < 300; $i++) {
			$metric->updateBar(Bar::of(
				$i * 1_000_000_000,
				$i * 1_000_000_000 + 999,
				$bars['closes'][$i],
				$bars['highs'][$i],
				$bars['lows'][$i],
				$bars['closes'][$i],
			));
			if (\is_nan($kernel[$i])) {
				self::assertNan($metric->value(), "bar $i");
			} else {
				self::assertSame($kernel[$i], $metric->value(), "bar $i");
			}
		}
	}

	public function testPeriodMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Atr(0);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		Atr::compute([1.0, 2.0], [1.0], [1.0, 2.0]);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new Atr(21);
		self::assertSame(['type' => 'atr', 'period' => 21], $metric->toArray());
		self::assertSame($metric->toArray(), Atr::fromArray($metric->toArray())->toArray());
	}
}
