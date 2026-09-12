<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Trend\MaType;
use OpenCCK\Kalman\Domain\Metric\Trend\MovingAverage;
use PHPUnit\Framework\TestCase;

/**
 * BRIEF §2 p.8: every numerical kernel is checked against an independent naive
 * textbook implementation written here, to 1e-9.
 *
 * The seeding is part of the definition and is pinned separately: the
 * recursive averages start from the simple average of the first `period`
 * observations, as Wilder defined them and as TA-Lib and TradingView
 * implement them. Seeding with the first observation instead leaves a
 * transient that makes reference values impossible to reproduce.
 */
final class MovingAverageTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * A deterministic price-like series.
	 *
	 * @return list<float>
	 */
	private static function series(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 100.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-200, 200) / 100.0;
			$out[] = $price;
		}
		return $out;
	}

	/**
	 * Naive SMA: the plain average of the trailing window, NAN until full.
	 *
	 * @param list<float> $values
	 * @return list<float>
	 */
	private static function naiveSma(array $values, int $period): array
	{
		$out = [];
		$n = \count($values);
		for ($i = 0; $i < $n; $i++) {
			if ($i + 1 < $period) {
				$out[] = \NAN;
				continue;
			}
			$sum = 0.0;
			foreach (\array_slice($values, $i + 1 - $period, $period) as $value) {
				$sum += $value;
			}
			$out[] = $sum / $period;
		}
		return $out;
	}

	/**
	 * Naive recursive average with an explicit alpha, seeded with the simple
	 * average of the first `period` values.
	 *
	 * @param list<float> $values
	 * @return list<float>
	 */
	private static function naiveRecursive(array $values, int $period, float $alpha): array
	{
		$out = [];
		$value = 0.0;
		foreach ($values as $i => $x) {
			if ($i + 1 < $period) {
				$out[] = \NAN;
				continue;
			}
			if ($i + 1 === $period) {
				$sum = 0.0;
				for ($j = 0; $j < $period; $j++) {
					$sum += $values[$j];
				}
				$value = $sum / $period;
				$out[] = $value;
				continue;
			}
			$value = $value + $alpha * ($x - $value);
			$out[] = $value;
		}
		return $out;
	}

	/**
	 * Naive WMA: weight 1 on the oldest value of the window, `period` on the
	 * newest, normalised by the triangular number.
	 *
	 * @param list<float> $values
	 * @return list<float>
	 */
	private static function naiveWma(array $values, int $period): array
	{
		$denominator = (float) ($period * ($period + 1)) / 2.0;
		$out = [];
		$n = \count($values);
		for ($i = 0; $i < $n; $i++) {
			if ($i + 1 < $period) {
				$out[] = \NAN;
				continue;
			}
			$sum = 0.0;
			$weight = 0.0;
			foreach (\array_slice($values, $i + 1 - $period, $period) as $value) {
				$weight += 1.0;
				$sum += $value * $weight;
			}
			$out[] = $sum / $denominator;
		}
		return $out;
	}

	/**
	 * @param list<float> $expected
	 * @param list<float> $actual
	 */
	private static function assertSeriesMatches(array $expected, array $actual, string $label): void
	{
		self::assertSame(\count($expected), \count($actual), "$label: length");
		foreach ($expected as $i => $want) {
			$got = $actual[$i];
			if (\is_nan($want)) {
				self::assertNan($got, "$label: index $i expected NAN");
				continue;
			}
			self::assertFalse(\is_nan($got), "$label: index $i unexpectedly NAN");
			self::assertEqualsWithDelta($want, $got, self::TOLERANCE, "$label: index $i");
		}
	}

	/** @return iterable<string, array{int}> */
	public function periods(): iterable
	{
		yield 'period 1' => [1];
		yield 'period 2' => [2];
		yield 'period 3' => [3];
		yield 'period 9' => [9];
		yield 'period 14' => [14];
		yield 'period 26' => [26];
	}

	/** @dataProvider periods */
	public function testSmaMatchesANaiveLoop(int $period): void
	{
		$values = self::series(400, 11 + $period);
		self::assertSeriesMatches(self::naiveSma($values, $period), MovingAverage::sma($values, $period), "sma($period)");
	}

	/** @dataProvider periods */
	public function testEmaMatchesANaiveLoop(int $period): void
	{
		$values = self::series(400, 22 + $period);
		$alpha = 2.0 / ($period + 1);
		self::assertSeriesMatches(
			self::naiveRecursive($values, $period, $alpha),
			MovingAverage::ema($values, $period),
			"ema($period)",
		);
	}

	/** @dataProvider periods */
	public function testRmaMatchesANaiveLoop(int $period): void
	{
		$values = self::series(400, 33 + $period);
		$alpha = 1.0 / $period;
		self::assertSeriesMatches(
			self::naiveRecursive($values, $period, $alpha),
			MovingAverage::rma($values, $period),
			"rma($period)",
		);
	}

	/** @dataProvider periods */
	public function testWmaMatchesANaiveLoop(int $period): void
	{
		$values = self::series(400, 44 + $period);
		self::assertSeriesMatches(self::naiveWma($values, $period), MovingAverage::wma($values, $period), "wma($period)");
	}

	/**
	 * Wilder's smoothing is an EMA of period 2N−1: α = 1/N on one side,
	 * 2/((2N−1)+1) = 1/N on the other. The two differ only in the seed, so
	 * running the EMA recursion at Wilder's alpha from Wilder's seed must
	 * reproduce `rma()` exactly.
	 *
	 * @dataProvider periods
	 */
	public function testRmaHasTheAlphaOfAnEmaOfPeriodTwoNMinusOne(int $period): void
	{
		self::assertSame(MaType::Rma->alpha($period), MaType::Ema->alpha(2 * $period - 1));
		self::assertSame(1.0 / $period, MaType::Rma->alpha($period));

		$values = self::series(400, 55 + $period);
		self::assertSeriesMatches(
			self::naiveRecursive($values, $period, MaType::Ema->alpha(2 * $period - 1)),
			MovingAverage::rma($values, $period),
			"rma($period) as ema(" . (2 * $period - 1) . ')',
		);
	}

	/**
	 * The centre of mass is the honest way to compare the families, and it is
	 * what makes 2N−1 the right translation.
	 */
	public function testCentresOfMassAgreeWithTheDocumentedTable(): void
	{
		self::assertSame(9.5, MaType::Sma->centreOfMass(20));
		self::assertSame(9.5, MaType::Ema->centreOfMass(20));
		self::assertSame(19.0, MaType::Rma->centreOfMass(20));
		self::assertEqualsWithDelta(19.0 / 3.0, MaType::Wma->centreOfMass(20), self::TOLERANCE);
		// an RMA of N has the centre of mass of an EMA of 2N−1
		self::assertSame(MaType::Rma->centreOfMass(14), MaType::Ema->centreOfMass(27));
		self::assertNan(MaType::Sma->alpha(10));
		self::assertNan(MaType::Wma->alpha(10));
	}

	/**
	 * The recursive averages are seeded with the SMA of the first `period`
	 * values: their first non-NAN output sits at index period−1 and equals it
	 * exactly.
	 *
	 * @dataProvider periods
	 */
	public function testRecursiveAveragesAreSeededWithTheSimpleAverage(int $period): void
	{
		$values = self::series(400, 66 + $period);
		$seed = 0.0;
		for ($i = 0; $i < $period; $i++) {
			$seed += $values[$i];
		}
		$seed /= $period;

		foreach (['ema' => MovingAverage::ema($values, $period), 'rma' => MovingAverage::rma($values, $period)] as $name => $out) {
			for ($i = 0; $i < $period - 1; $i++) {
				self::assertNan($out[$i], "$name: index $i must be NAN during warm-up");
			}
			self::assertEqualsWithDelta($seed, $out[$period - 1], self::TOLERANCE, "$name: seed");
			// and the SMA agrees with it at that one index
			self::assertEqualsWithDelta(
				$out[$period - 1],
				MovingAverage::sma($values, $period)[$period - 1],
				self::TOLERANCE,
				"$name: seed equals the SMA",
			);
		}
	}

	/** Every average of a constant series is that constant. */
	public function testConstantSeriesIsAFixedPointOfEveryAverage(): void
	{
		$values = \array_fill(0, 60, 42.5);
		foreach ([
			'sma' => MovingAverage::sma($values, 14),
			'ema' => MovingAverage::ema($values, 14),
			'rma' => MovingAverage::rma($values, 14),
			'wma' => MovingAverage::wma($values, 14),
		] as $name => $out) {
			self::assertEqualsWithDelta(42.5, $out[59], self::TOLERANCE, $name);
		}
	}

	/**
	 * The time-based form: α = 1 − exp(−dt/τ), which on a regular grid is a
	 * constant alpha, and NAN until τ seconds of stream have elapsed.
	 */
	public function testTimedEmaMatchesANaiveExponentialDecay(): void
	{
		$values = self::series(200, 777);
		$timestamps = [];
		$ts = 1_000_000_000;
		for ($i = 0, $n = \count($values); $i < $n; $i++) {
			$timestamps[] = $ts;
			$ts += 250_000_000; // 0.25 s
		}
		$tau = 5.0;

		$expected = [];
		$value = 0.0;
		$elapsed = 0.0;
		foreach ($values as $i => $x) {
			if ($i === 0) {
				$value = $x;
			} else {
				$dt = 0.25;
				$elapsed += $dt;
				$value += (1.0 - \exp(-$dt / $tau)) * ($x - $value);
			}
			$expected[] = $elapsed >= $tau ? $value : \NAN;
		}

		self::assertSeriesMatches($expected, MovingAverage::emaTimed($timestamps, $values, $tau), 'emaTimed');
	}

	public function testEmptyInputProducesEmptyOutput(): void
	{
		self::assertSame([], MovingAverage::sma([], 3));
		self::assertSame([], MovingAverage::ema([], 3));
		self::assertSame([], MovingAverage::rma([], 3));
		self::assertSame([], MovingAverage::wma([], 3));
	}

	public function testPeriodMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new MovingAverage(0);
	}

	public function testTimedFormIsOnlyDefinedForTheExponentialAverage(): void
	{
		$this->expectException(InvalidArgument::class);
		new MovingAverage(10, MaType::Sma, 5.0);
	}

	public function testTimedEmaNeedsOneTimestampPerValue(): void
	{
		$this->expectException(InvalidArgument::class);
		MovingAverage::emaTimed([1, 2], [1.0], 5.0);
	}

	public function testConfigurationRoundTrips(): void
	{
		$ma = new MovingAverage(21, MaType::Wma);
		self::assertSame(['type' => 'moving-average', 'period' => 21, 'ma' => 'wma', 'tau' => 0.0], $ma->toArray());
		self::assertSame($ma->toArray(), MovingAverage::fromArray($ma->toArray())->toArray());
	}
}
