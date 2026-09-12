<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference\Metric;

use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Microstructure\OrderFlowImbalance;
use PHPUnit\Framework\TestCase;

/**
 * Order-flow imbalance (M-29) against a naive implementation of the
 * Cont–Kukanov–Stoikov event, and against the four cases of that event derived
 * by hand.
 *
 * The event contribution is defined by comparisons that are deliberately
 * non-strict on both sides, so an unchanged best price contributes *both* the
 * new size and minus the old one — that is, the change in resting size. A
 * rewrite that uses strict inequalities loses exactly that case, which is also
 * the most common one in a real book, so all four branches are pinned here
 * rather than only the price-moving ones.
 */
final class OrderFlowImbalanceTest extends TestCase
{
	private const TOLERANCE = 1e-9;

	private static function naiveEvent(
		float $pbp,
		float $pbs,
		float $pap,
		float $pas,
		float $bp,
		float $bs,
		float $ap,
		float $as,
	): float {
		$e = 0.0;
		// the bid side adds size when it is not worse than before, and gives up
		// the old size when it is not better
		if ($bp >= $pbp) {
			$e += $bs;
		}
		if ($bp <= $pbp) {
			$e -= $pbs;
		}
		// the ask side, mirrored
		if ($ap <= $pap) {
			$e -= $as;
		}
		if ($ap >= $pap) {
			$e += $pas;
		}
		return $e;
	}

	/**
	 * @param list<float> $bidPrices
	 * @param list<float> $bidSizes
	 * @param list<float> $askPrices
	 * @param list<float> $askSizes
	 * @return list<float>
	 */
	private static function naive(array $bidPrices, array $bidSizes, array $askPrices, array $askSizes, int $window): array
	{
		$events = [];
		$out = [];

		foreach ($bidPrices as $i => $_) {
			if ($i === 0) {
				$out[] = \NAN;
				continue;
			}
			$events[] = self::naiveEvent(
				$bidPrices[$i - 1],
				$bidSizes[$i - 1],
				$askPrices[$i - 1],
				$askSizes[$i - 1],
				$bidPrices[$i],
				$bidSizes[$i],
				$askPrices[$i],
				$askSizes[$i],
			);

			if (\count($events) < $window) {
				$out[] = \NAN;
				continue;
			}
			$out[] = \array_sum(\array_slice($events, -$window));
		}
		return $out;
	}

