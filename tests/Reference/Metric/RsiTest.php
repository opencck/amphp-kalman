<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Momentum\Rsi;
use OpenCCK\Kalman\Domain\Metric\Trend\MaType;
use PHPUnit\Framework\TestCase;

/**
 * Wilder's RSI against an independent naive implementation, plus the two hand
 * values from the textbook series, plus the degenerate windows that a naive
 * `100 − 100/(1+RS)` divides by zero on.
 *
 * The last test pins the documented discrepancy with the reference PromQL
 * formula: lagged differences under a flat rolling average are not the RSI,
 * and the 70/30 thresholds do not transfer to them.
 */
final class RsiTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * The series every RSI worked example is built on. Index 14 is the first
	 * value, and its average gain and loss are 3.34/14 and 1.40/14.
	 *
	 * @return list<float>
	 */
	private static function textbookCloses(): array
	{
		return [
			44.34, 44.09, 44.15, 43.61, 44.33, 44.83, 45.10, 45.42,
			45.84, 46.08, 45.89, 46.03, 45.61, 46.28, 46.28, 46.00,
		];
	}

	/**
	 * Naive Wilder RSI, written straight from the definition: consecutive
	 * differences, a seed of the simple average of the first N gains and
	 * losses, then Ū ← Ū + (U − Ū)/N.
	 *
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveWilder(array $prices, int $period): array
	{
		$out = [];
		$previous = \NAN;
		$seedGain = 0.0;
		$seedLoss = 0.0;
		$avgGain = 0.0;
		$avgLoss = 0.0;
		$diffs = 0;

		foreach ($prices as $price) {
			if (\is_nan($previous)) {
				$previous = $price;
				$out[] = \NAN;
				continue;
			}
			$change = $price - $previous;
			$previous = $price;
			$gain = $change > 0.0 ? $change : 0.0;
			$loss = $change < 0.0 ? -$change : 0.0;
			$diffs++;

			if ($diffs < $period) {
				$seedGain += $gain;
				$seedLoss += $loss;
				$out[] = \NAN;
				continue;
			}
			if ($diffs === $period) {
				$seedGain += $gain;
				$seedLoss += $loss;
				$avgGain = $seedGain / $period;
				$avgLoss = $seedLoss / $period;
			} else {
				$avgGain += ($gain - $avgGain) / $period;
				$avgLoss += ($loss - $avgLoss) / $period;
			}
			$total = $avgGain + $avgLoss;
			$out[] = $total > 0.0 ? 100.0 * $avgGain / $total : 50.0;
		}
		return $out;
	}

	/**
	 * Naive reference variant: differences against the observation `lagSteps`
	 * back, smoothed by a plain rolling average over `window` of them.
	 *
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveLagged(array $prices, int $lagSteps, int $window): array
	{
		$out = [];
		$gains = [];
		$losses = [];
		$history = [];
		foreach ($prices as $price) {
			$history[] = $price;
			if (\count($history) <= $lagSteps) {
				$out[] = \NAN;
				continue;
			}
			// the queue holds lagSteps+1 prices, so its head is the one the
			// reference compares against
			$change = $price - \array_shift($history);
			$gains[] = $change > 0.0 ? $change : 0.0;
			$losses[] = $change < 0.0 ? -$change : 0.0;
			if (\count($gains) > $window) {
				\array_shift($gains);
				\array_shift($losses);
			}
			if (\count($gains) < $window) {
				$out[] = \NAN;
				continue;
			}
			$avgGain = \array_sum($gains) / $window;
			$avgLoss = \array_sum($losses) / $window;
			$total = $avgGain + $avgLoss;
			$out[] = $total > 0.0 ? 100.0 * $avgGain / $total : 50.0;
		}
		return $out;
	}

	/**
	 * A deterministic price series.
	 *
	 * @return list<float>
	 */
	private static function series(int $n, int $seed, float $drift = 0.0): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 100.0;
		for ($i = 0; $i < $n; $i++) {
			$price += $drift + (float) \mt_rand(-200, 200) / 100.0;
			$out[] = $price;
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
		yield 'period 2' => [2];
		yield 'period 5' => [5];
		yield 'period 14' => [14];
		yield 'period 21' => [21];
	}

	/** @dataProvider periods */
	public function testWilderMatchesANaiveImplementation(int $period): void
	{
		$prices = self::series(500, 100 + $period);
		self::assertSeriesMatches(self::naiveWilder($prices, $period), Rsi::wilder($prices, $period), "wilder($period)");
	}

	/** @dataProvider periods */
	public function testLaggedMatchesANaiveImplementation(int $period): void
	{
		$prices = self::series(500, 200 + $period);
		foreach ([1, 5, 30] as $lag) {
			self::assertSeriesMatches(
				self::naiveLagged($prices, $lag, $period),
				Rsi::lagged($prices, $lag, $period),
				"lagged(lag=$lag, window=$period)",
			);
		}
	}

	/**
	 * The two hand-derived values on the textbook closes: at index 14 the
	 * average gain is 3.34/14 = 0.2385714 and the average loss 1.40/14 = 0.1,
	 * so the index is 100·0.2385714/0.3385714 = 70.4641. Index 15 applies one
	 * Wilder step with a 0.28 loss.
	 */
	public function testPinnedTextbookValues(): void
	{
		$rsi = Rsi::wilder(self::textbookCloses(), 14);

		self::assertEqualsWithDelta(70.4641, $rsi[14], 1e-4, 'index 14');
		self::assertEqualsWithDelta(66.2496, $rsi[15], 1e-4, 'index 15');
	}

	/** The same two values, read off the streaming object rather than the kernel. */
	public function testPinnedAverageGainAndLoss(): void
	{
		$metric = new Rsi(14);
		$closes = self::textbookCloses();
		for ($i = 0; $i <= 14; $i++) {
			$metric->updatePrice($i * 1_000_000_000, $closes[$i]);
		}

		self::assertTrue($metric->isReady());
		$values = $metric->values();
		self::assertEqualsWithDelta(0.2385714, $values['avgGain'], 1e-7, 'avgGain');
		self::assertEqualsWithDelta(0.1, $values['avgLoss'], 1e-9, 'avgLoss');
		self::assertEqualsWithDelta(70.4641, $values['rsi'], 1e-4, 'rsi');

		$metric->updatePrice(15 * 1_000_000_000, $closes[15]);
		self::assertEqualsWithDelta(66.2496, $metric->value(), 1e-4, 'rsi one step later');
	}

	public function testWarmUpIsNanAndValueIsUndefinedUntilReady(): void
	{
		$rsi = Rsi::wilder(self::textbookCloses(), 14);
		for ($i = 0; $i < 14; $i++) {
			self::assertNan($rsi[$i], "index $i");
		}
		self::assertFalse(\is_nan($rsi[14]));

		$metric = new Rsi(14);
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['avgGain']);
	}

	/**
	 * The degenerate windows, resolved explicitly rather than by dividing by
	 * zero: no losses is 100, no gains is 0, a flat series is 50 — never NAN.
	 */
	public function testMonotoneAndFlatSeriesAreResolvedExplicitly(): void
	{
		$rising = [];
		$falling = [];
		$flat = [];
		for ($i = 0; $i < 40; $i++) {
			$rising[] = 100.0 + (float) $i;
			$falling[] = 200.0 - (float) $i;
			$flat[] = 150.0;
		}

		self::assertSame(100.0, Rsi::wilder($rising, 14)[39], 'a monotone rise is 100');
		self::assertSame(0.0, Rsi::wilder($falling, 14)[39], 'a monotone fall is 0');
		$flatRsi = Rsi::wilder($flat, 14)[39];
		self::assertFalse(\is_nan($flatRsi), 'a flat series must not be NAN');
		self::assertSame(50.0, $flatRsi, 'a flat series is 50');

		// and the reference variant resolves them the same way
		self::assertSame(100.0, Rsi::lagged($rising, 1, 14)[39]);
		self::assertSame(0.0, Rsi::lagged($falling, 1, 14)[39]);
		self::assertSame(50.0, Rsi::lagged($flat, 1, 14)[39]);
	}

	/**
	 * The documented discrepancy. Under a trend the canonical index saturates
	 * because every close is measured against its neighbour, while the lagged
	 * reference measures it against a distant past — so the two are different
	 * numbers, not two estimates of the same one.
	 */
	public function testLaggedIsMateriallyDifferentFromWilder(): void
	{
		$prices = self::series(600, 4242, 0.35);

		$wilder = Rsi::wilder($prices, 14);
		$lagged = Rsi::lagged($prices, 30, 14);

		$maxDifference = 0.0;
		$compared = 0;
		foreach ($wilder as $i => $canonical) {
			if (\is_nan($canonical) || \is_nan($lagged[$i])) {
				continue;
			}
			$compared++;
			$difference = \abs($canonical - $lagged[$i]);
			if ($difference > $maxDifference) {
				$maxDifference = $difference;
			}
		}

		self::assertGreaterThan(400, $compared, 'the two series must overlap for the comparison to mean anything');
		self::assertGreaterThan(
			5.0,
			$maxDifference,
			'the reference PromQL variant must not be mistaken for the RSI',
		);
	}

	/**
	 * Smoothing alone moves the answer: Wilder's RMA has a centre of mass of
	 * N−1 and a long tail, a flat rolling average cuts it off at N.
	 */
	public function testSmoothingAloneChangesTheIndex(): void
	{
		$prices = self::series(400, 99);
		$wilder = Rsi::compute($prices, 14, 1, MaType::Rma->value);
		$flat = Rsi::compute($prices, 14, 1, MaType::Sma->value);

		$maxDifference = 0.0;
		foreach ($wilder as $i => $value) {
			if (\is_nan($value) || \is_nan($flat[$i])) {
				continue;
			}
			$maxDifference = \max($maxDifference, \abs($value - $flat[$i]));
		}
		self::assertGreaterThan(1.0, $maxDifference);
	}

	/**
	 * The time-based form must span τ seconds of market time: on a regular
	 * grid it reduces to a fixed alpha, which is what is checked here.
	 */
	public function testWilderTimedMatchesANaiveExponentialDecay(): void
	{
		$prices = self::series(300, 31415);
		$timestamps = [];
		$ts = 5_000_000_000;
		for ($i = 0, $n = \count($prices); $i < $n; $i++) {
			$timestamps[] = $ts;
			$ts += 500_000_000; // 0.5 s
		}
		$tau = 7.0;
		$alpha = 1.0 - \exp(-0.5 / $tau);

		$expected = [];
		$avgGain = 0.0;
		$avgLoss = 0.0;
		$elapsed = 0.0;
		$previous = \NAN;
		foreach ($prices as $price) {
			if (\is_nan($previous)) {
				$previous = $price;
				$expected[] = \NAN;
				continue;
			}
			$change = $price - $previous;
			$previous = $price;
			$avgGain += $alpha * (($change > 0.0 ? $change : 0.0) - $avgGain);
			$avgLoss += $alpha * (($change < 0.0 ? -$change : 0.0) - $avgLoss);
			$elapsed += 0.5;
			if ($elapsed < $tau) {
				$expected[] = \NAN;
				continue;
			}
			$total = $avgGain + $avgLoss;
			$expected[] = $total > 0.0 ? 100.0 * $avgGain / $total : 50.0;
		}

		self::assertSeriesMatches($expected, Rsi::wilderTimed($timestamps, $prices, $tau), 'wilderTimed');
	}

	public function testInvalidConfigurations(): void
	{
		$this->expectException(InvalidArgument::class);
		new Rsi(0);
	}

	public function testLagMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Rsi(14, 0);
	}

	public function testSmoothingIsRestrictedToWilderAndTheReferenceVariant(): void
	{
		$this->expectException(InvalidArgument::class);
		new Rsi(14, 1, MaType::Wma);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = Rsi::reference(14, 30);
		self::assertSame(
			['type' => 'rsi', 'period' => 14, 'lagSteps' => 30, 'smoothing' => 'sma'],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), Rsi::fromArray($metric->toArray())->toArray());
	}
}
