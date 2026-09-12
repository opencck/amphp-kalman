<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Volatility\OhlcVolatility;
use OpenCCK\Kalman\Domain\Metric\Volatility\VolatilityEstimator;
use PHPUnit\Framework\TestCase;

/**
 * The five OHLC volatility estimators (M-22) against naive implementations
 * written straight from their published formulas.
 *
 * Beyond the arithmetic, each estimator is checked for the property it is
 * chosen for: Rogers–Satchell and Yang–Zhang are unaffected by a drift, and
 * Yang–Zhang and close-to-close are the only two that see the gap between one
 * bar's close and the next bar's open. Those two claims are what
 * `isDriftIndependent()` and `handlesGaps()` advertise, so they are measured
 * here rather than taken on trust.
 */
final class OhlcVolatilityTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Per-bar estimator terms, each from its published formula.
	 *
	 * @param list<float> $o
	 * @param list<float> $h
	 * @param list<float> $l
	 * @param list<float> $c
	 * @return array{parkinson: list<float>, gk: list<float>, rs: list<float>, overnight: list<float>, openClose: list<float>, closeReturn: list<float>}
	 */
	private static function naiveTerms(array $o, array $h, array $l, array $c): array
	{
		$parkinson = [];
		$gk = [];
		$rs = [];
		$overnight = [];
		$openClose = [];
		$closeReturn = [];

		foreach ($c as $i => $close) {
			$logHl = \log($h[$i] / $l[$i]);
			$logCo = \log($close / $o[$i]);

			$parkinson[] = $logHl ** 2 / (4.0 * \M_LN2);
			$gk[] = 0.5 * $logHl ** 2 - (2.0 * \M_LN2 - 1.0) * $logCo ** 2;
			$rs[] = \log($h[$i] / $close) * \log($h[$i] / $o[$i]) + \log($l[$i] / $close) * \log($l[$i] / $o[$i]);

			$overnight[] = $i === 0 ? 0.0 : \log($o[$i] / $c[$i - 1]);
			$openClose[] = $logCo;
			if ($i > 0) {
				$closeReturn[] = \log($close / $c[$i - 1]);
			}
		}

		return [
			'parkinson' => $parkinson,
			'gk' => $gk,
			'rs' => $rs,
			'overnight' => $overnight,
			'openClose' => $openClose,
			'closeReturn' => $closeReturn,
		];
	}

	/**
	 * Unbiased (N−1) variance of a slice, summed from scratch.
	 *
	 * @param list<float> $values
	 */
	private static function naiveVariance(array $values): float
	{
		$n = \count($values);
		if ($n < 2) {
			return \NAN;
		}
		$mean = \array_sum($values) / $n;
		$sq = 0.0;
		foreach ($values as $v) {
			$sq += ($v - $mean) ** 2;
		}
		return $sq / ($n - 1);
	}

	/**
	 * @param list<float> $o
	 * @param list<float> $h
	 * @param list<float> $l
	 * @param list<float> $c
	 * @return list<float> per-bar standard deviation, aligned with the input
	 */
	private static function naive(array $o, array $h, array $l, array $c, int $window, VolatilityEstimator $estimator): array
	{
		$t = self::naiveTerms($o, $h, $l, $c);
		$out = [];

		// Yang–Zhang's k, from the published formula
		$k = 0.34 / (1.34 + (float) ($window + 1) / ($window - 1));

		foreach ($c as $i => $_) {
			$bars = $i + 1;

			if ($estimator === VolatilityEstimator::CloseToClose) {
				// the first bar contributes no close-to-close return
				$available = $i;
				if ($available < $window) {
					$out[] = \NAN;
					continue;
				}
				$slice = \array_slice($t['closeReturn'], $available - $window, $window);
				$out[] = \sqrt(self::naiveVariance($slice));
				continue;
			}

			if ($bars < $window) {
				$out[] = \NAN;
				continue;
			}

			$mean = static fn (array $series): float
				=> \array_sum(\array_slice($series, $bars - $window, $window)) / $window;

			$variance = match ($estimator) {
				VolatilityEstimator::Parkinson => \max($mean($t['parkinson']), 0.0),
				VolatilityEstimator::GarmanKlass => \max($mean($t['gk']), 0.0),
				VolatilityEstimator::RogersSatchell => \max($mean($t['rs']), 0.0),
				// Yang–Zhang is all that is left: close-to-close returned above
				// and the other three are handled by the arms before this one
				default => \max(
					self::naiveVariance(\array_slice($t['overnight'], $bars - $window, $window))
					+ $k * self::naiveVariance(\array_slice($t['openClose'], $bars - $window, $window))
					+ (1.0 - $k) * $mean($t['rs']),
					0.0,
				),
			};

			$out[] = \sqrt($variance);
		}
		return $out;
	}

	/**
	 * Deterministic bars with genuine overnight gaps, so the estimators that
	 * handle them differ from the ones that do not.
	 *
	 * @return array{list<float>, list<float>, list<float>, list<float>}
	 */
	private static function bars(int $n, int $seed): array
	{
		\mt_srand($seed);
		$o = [];
		$h = [];
		$l = [];
		$c = [];
		$close = 100.0;
		for ($i = 0; $i < $n; $i++) {
			$open = $close * (1.0 + (float) \mt_rand(-200, 200) / 10000.0);
			$closeNext = $open * (1.0 + (float) \mt_rand(-250, 250) / 10000.0);
			$high = \max($open, $closeNext) * (1.0 + (float) \mt_rand(1, 150) / 10000.0);
			$low = \min($open, $closeNext) * (1.0 - (float) \mt_rand(1, 150) / 10000.0);
			$o[] = $open;
			$h[] = $high;
			$l[] = $low;
			$c[] = $closeNext;
			$close = $closeNext;
		}
		return [$o, $h, $l, $c];
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

	/** @return iterable<string, array{string, int}> */
	public function estimators(): iterable
	{
		foreach (['close-to-close', 'parkinson', 'garman-klass', 'rogers-satchell', 'yang-zhang'] as $estimator) {
			foreach ([2, 5, 20] as $window) {
				yield "$estimator, window $window" => [$estimator, $window];
			}
		}
	}

	/** @dataProvider estimators */
	public function testMatchesTheNaiveFormula(string $estimator, int $window): void
	{
		[$o, $h, $l, $c] = self::bars(400, 900 + $window);
		self::assertSeriesMatches(
			self::naive($o, $h, $l, $c, $window, VolatilityEstimator::from($estimator)),
			OhlcVolatility::compute($o, $h, $l, $c, $window, $estimator),
			"$estimator($window)",
		);
	}

	/** The per-estimator kernels agree with the generic one. */
	public function testTheDedicatedKernelsAgreeWithTheGenericOne(): void
	{
		[$o, $h, $l, $c] = self::bars(300, 7373);

		self::assertSeriesMatches(
			OhlcVolatility::compute($h, $h, $l, $l, 20, 'parkinson'),
			OhlcVolatility::parkinson($h, $l, 20),
			'parkinson',
		);
		self::assertSeriesMatches(
			OhlcVolatility::compute($o, $h, $l, $c, 20, 'garman-klass'),
			OhlcVolatility::garmanKlass($o, $h, $l, $c, 20),
			'garmanKlass',
		);
		self::assertSeriesMatches(
			OhlcVolatility::compute($o, $h, $l, $c, 20, 'rogers-satchell'),
			OhlcVolatility::rogersSatchell($o, $h, $l, $c, 20),
			'rogersSatchell',
		);
		self::assertSeriesMatches(
			OhlcVolatility::compute($o, $h, $l, $c, 20, 'yang-zhang'),
			OhlcVolatility::yangZhang($o, $h, $l, $c, 20),
			'yangZhang',
		);
		self::assertSeriesMatches(
			OhlcVolatility::compute($c, $c, $c, $c, 20, 'close-to-close'),
			OhlcVolatility::closeToClose($c, 20),
			'closeToClose',
		);
	}

	/**
	 * Values derived by hand from the formulas, on bars chosen so the logs come
	 * out as whole numbers.
	 *
	 * Parkinson on H/L = e: the term is (ln e)² / (4 ln 2) = 1/(4 ln 2), so
	 * σ = 1 / (2·√ln2).
	 *
	 * Garman–Klass with C = O: the second term vanishes and the first is
	 * ½·(ln e)² = ½, so σ = √½.
	 *
	 * Rogers–Satchell with O = C = 1, H = e, L = 1/e: the term is
	 * ln(e)·ln(e) + ln(1/e)·ln(1/e) = 1 + 1 = 2, so σ = √2.
	 */
	public function testPinnedValuesFromTheFormulas(): void
	{
		$n = 30;

		$highs = \array_fill(0, $n, \M_E);
		$lows = \array_fill(0, $n, 1.0);
		self::assertEqualsWithDelta(
			1.0 / (2.0 * \sqrt(\M_LN2)),
			OhlcVolatility::parkinson($highs, $lows, 20)[$n - 1],
			self::TOLERANCE,
		);

		// Garman–Klass: open = close, so only the high/low term survives
		$opens = \array_fill(0, $n, 1.0);
		$closes = \array_fill(0, $n, 1.0);
		self::assertEqualsWithDelta(
			\sqrt(0.5),
			OhlcVolatility::garmanKlass($opens, $highs, $lows, $closes, 20)[$n - 1],
			self::TOLERANCE,
		);

		// Rogers–Satchell: symmetric bar around an unchanged open and close
		$rsHighs = \array_fill(0, $n, \M_E);
		$rsLows = \array_fill(0, $n, 1.0 / \M_E);
		self::assertEqualsWithDelta(
			\sqrt(2.0),
			OhlcVolatility::rogersSatchell($opens, $rsHighs, $rsLows, $closes, 20)[$n - 1],
			self::TOLERANCE,
		);
	}

	/**
	 * Every estimator reads a ratio inside the bar, so multiplying a whole bar
	 * by any positive factor leaves it alone — the readings are in log space
	 * and carry no price units.
	 */
	public function testEstimatorsAreInvariantUnderScalingEveryBar(): void
	{
		[$o, $h, $l, $c] = self::bars(300, 5959);
		/** @param list<float> $x @return list<float> */
		$scale = static fn (array $x): array => \array_values(\array_map(static fn (float $v): float => 37.0 * $v, $x));

		foreach (['close-to-close', 'parkinson', 'garman-klass', 'rogers-satchell', 'yang-zhang'] as $estimator) {
			self::assertSeriesMatches(
				OhlcVolatility::compute($o, $h, $l, $c, 20, $estimator),
				OhlcVolatility::compute($scale($o), $scale($h), $scale($l), $scale($c), 20, $estimator),
				"$estimator under scaling",
			);
		}
	}

	/**
	 * The drift-independence claim, in the one case where it is exact.
	 *
	 * Take a market with a pure drift and no noise whatever: inside each bar
	 * the price climbs monotonically from the open to the close, so the high is
	 * the close and the low is the open, and each bar opens where the last one
	 * closed. The true volatility is zero.
	 *
	 * Rogers–Satchell reports exactly that, because its term collapses:
	 * ln(H/C)·ln(H/O) + ln(L/C)·ln(L/O) = 0·ln(C/O) + ln(O/C)·0 = 0. Yang–Zhang
	 * and close-to-close likewise report zero, the first because all three of
	 * its components vanish and the second because a constant return has no
	 * variance. Parkinson and Garman–Klass instead read the drift as volatility
	 * — ln(g)²/(4·ln2) and (½ − (2·ln2 − 1))·ln(g)² — which is precisely what
	 * `isDriftIndependent()` warns about.
	 */
	public function testOnlyTheDriftIndependentEstimatorsReadAPureDriftAsZeroVolatility(): void
	{
		$n = 60;
		$g = 1.01;
		$o = [];
		$h = [];
		$l = [];
		$c = [];
		$price = 100.0;
		for ($i = 0; $i < $n; $i++) {
			$open = $price;
			$close = $open * $g;
			$o[] = $open;
			$c[] = $close;
			$h[] = $close;  // monotone rise: the high is the close
			$l[] = $open;   // and the low is the open
			$price = $close;
		}

		$logG = \log($g);

		// the three drift-independent estimators report exactly zero
		foreach (['rogers-satchell', 'yang-zhang'] as $estimator) {
			self::assertTrue(VolatilityEstimator::from($estimator)->isDriftIndependent());
			self::assertEqualsWithDelta(
				0.0,
				OhlcVolatility::compute($o, $h, $l, $c, 20, $estimator)[$n - 1],
				self::TOLERANCE,
				$estimator,
			);
		}
		self::assertTrue(VolatilityEstimator::CloseToClose->isDriftIndependent());
		self::assertEqualsWithDelta(0.0, OhlcVolatility::closeToClose($c, 20)[$n - 1], self::TOLERANCE);

		// the two that are not report the drift as volatility, in closed form
		self::assertFalse(VolatilityEstimator::Parkinson->isDriftIndependent());
		self::assertEqualsWithDelta(
			\sqrt($logG ** 2 / (4.0 * \M_LN2)),
			OhlcVolatility::compute($o, $h, $l, $c, 20, 'parkinson')[$n - 1],
			self::TOLERANCE,
		);

		self::assertFalse(VolatilityEstimator::GarmanKlass->isDriftIndependent());
		self::assertEqualsWithDelta(
			\sqrt((0.5 - (2.0 * \M_LN2 - 1.0)) * $logG ** 2),
			OhlcVolatility::compute($o, $h, $l, $c, 20, 'garman-klass')[$n - 1],
			self::TOLERANCE,
		);
	}

	/**
	 * The gap claim. Bars with a negligible internal range but a large jump
	 * between one close and the next open carry real volatility that only the
	 * gap-aware estimators see.
	 */
	public function testOnlyTheGapAwareEstimatorsSeeAnOvernightJump(): void
	{
		$n = 60;
		$o = [];
		$h = [];
		$l = [];
		$c = [];
		$price = 100.0;
		for ($i = 0; $i < $n; $i++) {
			// a 2% gap, alternating in sign, then an almost flat session
			$price *= $i % 2 === 0 ? 1.02 : 1.0 / 1.02;
			$open = $price;
			$close = $open * 1.00001;
			$o[] = $open;
			$h[] = \max($open, $close) * 1.000005;
			$l[] = \min($open, $close) * 0.999995;
			$c[] = $close;
		}

		$parkinson = OhlcVolatility::compute($o, $h, $l, $c, 20, 'parkinson')[$n - 1];
		$rs = OhlcVolatility::compute($o, $h, $l, $c, 20, 'rogers-satchell')[$n - 1];
		$yz = OhlcVolatility::compute($o, $h, $l, $c, 20, 'yang-zhang')[$n - 1];
		$ctc = OhlcVolatility::compute($c, $c, $c, $c, 20, 'close-to-close')[$n - 1];

		self::assertTrue(VolatilityEstimator::YangZhang->handlesGaps());
		self::assertTrue(VolatilityEstimator::CloseToClose->handlesGaps());
		self::assertFalse(VolatilityEstimator::Parkinson->handlesGaps());
		self::assertFalse(VolatilityEstimator::RogersSatchell->handlesGaps());

		// the intrabar estimators see almost nothing
		self::assertLessThan(1e-4, $parkinson);
		self::assertLessThan(1e-4, $rs);
		// the gap-aware ones see the 2% jump
		self::assertGreaterThan(0.015, $yz);
		self::assertGreaterThan(0.015, $ctc);
	}

	/** The published efficiency constants, as advertised by the enum. */
	public function testEfficiencyConstants(): void
	{
		self::assertSame(1.0, VolatilityEstimator::CloseToClose->efficiency());
		self::assertSame(5.2, VolatilityEstimator::Parkinson->efficiency());
		self::assertSame(7.4, VolatilityEstimator::GarmanKlass->efficiency());
		self::assertSame(8.0, VolatilityEstimator::RogersSatchell->efficiency());
		self::assertSame(14.0, VolatilityEstimator::YangZhang->efficiency());

		self::assertSame(8.0, (new OhlcVolatility(20, VolatilityEstimator::RogersSatchell))->values()['efficiency']);
	}

	/** Annualising is a √T scaling of the per-bar standard deviation. */
	public function testAnnualisedScalesByTheRootOfTheBarCount(): void
	{
		[$o, $h, $l, $c] = self::bars(100, 1221);
		$metric = new OhlcVolatility(20, VolatilityEstimator::Parkinson);
		self::assertNan($metric->annualised(365.0), 'undefined during warm-up');

		foreach ($c as $i => $_) {
			$metric->updateBar(Bar::of($i * 1_000_000_000, $i * 1_000_000_000 + 1, $o[$i], $h[$i], $l[$i], $c[$i]));
		}

		self::assertEqualsWithDelta(
			$metric->value() * \sqrt(365.0),
			$metric->annualised(365.0),
			self::TOLERANCE,
		);
		self::assertEqualsWithDelta($metric->value() ** 2, $metric->variance(), self::TOLERANCE);
	}

	/** A bar series with no movement at all has exactly zero volatility. */
	public function testFlatBarsGiveExactlyZero(): void
	{
		$flat = \array_fill(0, 40, 10.0);
		foreach (['parkinson', 'garman-klass', 'rogers-satchell', 'yang-zhang'] as $estimator) {
			$out = OhlcVolatility::compute($flat, $flat, $flat, $flat, 20, $estimator);
			self::assertSame(0.0, $out[39], $estimator);
		}
		self::assertSame(0.0, OhlcVolatility::closeToClose($flat, 20)[39]);
	}

	public function testWarmUpAndReset(): void
	{
		[$o, $h, $l, $c] = self::bars(40, 3399);
		$metric = new OhlcVolatility(20, VolatilityEstimator::YangZhang);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->variance());

		foreach ($c as $i => $_) {
			$metric->updateBar(Bar::of($i * 1_000_000_000, $i * 1_000_000_000 + 1, $o[$i], $h[$i], $l[$i], $c[$i]));
			self::assertSame($i >= 19, $metric->isReady(), "bar $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
	}

	/**
	 * Close-to-close needs one extra bar, because the first one contributes no
	 * return at all.
	 */
	public function testCloseToCloseNeedsOneExtraBar(): void
	{
		[$o, $h, $l, $c] = self::bars(40, 4499);
		$metric = new OhlcVolatility(20, VolatilityEstimator::CloseToClose);

		foreach ($c as $i => $_) {
			$metric->updateBar(Bar::of($i * 1_000_000_000, $i * 1_000_000_000 + 1, $o[$i], $h[$i], $l[$i], $c[$i]));
			self::assertSame($i >= 20, $metric->isReady(), "bar $i");
		}
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		[$o, $h, $l, $c] = self::bars(200, 8008);
		$kernel = OhlcVolatility::compute($o, $h, $l, $c, 20, 'yang-zhang');

		$metric = new OhlcVolatility(20, VolatilityEstimator::YangZhang);
		foreach ($c as $i => $_) {
			$metric->updateBar(Bar::of($i * 1_000_000_000, $i * 1_000_000_000 + 1, $o[$i], $h[$i], $l[$i], $c[$i]));
			if (\is_nan($kernel[$i])) {
				self::assertNan($metric->value(), "bar $i");
			} else {
				self::assertSame($kernel[$i], $metric->value(), "bar $i");
			}
		}
	}

	public function testWindowMustBeAtLeastTwo(): void
	{
		$this->expectException(InvalidArgument::class);
		new OhlcVolatility(1, VolatilityEstimator::Parkinson);
	}

	public function testPricesMustBeStrictlyPositive(): void
	{
		$this->expectException(InvalidArgument::class);
		OhlcVolatility::compute([1.0], [1.0], [0.0], [1.0], 2, 'parkinson');
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		OhlcVolatility::compute([1.0, 2.0], [1.0], [1.0, 2.0], [1.0, 2.0], 2, 'parkinson');
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new OhlcVolatility(30, VolatilityEstimator::GarmanKlass);
		self::assertSame(
			['type' => 'ohlc-volatility', 'window' => 30, 'estimator' => 'garman-klass'],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), OhlcVolatility::fromArray($metric->toArray())->toArray());
	}
}