	/**
	 * A deterministic sequence of book snapshots in which the touch sometimes
	 * moves and sometimes only changes size.
	 *
	 * @return array{list<float>, list<float>, list<float>, list<float>}
	 */
	private static function books(int $n, int $seed): array
	{
		\mt_srand($seed);
		$bidPrices = [];
		$bidSizes = [];
		$askPrices = [];
		$askSizes = [];
		$bid = 50.00;
		$ask = 50.02;
		for ($i = 0; $i < $n; $i++) {
			// a third of the time the touch moves, otherwise only the size changes
			if (\mt_rand(0, 2) === 0) {
				$step = 0.01 * (\mt_rand(0, 1) === 0 ? 1 : -1);
				$bid += $step;
				$ask += $step;
			}
			$bidPrices[] = $bid;
			$askPrices[] = $ask;
			$bidSizes[] = (float) \mt_rand(1, 400) / 10.0;
			$askSizes[] = (float) \mt_rand(1, 400) / 10.0;
		}
		return [$bidPrices, $bidSizes, $askPrices, $askSizes];
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
	public function windows(): iterable
	{
		yield 'window 1' => [1];
		yield 'window 5' => [5];
		yield 'window 50' => [50];
	}

	/** @dataProvider windows */
	public function testMatchesANaiveImplementation(int $window): void
	{
		for ($seed = 1; $seed <= 15; $seed++) {
			[$bp, $bs, $ap, $as] = self::books(300, $seed * 19 + $window);
			self::assertSeriesMatches(
				self::naive($bp, $bs, $ap, $as, $window),
				OrderFlowImbalance::compute($bp, $bs, $ap, $as, $window)['ofi'],
				"seed $seed, window $window",
			);
		}
	}

	/**
	 * The four branches of the event, each derived by hand.
	 *
	 * Bid up: the new bid size is added and the old is not removed, because the
	 * old level is gone. Bid down: the old size is removed and the new is not
	 * added. Bid unchanged: both fire, leaving the *change* in size. The ask
	 * side is the mirror image with the signs reversed.
	 */
	public function testTheFourBranchesOfTheEvent(): void
	{
		// the bid improves from 100 to 101, sizes 5 then 7; the ask stands still
		self::assertEqualsWithDelta(
			7.0,
			OrderFlowImbalance::event(100.0, 5.0, 102.0, 3.0, 101.0, 7.0, 102.0, 3.0),
			self::TOLERANCE,
			'a better bid adds its size',
		);

		// the bid falls away from 100 to 99: the old size is withdrawn
		self::assertEqualsWithDelta(
			-5.0,
			OrderFlowImbalance::event(100.0, 5.0, 102.0, 3.0, 99.0, 7.0, 102.0, 3.0),
			self::TOLERANCE,
			'a worse bid removes the size that was there',
		);

		// the bid stays at 100 and grows from 5 to 7: the change in resting size
		self::assertEqualsWithDelta(
			2.0,
			OrderFlowImbalance::event(100.0, 5.0, 102.0, 3.0, 100.0, 7.0, 102.0, 3.0),
			self::TOLERANCE,
			'an unchanged bid contributes the change in size',
		);

		// the ask improves (falls) from 102 to 101 with size 4: selling pressure
		self::assertEqualsWithDelta(
			-4.0,
			OrderFlowImbalance::event(100.0, 5.0, 102.0, 3.0, 100.0, 5.0, 101.0, 4.0),
			self::TOLERANCE,
			'a better offer subtracts its size',
		);

		// the ask retreats from 102 to 103: the old offer is gone
		self::assertEqualsWithDelta(
			3.0,
			OrderFlowImbalance::event(100.0, 5.0, 102.0, 3.0, 100.0, 5.0, 103.0, 4.0),
			self::TOLERANCE,
			'a worse offer releases the size that was there',
		);

		// nothing changes at all
		self::assertEqualsWithDelta(
			0.0,
			OrderFlowImbalance::event(100.0, 5.0, 102.0, 3.0, 100.0, 5.0, 102.0, 3.0),
			self::TOLERANCE,
			'a still book is no flow',
		);
	}

	/**
	 * The sign convention: bid-side pressure is positive, ask-side pressure
	 * negative. A book where only the bid grows must read positive, and its
	 * mirror image must read exactly the negative of it.
	 */
	public function testTheSignConventionIsBidPositive(): void
	{
		$growingBid = OrderFlowImbalance::event(100.0, 5.0, 102.0, 3.0, 100.0, 50.0, 102.0, 3.0);
		self::assertGreaterThan(0.0, $growingBid);

		$growingAsk = OrderFlowImbalance::event(100.0, 5.0, 102.0, 3.0, 100.0, 5.0, 102.0, 48.0);
		self::assertLessThan(0.0, $growingAsk);

		self::assertEqualsWithDelta($growingBid, -$growingAsk, self::TOLERANCE, 'the two sides are mirror images');
	}

	/** Over a whole window the reading is the sum of its events. */
	public function testTheReadingIsTheSumOfTheEventsInTheWindow(): void
	{
		[$bp, $bs, $ap, $as] = self::books(120, 2468);
		$window = 10;

		$perEvent = OrderFlowImbalance::compute($bp, $bs, $ap, $as, 1)['ofi'];
		$summed = OrderFlowImbalance::compute($bp, $bs, $ap, $as, $window)['ofi'];

		for ($i = $window; $i < 120; $i++) {
			$expected = 0.0;
			for ($k = 0; $k < $window; $k++) {
				$back = $i - $k;
				self::assertGreaterThanOrEqual(0, $back);
				$expected += $perEvent[$back] ?? \NAN;
			}
			self::assertEqualsWithDelta($expected, $summed[$i], self::TOLERANCE, "index $i");
		}
	}

	/** A book that never changes generates no flow at all. */
	public function testAStillBookGivesExactlyZero(): void
	{
		$n = 40;
		$bp = \array_fill(0, $n, 100.0);
		$bs = \array_fill(0, $n, 5.0);
		$ap = \array_fill(0, $n, 101.0);
		$as = \array_fill(0, $n, 5.0);

		$out = OrderFlowImbalance::compute($bp, $bs, $ap, $as, 10)['ofi'];
		for ($i = 10; $i < $n; $i++) {
			self::assertSame(0.0, $out[$i], "index $i");
		}
	}

	/**
	 * `impliedMove()` divides the flow by twice the average depth, so it is a
	 * count of ticks rather than a price — the docblock claim, measured.
	 */
	public function testTheImpliedMoveIsTheFlowOverTwiceTheDepth(): void
	{
		$metric = new OrderFlowImbalance(3);

		// four snapshots: the bid grows by 10 each time, the ask stands still
		$metric->updateBook(OrderBook::of(1, [[100.0, 10.0]], [[101.0, 10.0]]));
		$metric->updateBook(OrderBook::of(2, [[100.0, 20.0]], [[101.0, 10.0]]));
		$metric->updateBook(OrderBook::of(3, [[100.0, 30.0]], [[101.0, 10.0]]));
		$metric->updateBook(OrderBook::of(4, [[100.0, 40.0]], [[101.0, 10.0]]));

		self::assertTrue($metric->isReady());
		// three events of +10 each
		self::assertEqualsWithDelta(30.0, $metric->value(), self::TOLERANCE);

		$values = $metric->values();
		self::assertEqualsWithDelta(
			$metric->value() / (2.0 * $values['depth']),
			$values['impliedMove'],
			self::TOLERANCE,
		);
	}

	public function testWarmUpAndReset(): void
	{
		[$bp, $bs, $ap, $as] = self::books(40, 1357);
		$metric = new OrderFlowImbalance(10);

		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['lastEvent']);

		foreach ($bp as $i => $price) {
			$metric->updateBook(OrderBook::of($i + 1, [[$price, $bs[$i]]], [[$ap[$i], $as[$i]]]));
			// the first snapshot establishes a baseline and produces no event
			self::assertSame($i >= 10, $metric->isReady(), "snapshot $i");
		}

		$metric->reset();
		self::assertFalse($metric->isReady());
		self::assertNan($metric->value());
		self::assertNan($metric->values()['depth']);
	}

