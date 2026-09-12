<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Trend\MaSpread;
use OpenCCK\Kalman\Domain\Metric\Trend\MaType;
use PHPUnit\Framework\TestCase;

/**
 * Moving-average spread (M-12) against naive moving averages written from
 * their textbook definitions — not against `MovingAverage`, which would only
 * prove that a subtraction is a subtraction.
 *
 * Two properties carry the definition. The spread is a band-pass filter: it
 * kills any component both averages agree on, so a constant price and a
 * constant offset both give exactly zero, while a ramp gives a constant offset
 * that can be written down in closed form. And the relative form divides by the
 * slow average, which makes it dimensionless where the raw spread is not.
 */
final class MaSpreadTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	/**
	 * Naive moving average, seeded the way the definition requires: a simple
	 * average of the first `period` observations for the recursive forms, a
	 * plain window average for SMA, and a linearly weighted one for WMA.
	 *
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveMa(array $prices, int $period, MaType $type): array
	{
		$out = [];

		if ($type === MaType::Sma) {
			foreach ($prices as $i => $_) {
				$out[] = $i + 1 < $period
					? \NAN
					: \array_sum(\array_slice($prices, $i + 1 - $period, $period)) / $period;
			}
			return $out;
		}

		if ($type === MaType::Wma) {
			foreach ($prices as $i => $_) {
				if ($i + 1 < $period) {
					$out[] = \NAN;
					continue;
				}
				$window = \array_slice($prices, $i + 1 - $period, $period);
				$sum = 0.0;
				$weight = 0.0;
				foreach ($window as $k => $v) {
					$w = (float) ($k + 1);
					$sum += $v * $w;
					$weight += $w;
				}
				$out[] = $sum / $weight;
			}
			return $out;
		}

		// Ema: alpha = 2/(n+1); Rma: alpha = 1/n. Both seeded with the simple
		// average of the first `period` observations.
		$alpha = $type === MaType::Ema ? 2.0 / ($period + 1) : 1.0 / $period;
		$value = \NAN;
		foreach ($prices as $i => $price) {
			$seen = $i + 1;
			if ($seen < $period) {
				$out[] = \NAN;
				continue;
			}
			if ($seen === $period) {
				$value = \array_sum(\array_slice($prices, 0, $period)) / $period;
				$out[] = $value;
				continue;
			}
			$value += $alpha * ($price - $value);
			$out[] = $value;
		}
		return $out;
	}

	/**
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveSpread(array $prices, int $fast, int $slow, MaType $type): array
	{
		$f = self::naiveMa($prices, $fast, $type);
		$s = self::naiveMa($prices, $slow, $type);
		$out = [];
		foreach ($prices as $i => $_) {
			$out[] = \is_nan($f[$i]) || \is_nan($s[$i]) ? \NAN : $f[$i] - $s[$i];
		}
		return $out;
	}

	/**
	 * @param list<float> $prices
	 * @return list<float>
	 */
	private static function naiveRelative(array $prices, int $fast, int $slow, MaType $type): array
	{
		$s = self::naiveMa($prices, $slow, $type);
		$spread = self::naiveSpread($prices, $fast, $slow, $type);
		$out = [];
		foreach ($prices as $i => $_) {
			$out[] = \is_nan($spread[$i]) || $s[$i] <= 0.0 ? \NAN : $spread[$i] / $s[$i];
		}
		return $out;
	}

	/** @return list<float> */
	private static function prices(int $n, int $seed): array
	{
		\mt_srand($seed);
		$out = [];
		$price = 2000.0;
		for ($i = 0; $i < $n; $i++) {
			$price += (float) \mt_rand(-500, 505) / 100.0;
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

	/** @return iterable<string, array{int, int, string}> */
	public function configurations(): iterable
	{
		yield 'sma 5/20' => [5, 20, 'sma'];
		yield 'sma 1/2' => [1, 2, 'sma'];
		yield 'ema 12/26' => [12, 26, 'ema'];
		yield 'rma 7/40' => [7, 40, 'rma'];
		yield 'wma 4/16' => [4, 16, 'wma'];
	}

	/** @dataProvider configurations */
	public function testMatchesNaiveMovingAverages(int $fast, int $slow, string $ma): void
	{
		$prices = self::prices(600, $fast * 29 + $slow);
		self::assertSeriesMatches(
			self::naiveSpread($prices, $fast, $slow, MaType::from($ma)),
			MaSpread::compute($prices, $fast, $slow, $ma),
			"compute($fast, $slow, $ma)",
		);
	}

	/** @dataProvider configurations */
	public function testRelativeMatchesNaiveMovingAverages(int $fast, int $slow, string $ma): void
	{
		$prices = self::prices(600, $fast * 31 + $slow);
		self::assertSeriesMatches(
			self::naiveRelative($prices, $fast, $slow, MaType::from($ma)),
			MaSpread::relative($prices, $fast, $slow, $ma),
			"relative($fast, $slow, $ma)",
		);
	}

	/**
	 * The band-pass property: both averages agree exactly on a constant, so the
	 * spread is exactly zero — no transient once both are warm, and no drift.
	 */
	public function testAConstantPriceGivesExactlyZeroSpread(): void
	{
		$flat = \array_fill(0, 200, 640.0);

		foreach (['sma', 'ema', 'rma', 'wma'] as $ma) {
			$out = MaSpread::compute($flat, 10, 50, $ma);
			for ($i = 49; $i < 200; $i++) {
				self::assertSame(0.0, $out[$i], "$ma at $i");
			}
			$relative = MaSpread::relative($flat, 10, 50, $ma);
			self::assertSame(0.0, $relative[199], "$ma relative");
		}
	}

	/**
	 * On an exact linear ramp an SMA equals the price at the centre of its
	 * window, so the spread settles at slope × (slow − fast) / 2 — a value that
	 * follows from the definition alone and does not depend on the price level.
	 */
	public function testOnALinearRampTheSmaSpreadIsSlopeTimesHalfTheWindowDifference(): void
	{
		$slope = 0.75;
		$fast = 10;
		$slow = 40;
		$prices = [];
		for ($i = 0; $i < 300; $i++) {
			$prices[] = 500.0 + $slope * $i;
		}

		$out = MaSpread::compute($prices, $fast, $slow, 'sma');
		$expected = $slope * ($slow - $fast) / 2.0;

		for ($i = 100; $i < 300; $i++) {
			self::assertEqualsWithDelta($expected, $out[$i], self::TOLERANCE, "index $i");
		}

		// a falling ramp mirrors it exactly
		$falling = \array_map(static fn (float $p): float => 1000.0 - ($p - 500.0), $prices);
		self::assertEqualsWithDelta(-$expected, MaSpread::compute($falling, $fast, $slow, 'sma')[299], self::TOLERANCE);
	}

	/**
	 * The raw spread carries the price's units and the relative form does not:
	 * scaling every price scales the spread by the same factor while leaving
	 * the relative reading alone. That is the whole reason the second form
	 * exists.
	 */
	public function testTheRelativeFormIsDimensionlessAndTheRawSpreadIsNot(): void
	{
		$prices = self::prices(400, 1201);
		$scaled = \array_map(static fn (float $p): float => 12.0 * $p, $prices);

		$plain = MaSpread::compute($prices, 8, 32, 'sma');
		$big = MaSpread::compute($scaled, 8, 32, 'sma');
		for ($i = 31; $i < 400; $i++) {
			self::assertEqualsWithDelta(12.0 * $plain[$i], $big[$i], self::TOLERANCE, "raw spread scales at $i");
		}

		self::assertSeriesMatches(
			MaSpread::relative($prices, 8, 32, 'sma'),
			MaSpread::relative($scaled, 8, 32, 'sma'),
			'relative under scaling',
		);
	}

	/** The sign says which horizon leads: after a step up, the fast one does. */
	public function testTheSignSaysWhichHorizonLeads(): void
	{
		$prices = [];
		$down = [];
		for ($i = 0; $i < 200; $i++) {
			$prices[] = $i < 100 ? 100.0 : 120.0;
			$down[] = $i < 100 ? 120.0 : 100.0;
		}
		$out = MaSpread::compute($prices, 5, 40, 'sma');

		// right after the step the short average has moved and the long one has not
		self::assertGreaterThan(0.0, $out[110]);
		// and long after it, both have caught up again
		self::assertEqualsWithDelta(0.0, $out[199], self::TOLERANCE);

		self::assertLessThan(0.0, MaSpread::compute($down, 5, 40, 'sma')[110] ?? \NAN);
	}

	/** `values()` exposes both averages behind the spread, and they agree with it. */
	public function testValuesExposeBothAveragesBehindTheSpread(): void
	{
		$prices = self::prices(200, 8080);
		$metric = new MaSpread(6, 24, MaType::Ema);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
		}

		$values = $metric->values();
		self::assertEqualsWithDelta($values['fast'] - $values['slow'], $values['spread'], self::TOLERANCE);
		self::assertEqualsWithDelta($values['spread'] / $values['slow'], $values['relative'], self::TOLERANCE);
		self::assertSame($metric->value(), $values['spread']);
	}

	public function testWarmUpAndReset(): void
	{
		$prices = self::prices(80, 4004);
		$metric = new MaSpread(5, 20, MaType::Sma);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());

		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			// readiness follows the slow average, which is the later of the two
			self::assertSame($i >= 19, $metric->isReady(), "tick $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['relative']);
	}

	/** The streaming object and the kernels are one implementation. */
	public function testStreamingObjectAgreesWithTheKernels(): void
	{
		$prices = self::prices(400, 2402);
		$spread = MaSpread::compute($prices, 9, 36, 'ema');
		$relative = MaSpread::relative($prices, 9, 36, 'ema');

		$metric = new MaSpread(9, 36, MaType::Ema);
		foreach ($prices as $i => $price) {
			$metric->updatePrice(($i + 1) * 1_000_000_000, $price);
			if (\is_nan($spread[$i])) {
				self::assertNan($metric->value(), "index $i");
				continue;
			}
			self::assertSame($spread[$i], $metric->value(), "spread at $i");
			self::assertSame($relative[$i], $metric->values()['relative'], "relative at $i");
		}
	}

	public function testTheFastPeriodMustBeShorterThanTheSlowOne(): void
	{
		$this->expectException(InvalidArgument::class);
		new MaSpread(30, 30, MaType::Sma);
	}

	public function testPeriodsMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new MaSpread(0, 10, MaType::Sma);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new MaSpread(15, 60, MaType::Rma);
		self::assertSame(
			['type' => 'ma-spread', 'fast' => 15, 'slow' => 60, 'ma' => 'rma'],
			$metric->toArray(),
		);
		self::assertSame($metric->toArray(), MaSpread::fromArray($metric->toArray())->toArray());
	}
}
