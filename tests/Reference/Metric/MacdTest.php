<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Trend\Macd;
use OpenCCK\Kalman\Domain\Metric\Trend\MaType;
use PHPUnit\Framework\TestCase;

/**
 * MACD against naive exponential-average loops.
 *
 * All three outputs are checked, because all three carry a trading rule: the
 * zero crossing, the signal crossing and the histogram. The reference PromQL
 * expression collapses the algebra to the histogram alone and builds it from
 * flat rolling averages, so two of the three rules cannot be evaluated on it —
 * `referenceEquivalent()` exists to plot the two side by side, and is checked
 * here to be the flat-average configuration it claims to be.
 */
final class MacdTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * A deterministic price series.
	 *
	 * @return list<float>
	 */
	private static function series(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 100.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-180, 180) / 100.0;
			$out[] = $price;
		}
		return $out;
	}

	/**
	 * Naive EMA seeded with the simple average of the first `period` values.
	 *
	 * @param list<float> $values
	 * @return list<float>
	 */
	private static function naiveEma(array $values, int $period): array
	{
		$alpha = 2.0 / ($period + 1);
		$out = [];
		$value = 0.0;
		$seen = 0;
		$seed = 0.0;
		foreach ($values as $x) {
			$seen++;
			if ($seen < $period) {
				$seed += $x;
				$out[] = \NAN;
				continue;
			}
			if ($seen === $period) {
				$seed += $x;
				$value = $seed / $period;
				$out[] = $value;
				continue;
			}
			$value += $alpha * ($x - $value);
			$out[] = $value;
		}
		return $out;
	}

	/**
	 * The whole indicator, written from the definition: two EMAs of the price,
	 * their difference, and an EMA of that difference over the values where it
	 * is defined.
	 *
	 * @param list<float> $prices
	 * @return array{macd: list<float>, signal: list<float>, histogram: list<float>}
	 */
	private static function naiveMacd(array $prices, int $fast, int $slow, int $signal): array
	{
		$fastEma = self::naiveEma($prices, $fast);
		$slowEma = self::naiveEma($prices, $slow);

		$macd = [];
		$defined = [];
		foreach ($fastEma as $i => $fast) {
			$slow = $slowEma[$i];
			if (\is_nan($fast) || \is_nan($slow)) {
				$macd[] = \NAN;
				continue;
			}
			$line = $fast - $slow;
			$macd[] = $line;
			$defined[] = $line;
		}

		$signalOverDefined = self::naiveEma($defined, $signal);
		$signalLine = [];
		$histogram = [];
		$k = 0;
		foreach ($macd as $line) {
			if (\is_nan($line)) {
				$signalLine[] = \NAN;
				$histogram[] = \NAN;
				continue;
			}
			$value = $signalOverDefined[$k];
			$k++;
			$signalLine[] = $value;
			$histogram[] = \is_nan($value) ? \NAN : $line - $value;
		}

		return ['macd' => $macd, 'signal' => $signalLine, 'histogram' => $histogram];
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

	/** @return iterable<string, array{int, int, int}> */
	public function configurations(): iterable
	{
		yield 'classic 12/26/9' => [12, 26, 9];
		yield 'fast 5/13/4' => [5, 13, 4];
		yield 'slow 20/50/15' => [20, 50, 15];
		yield 'degenerate 1/2/1' => [1, 2, 1];
	}

	/** @dataProvider configurations */
	public function testMatchesNaiveExponentialAverages(int $fast, int $slow, int $signal): void
	{
		$prices = self::series(600, $fast * 1000 + $slow);
		$expected = self::naiveMacd($prices, $fast, $slow, $signal);
		$actual = Macd::compute($prices, $fast, $slow, $signal);

		self::assertSeriesMatches($expected['macd'], $actual['macd'], "macd($fast/$slow/$signal)");
		self::assertSeriesMatches($expected['signal'], $actual['signal'], "signal($fast/$slow/$signal)");
		self::assertSeriesMatches($expected['histogram'], $actual['histogram'], "histogram($fast/$slow/$signal)");
	}

	/** All three outputs are present, and the histogram is their difference. */
	public function testAllThreeOutputsArePresentAndConsistent(): void
	{
		$prices = self::series(300, 8080);
		$result = Macd::compute($prices, 12, 26, 9);

		self::assertSame(['macd', 'signal', 'histogram'], \array_keys($result));
		self::assertSame(300, \count($result['macd']));
		self::assertSame(300, \count($result['signal']));
		self::assertSame(300, \count($result['histogram']));

		// the MACD line appears with the slow average, the signal eight bars later
		for ($i = 0; $i < 25; $i++) {
			self::assertNan($result['macd'][$i], "macd index $i");
		}
		self::assertFalse(\is_nan($result['macd'][25]));
		for ($i = 0; $i < 33; $i++) {
			self::assertNan($result['signal'][$i], "signal index $i");
		}
		self::assertFalse(\is_nan($result['signal'][33]));

		for ($i = 33; $i < 300; $i++) {
			self::assertEqualsWithDelta(
				$result['macd'][$i] - $result['signal'][$i],
				$result['histogram'][$i],
				self::TOLERANCE,
				"histogram index $i",
			);
		}

		$metric = new Macd();
		self::assertSame(['macd', 'signal', 'histogram'], \array_keys($metric->values()));
	}

	/** A constant price has no convergence and no divergence: every output is 0. */
	public function testConstantSeriesGivesZero(): void
	{
		$prices = \array_fill(0, 120, 250.0);
		$result = Macd::compute($prices, 12, 26, 9);

		self::assertSame(0.0, $result['macd'][119]);
		self::assertSame(0.0, $result['signal'][119]);
		self::assertSame(0.0, $result['histogram'][119]);

		$metric = new Macd();
		foreach ($prices as $i => $price) {
			$metric->updatePrice($i * 1_000_000_000, $price);
		}
		self::assertTrue($metric->isReady());
		self::assertSame(['macd' => 0.0, 'signal' => 0.0, 'histogram' => 0.0], $metric->values());
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		$prices = self::series(400, 24601);
		$kernel = Macd::compute($prices, 12, 26, 9);

		$metric = new Macd(12, 26, 9);
		foreach ($prices as $i => $price) {
			$metric->updatePrice($i * 1_000_000_000, $price);
			$values = $metric->values();
			foreach (['macd', 'signal', 'histogram'] as $key) {
				if (\is_nan($kernel[$key][$i])) {
					self::assertNan($values[$key], "$key index $i");
				} else {
					self::assertSame($kernel[$key][$i], $values[$key], "$key index $i");
				}
			}
		}
	}

	/**
	 * The reference configuration: flat rolling averages over 8 and 17
	 * samples. Its windows are roughly two thirds of the standard ones — an
	 * SMA over M samples has the centre of mass of an EMA of period M — so its
	 * crossings happen at different times from the ones a trader sees.
	 */
	public function testReferenceEquivalentIsTheFlatAverageConfiguration(): void
	{
		$reference = Macd::referenceEquivalent();
		self::assertSame(8, $reference->fast);
		self::assertSame(17, $reference->slow);
		self::assertSame(9, $reference->signal);
		self::assertSame(MaType::Sma, $reference->type);

		$prices = self::series(400, 1717);
		$standard = new Macd();
		$flat = Macd::referenceEquivalent();
		$maxDifference = 0.0;
		foreach ($prices as $i => $price) {
			$ts = $i * 1_000_000_000;
			$standard->updatePrice($ts, $price);
			$flat->updatePrice($ts, $price);
			if ($standard->isReady() && $flat->isReady()) {
				$maxDifference = \max($maxDifference, \abs($standard->value() - $flat->value()));
			}
		}
		self::assertGreaterThan(0.1, $maxDifference, 'the reference expression is a different indicator');
	}

	/** On a regular grid the time-based form reduces to a fixed alpha. */
	public function testTimedFormMatchesANaiveExponentialDecay(): void
	{
		$prices = self::series(300, 999);
		$timestamps = [];
		$ts = 2_000_000_000;
		for ($i = 0, $n = \count($prices); $i < $n; $i++) {
			$timestamps[] = $ts;
			$ts += 1_000_000_000;
		}

		$result = Macd::computeTimed($timestamps, $prices, 12.0, 26.0, 9.0);
		self::assertSame(300, \count($result['macd']));

		$fast = 0.0;
		$slow = 0.0;
		$signal = 0.0;
		$fastElapsed = 0.0;
		$signalElapsed = 0.0;
		foreach ($prices as $i => $price) {
			if ($i === 0) {
				$fast = $price;
				$slow = $price;
				continue;
			}
			$fast += (1.0 - \exp(-1.0 / 12.0)) * ($price - $fast);
			$slow += (1.0 - \exp(-1.0 / 26.0)) * ($price - $slow);
			$fastElapsed += 1.0;
			if ($fastElapsed < 26.0) {
				continue;
			}
			$line = $fast - $slow;
			if ($signalElapsed === 0.0) {
				$signal = $line;
			} else {
				$signal += (1.0 - \exp(-1.0 / 9.0)) * ($line - $signal);
			}
			$signalElapsed += 1.0;
			if ($signalElapsed - 1.0 < 9.0) {
				continue;
			}
			self::assertEqualsWithDelta($line, $result['macd'][$i], self::TOLERANCE, "timed macd index $i");
			self::assertEqualsWithDelta($signal, $result['signal'][$i], self::TOLERANCE, "timed signal index $i");
		}
	}

	public function testFastPeriodMustBeShorterThanTheSlowOne(): void
	{
		$this->expectException(InvalidArgument::class);
		new Macd(26, 12);
	}

	public function testPeriodsMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Macd(12, 26, 0);
	}

	public function testTimedFormNeedsOneTimestampPerPrice(): void
	{
		$this->expectException(InvalidArgument::class);
		Macd::computeTimed([1, 2], [1.0], 12.0, 26.0, 9.0);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = Macd::referenceEquivalent();
		self::assertSame(
			['type' => 'macd', 'fast' => 8, 'slow' => 17, 'signal' => 9, 'ma' => 'sma'],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), Macd::fromArray($metric->toArray())->toArray());
	}
}
