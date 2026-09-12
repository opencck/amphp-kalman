<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Momentum\Cci;
use PHPUnit\Framework\TestCase;

/**
 * The commodity channel index against an independent naive implementation
 * using the MEAN ABSOLUTE deviation — the constant that carries the whole
 * ±100 calibration and the one the usual shortcut gets wrong.
 *
 * The second half of this file pins that shortcut quantitatively: dividing by
 * the standard deviation instead inflates the denominator by roughly 1/0.7979,
 * so every reading shrinks by about a quarter.
 */
final class CciTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive CCI straight from the definition: typical price, its simple
	 * average over the window, and the mean of the absolute deviations from
	 * that average.
	 *
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return list<float>
	 */
	private static function naiveCci(array $highs, array $lows, array $closes, int $period, float $constant): array
	{
		$typical = [];
		foreach ($closes as $i => $close) {
			$typical[] = ($highs[$i] + $lows[$i] + $close) / 3.0;
		}

		$out = [];
		foreach ($typical as $i => $tp) {
			if ($i + 1 < $period) {
				$out[] = \NAN;
				continue;
			}
			$window = \array_slice($typical, $i + 1 - $period, $period);

			$sum = 0.0;
			foreach ($window as $value) {
				$sum += $value;
			}
			$mean = $sum / $period;

			$absolute = 0.0;
			foreach ($window as $value) {
				$absolute += \abs($value - $mean);
			}
			$mad = $absolute / $period;

			$out[] = $mad > 0.0 ? ($tp - $mean) / ($constant * $mad) : 0.0;
		}
		return $out;
	}

	/**
	 * The same thing with the population standard deviation in the
	 * denominator — the shortcut the class docblock warns about.
	 *
	 * @param list<float> $typical
	 * @return list<float>
	 */
	private static function naiveCciWithStandardDeviation(array $typical, int $period, float $constant): array
	{
		$out = [];
		foreach ($typical as $i => $tp) {
			if ($i + 1 < $period) {
				$out[] = \NAN;
				continue;
			}
			$window = \array_slice($typical, $i + 1 - $period, $period);

			$sum = 0.0;
			foreach ($window as $value) {
				$sum += $value;
			}
			$mean = $sum / $period;

			$m2 = 0.0;
			foreach ($window as $value) {
				$d = $value - $mean;
				$m2 += $d * $d;
			}
			$sd = \sqrt($m2 / $period);

			$out[] = $sd > 0.0 ? ($tp - $mean) / ($constant * $sd) : 0.0;
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
			$price += (float) \mt_rand(-120, 120) / 100.0;
			$high = $price + (float) \mt_rand(1, 100) / 100.0;
			$low = $price - (float) \mt_rand(1, 100) / 100.0;
			$highs[] = $high;
			$lows[] = $low;
			$closes[] = $low + ($high - $low) * ((float) \mt_rand(0, 1000) / 1000.0);
		}
		return ['highs' => $highs, 'lows' => $lows, 'closes' => $closes];
	}

	/** Box-Muller on a seeded mt_rand, so the sample is reproducible. */
	private static function gaussian(): float
	{
		$max = \mt_getrandmax();
		$u1 = \mt_rand(1, $max) / $max;
		$u2 = \mt_rand(0, $max) / $max;
		return \sqrt(-2.0 * \log($u1)) * \cos(2.0 * \M_PI * $u2);
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

	/**
	 * Period 2 is left out on purpose: with a two-sample window the mean
	 * absolute deviation is exactly half the gap between the two values, the
	 * numerator is the same quantity with the opposite sign, and the index is
	 * ±1/0.015 whatever the input. Both this test's naive loop and the
	 * library's O(1) window then compute 200/3 by catastrophic cancellation
	 * and disagree in the eleventh digit — a property of the formula at N = 2,
	 * not of either implementation.
	 *
	 * @return iterable<string, array{int}>
	 */
	public function periods(): iterable
	{
		yield 'period 3' => [3];
		yield 'period 5' => [5];
		yield 'period 20' => [20];
		yield 'period 50' => [50];
	}

	/** @dataProvider periods */
	public function testMatchesANaiveImplementationUsingMeanAbsoluteDeviation(int $period): void
	{
		$bars = self::bars(500, 300 + $period);
		self::assertSeriesMatches(
			self::naiveCci($bars['highs'], $bars['lows'], $bars['closes'], $period, Cci::LAMBERT_CONSTANT),
			Cci::compute($bars['highs'], $bars['lows'], $bars['closes'], $period),
			"cci($period)",
		);
	}

	public function testLambertConstantIsTheDocumentedDefault(): void
	{
		self::assertSame(0.015, Cci::LAMBERT_CONSTANT);
		self::assertSame(0.015, (new Cci())->constant);
		self::assertSame(20, (new Cci())->period);
	}

	/** A different constant simply rescales the index. */
	public function testConstantScalesTheIndexInversely(): void
	{
		$bars = self::bars(200, 4711);
		$standard = Cci::compute($bars['highs'], $bars['lows'], $bars['closes'], 20, 0.015);
		$doubled = Cci::compute($bars['highs'], $bars['lows'], $bars['closes'], 20, 0.030);

		foreach ($standard as $i => $value) {
			if (\is_nan($value)) {
				self::assertNan($doubled[$i]);
				continue;
			}
			self::assertEqualsWithDelta($value / 2.0, $doubled[$i], self::TOLERANCE, "index $i");
		}
	}

	public function testTypicalPriceIsTheAverageOfHighLowAndClose(): void
	{
		self::assertSame([101.0], Cci::typicalPrices([102.0], [99.0], [102.0]));
		$bar = Bar::of(0, 1, 100.0, 102.0, 99.0, 102.0);
		self::assertSame(101.0, $bar->typicalPrice());
	}

	/**
	 * Substituting the standard deviation for the mean absolute deviation
	 * inflates the denominator by about 1/0.7979 ≈ 1.2533 on normal input, so
	 * the CCI computed with the standard deviation is smaller by that factor.
	 * The median over a long synthetic series pins it.
	 */
	public function testStandardDeviationShrinksTheIndexByAboutAQuarter(): void
	{
		\mt_srand(1618033);
		$period = 20;
		$typical = [];
		for ($i = 0; $i < 4000; $i++) {
			$typical[] = 100.0 + self::gaussian();
		}

		// highs == lows == closes ⇒ the typical price is exactly the input
		$withMad = Cci::compute($typical, $typical, $typical, $period);
		$withSd = self::naiveCciWithStandardDeviation($typical, $period, Cci::LAMBERT_CONSTANT);

		$ratios = [];
		foreach ($withMad as $i => $value) {
			if (\is_nan($value) || \is_nan($withSd[$i]) || \abs($withSd[$i]) < 1e-6) {
				continue;
			}
			$ratios[] = $value / $withSd[$i];
		}

		self::assertGreaterThan(3000, \count($ratios), 'not enough samples to take a median of');
		\sort($ratios);
		$median = $ratios[\intdiv(\count($ratios), 2)];

		self::assertEqualsWithDelta(1.0 / \sqrt(2.0 / \M_PI), $median, 0.1, 'median CCI(mad) / CCI(sd)');
		self::assertEqualsWithDelta(1.25, $median, 0.1, 'median CCI(mad) / CCI(sd)');
	}

	/** A window with no dispersion has no scale to measure a deviation in. */
	public function testFlatWindowIsZeroRatherThanADivisionByZero(): void
	{
		$flat = \array_fill(0, 30, 100.0);
		$cci = Cci::compute($flat, $flat, $flat, 20);

		self::assertFalse(\is_nan($cci[29]));
		self::assertSame(0.0, $cci[29]);
	}

	public function testWarmUpAndReset(): void
	{
		$metric = new Cci(20);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['cci']);

		$bars = self::bars(25, 606);
		for ($i = 0; $i < 25; $i++) {
			$metric->updateBar(Bar::of(
				$i * 1_000_000_000,
				$i * 1_000_000_000 + 999,
				$bars['closes'][$i],
				$bars['highs'][$i],
				$bars['lows'][$i],
				$bars['closes'][$i],
			));
			self::assertSame($i >= 19, $metric->isReady(), "bar $i");
		}
		self::assertSame(
			['cci', 'typical', 'mad'],
			\array_keys($metric->values()),
			'the output keys must not depend on readiness',
		);

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
	}

	public function testPeriodMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Cci(0);
	}

	public function testConstantMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Cci(20, 0.0);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		Cci::typicalPrices([1.0, 2.0], [1.0, 2.0], [1.0]);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new Cci(30, 0.02);
		self::assertSame(['type' => 'cci', 'period' => 30, 'constant' => 0.02], $metric->toArray());
		self::assertSame($metric->toArray(), Cci::fromArray($metric->toArray())->toArray());
	}
}
