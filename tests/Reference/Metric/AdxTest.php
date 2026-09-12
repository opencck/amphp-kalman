<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Trend\Adx;
use PHPUnit\Framework\TestCase;

/**
 * ADX and the directional pair against a naive Wilder DMI written from the
 * definition, with the running sums Wilder actually published rather than the
 * RMA the library is built on — the two agree exactly, because ±DI is a ratio
 * in which the 1/N cancels.
 *
 * The last two tests pin the rule implementations most often get wrong: only
 * one directional move can be counted per bar, so an outside bar is not
 * evidence of direction.
 */
final class AdxTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive Wilder DMI/ADX.
	 *
	 * @param list<float> $highs
	 * @param list<float> $lows
	 * @param list<float> $closes
	 * @return array{adx: list<float>, plusDi: list<float>, minusDi: list<float>, dx: list<float>}
	 */
	private static function naiveDirectional(array $highs, array $lows, array $closes, int $period): array
	{
		$n = \count($closes);
		if ($n === 0) {
			return ['adx' => [], 'plusDi' => [], 'minusDi' => [], 'dx' => []];
		}

		// the first bar has no previous close, so it has no true range and no
		// directional move
		$adx = [\NAN];
		$plusDi = [\NAN];
		$minusDi = [\NAN];
		$dxOut = [\NAN];

		$trSum = 0.0;
		$plusSum = 0.0;
		$minusSum = 0.0;
		$dxSum = 0.0;
		$adxValue = \NAN;
		$moves = 0;
		$dxCount = 0;

		for ($i = 1; $i < $n; $i++) {
			$barPlusDi = \NAN;
			$barMinusDi = \NAN;
			$barDx = \NAN;
			$barAdx = \NAN;

			// Wilder's true range: the bar range or either gap to the previous close
			$tr = \max(
				$highs[$i] - $lows[$i],
				\abs($highs[$i] - $closes[$i - 1]),
				\abs($lows[$i] - $closes[$i - 1]),
			);
			$upMove = $highs[$i] - $highs[$i - 1];
			$downMove = $lows[$i - 1] - $lows[$i];
			// only one move per bar; an outside bar counts as neither
			$plusDm = ($upMove > $downMove && $upMove > 0.0) ? $upMove : 0.0;
			$minusDm = ($downMove > $upMove && $downMove > 0.0) ? $downMove : 0.0;

			$moves++;
			if ($moves <= $period) {
				// Wilder seeds with the plain sum of the first N values
				$trSum += $tr;
				$plusSum += $plusDm;
				$minusSum += $minusDm;
			} else {
				$trSum = $trSum - $trSum / $period + $tr;
				$plusSum = $plusSum - $plusSum / $period + $plusDm;
				$minusSum = $minusSum - $minusSum / $period + $minusDm;
			}

			if ($moves >= $period && $trSum > 0.0) {
				$plus = 100.0 * $plusSum / $trSum;
				$minus = 100.0 * $minusSum / $trSum;
				$barPlusDi = $plus;
				$barMinusDi = $minus;

				$sum = $plus + $minus;
				$barDx = $sum > 0.0 ? 100.0 * \abs($plus - $minus) / $sum : 0.0;

				$dxCount++;
				if ($dxCount <= $period) {
					$dxSum += $barDx;
					if ($dxCount === $period) {
						$adxValue = $dxSum / $period;
						$barAdx = $adxValue;
					}
				} else {
					$adxValue += ($barDx - $adxValue) / $period;
					$barAdx = $adxValue;
				}
			}

			$adx[] = $barAdx;
			$plusDi[] = $barPlusDi;
			$minusDi[] = $barMinusDi;
			$dxOut[] = $barDx;
		}

		return ['adx' => $adx, 'plusDi' => $plusDi, 'minusDi' => $minusDi, 'dx' => $dxOut];
	}

	/**
	 * A deterministic bar series with a genuine range on every bar.
	 *
	 * @return array{highs: list<float>, lows: list<float>, closes: list<float>}
	 */
	private static function bars(int $n, int $seed, float $drift = 0.0): array
	{
		\mt_srand($seed);
		$highs = [];
		$lows = [];
		$closes = [];
		$price = 100.0;
		for ($i = 0; $i < $n; $i++) {
			$price += $drift + (float) \mt_rand(-150, 150) / 100.0;
			$high = $price + (float) \mt_rand(10, 150) / 100.0;
			$low = $price - (float) \mt_rand(10, 150) / 100.0;
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
		yield 'period 2' => [2];
		yield 'period 5' => [5];
		yield 'period 14' => [14];
		yield 'period 20' => [20];
	}

	/** @dataProvider periods */
	public function testMatchesANaiveWilderImplementation(int $period): void
	{
		$bars = self::bars(600, 500 + $period);
		$expected = self::naiveDirectional($bars['highs'], $bars['lows'], $bars['closes'], $period);
		$actual = Adx::directional($bars['highs'], $bars['lows'], $bars['closes'], $period);

		self::assertSame(['adx', 'plusDi', 'minusDi', 'dx'], \array_keys($actual));
		self::assertSeriesMatches($expected['plusDi'], $actual['plusDi'], "+DI($period)");
		self::assertSeriesMatches($expected['minusDi'], $actual['minusDi'], "-DI($period)");
		self::assertSeriesMatches($expected['dx'], $actual['dx'], "DX($period)");
		self::assertSeriesMatches($expected['adx'], $actual['adx'], "ADX($period)");
		self::assertSeriesMatches(
			$expected['adx'],
			Adx::compute($bars['highs'], $bars['lows'], $bars['closes'], $period),
			"compute($period)",
		);
	}

	/**
	 * A market that only ever goes up: every bar contributes +DM and no −DM,
	 * so +DI is 100, −DI is 0, DX is 100 and the index saturates.
	 */
	public function testPureUptrendSaturatesTheIndex(): void
	{
		$highs = [];
		$lows = [];
		$closes = [];
		for ($i = 0; $i < 120; $i++) {
			$lows[] = 100.0 + 2.0 * $i;
			$highs[] = 101.0 + 2.0 * $i;
			$closes[] = 101.0 + 2.0 * $i;
		}

		$result = Adx::directional($highs, $lows, $closes, 14);
		self::assertGreaterThan(90.0, $result['adx'][119], 'a pure uptrend must read as strongly directional');
		self::assertGreaterThan($result['minusDi'][119], $result['plusDi'][119], '+DI must lead −DI in an uptrend');
		self::assertEqualsWithDelta(0.0, $result['minusDi'][119], self::TOLERANCE);

		// and the mirror image
		$downHighs = [];
		$downLows = [];
		$downCloses = [];
		for ($i = 0; $i < 120; $i++) {
			$downHighs[] = 500.0 - 2.0 * $i;
			$downLows[] = 499.0 - 2.0 * $i;
			$downCloses[] = 499.0 - 2.0 * $i;
		}
		$down = Adx::directional($downHighs, $downLows, $downCloses, 14);
		self::assertGreaterThan(90.0, $down['adx'][119]);
		self::assertGreaterThan($down['plusDi'][119], $down['minusDi'][119], '−DI must lead +DI in a downtrend');
	}

	/**
	 * Only one directional move is counted per bar. An outside bar extends
	 * both ends of the previous range; the larger move wins and the smaller
	 * one is zero, because an outside bar is not evidence of direction.
	 *
	 * Period 1 makes Wilder's smoothing the identity, so the DI pair reports
	 * the single bar being examined.
	 */
	public function testOutsideBarCountsOnlyTheLargerMove(): void
	{
		$metric = new Adx(1);
		// the reference bar
		$metric->updateBar(Bar::of(0, 1, 105.0, 110.0, 100.0, 105.0));
		$metric->updateBar(Bar::of(2, 3, 105.0, 110.0, 100.0, 105.0));

		// an outside bar: up move +1, down move +5 ⇒ the down move wins
		$metric->updateBar(Bar::of(4, 5, 100.0, 111.0, 95.0, 100.0));
		$values = $metric->values();
		// TR = max(111 − 95, |111 − 105|, |95 − 105|) = 16
		self::assertSame(0.0, $values['plusDi'], 'the smaller move must not be counted');
		self::assertEqualsWithDelta(100.0 * 5.0 / 16.0, $values['minusDi'], self::TOLERANCE);

		// the mirror: up move +6, down move +1 ⇒ the up move wins
		$mirror = new Adx(1);
		$mirror->updateBar(Bar::of(0, 1, 105.0, 110.0, 100.0, 105.0));
		$mirror->updateBar(Bar::of(2, 3, 105.0, 110.0, 100.0, 105.0));
		$mirror->updateBar(Bar::of(4, 5, 100.0, 116.0, 99.0, 100.0));
		$mirrorValues = $mirror->values();
		// TR = max(116 − 99, |116 − 105|, |99 − 105|) = 17
		self::assertSame(0.0, $mirrorValues['minusDi'], 'the smaller move must not be counted');
		self::assertEqualsWithDelta(100.0 * 6.0 / 17.0, $mirrorValues['plusDi'], self::TOLERANCE);
	}

	/** An inside bar extends neither end: both directional moves are zero. */
	public function testInsideBarCountsNoDirectionalMoveAtAll(): void
	{
		$metric = new Adx(1);
		$metric->updateBar(Bar::of(0, 1, 105.0, 110.0, 100.0, 105.0));
		$metric->updateBar(Bar::of(2, 3, 105.0, 110.0, 100.0, 105.0));
		$metric->updateBar(Bar::of(4, 5, 105.0, 108.0, 102.0, 105.0));

		$values = $metric->values();
		self::assertSame(0.0, $values['plusDi']);
		self::assertSame(0.0, $values['minusDi']);
		// no directional movement at all in the window: the index is 0, not 0/0
		self::assertSame(0.0, $values['dx']);
	}

	/**
	 * The double Wilder smoothing is the reason the warm-up is so long: the
	 * index only appears after 2N−1 bars, and `isSettled()` reports the
	 * stricter condition it takes for the seed to stop showing.
	 */
	public function testWarmUpAccountsForBothSmoothingStages(): void
	{
		$bars = self::bars(200, 7007);
		$metric = new Adx(14);
		self::assertFalse($metric->isReady());
		self::assertFalse($metric->isSettled());
		self::assertNan($metric->value());

		for ($i = 0; $i < 200; $i++) {
			$metric->updateBar(Bar::of(
				$i * 1_000_000_000,
				$i * 1_000_000_000 + 999,
				$bars['closes'][$i],
				$bars['highs'][$i],
				$bars['lows'][$i],
				$bars['closes'][$i],
			));
			self::assertSame($i >= 27, $metric->isReady(), "bar $i readiness");
			self::assertSame($i >= 153, $metric->isSettled(), "bar $i settling");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertFalse($metric->isSettled());
		self::assertNan($metric->value());
	}

	/** ADX is indifferent to direction: mirroring the series leaves it alone. */
	public function testIndexIsIndifferentToDirection(): void
	{
		$bars = self::bars(400, 31337, 0.4);
		$up = Adx::directional($bars['highs'], $bars['lows'], $bars['closes'], 14);

		// reflect the series about zero: highs become lows and vice versa, and
		// negation is exact in binary floating point, so nothing else moves
		$highs = [];
		$lows = [];
		$closes = [];
		foreach ($bars['closes'] as $i => $close) {
			$highs[] = -$bars['lows'][$i];
			$lows[] = -$bars['highs'][$i];
			$closes[] = -$close;
		}
		$down = Adx::directional($highs, $lows, $closes, 14);

		self::assertSeriesMatches($up['adx'], $down['adx'], 'mirrored ADX');
		self::assertSeriesMatches($up['plusDi'], $down['minusDi'], 'mirrored +DI');
		self::assertSeriesMatches($up['minusDi'], $down['plusDi'], 'mirrored −DI');
	}

	public function testPeriodMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new Adx(0);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		Adx::directional([1.0, 2.0], [1.0, 2.0], [1.0]);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new Adx(21);
		self::assertSame(['type' => 'adx', 'period' => 21], $metric->toArray());
		self::assertSame($metric->toArray(), Adx::fromArray($metric->toArray())->toArray());
	}
}