	/** An empty snapshot is ignored rather than treated as a book of zeros. */
	public function testAnEmptySnapshotIsIgnored(): void
	{
		$metric = new OrderFlowImbalance(2);
		$metric->updateBook(OrderBook::of(1, [[100.0, 10.0]], [[101.0, 10.0]]));
		$metric->updateBook(OrderBook::of(2, [], []));
		$metric->updateBook(OrderBook::of(3, [[100.0, 20.0]], [[101.0, 10.0]]));
		$metric->updateBook(OrderBook::of(4, [[100.0, 30.0]], [[101.0, 10.0]]));

		self::assertTrue($metric->isReady());
		// two events of +10, the empty snapshot having contributed nothing
		self::assertEqualsWithDelta(20.0, $metric->value(), self::TOLERANCE);
	}

	/** The streaming object and the kernel are one implementation. */
	public function testStreamingObjectAgreesWithTheKernel(): void
	{
		[$bp, $bs, $ap, $as] = self::books(200, 8642);
		$kernel = OrderFlowImbalance::compute($bp, $bs, $ap, $as, 20)['ofi'];

		$metric = new OrderFlowImbalance(20);
		foreach ($bp as $i => $price) {
			$metric->updateBook(OrderBook::of($i + 1, [[$price, $bs[$i]]], [[$ap[$i], $as[$i]]]));
			if (\is_nan($kernel[$i])) {
				self::assertNan($metric->value(), "index $i");
			} else {
				self::assertEqualsWithDelta($kernel[$i], $metric->value(), self::TOLERANCE, "index $i");
			}
		}
	}

	public function testWindowMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new OrderFlowImbalance(0);
	}

	public function testKernelNeedsAlignedInput(): void
	{
		$this->expectException(InvalidArgument::class);
		OrderFlowImbalance::compute([1.0, 2.0], [1.0], [1.0, 2.0], [1.0, 2.0], 1);
	}

	public function testConfigurationRoundTrips(): void
	{
		$metric = new OrderFlowImbalance(75);
		self::assertSame(['type' => 'order-flow-imbalance', 'window' => 75], $metric->toArray());
		self::assertSame($metric->toArray(), OrderFlowImbalance::fromArray($metric->toArray())->toArray());
	}
}
