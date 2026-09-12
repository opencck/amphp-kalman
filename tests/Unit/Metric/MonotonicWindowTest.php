<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Support\MonotonicWindow;
use PHPUnit\Framework\TestCase;

/**
 * The monotonic deque replaces an O(N) rescan of the window. It is only worth
 * anything if it returns exactly what the rescan would, so that is what is
 * checked here — on every push of a long random sequence.
 */
final class MonotonicWindowTest extends TestCase
{
	/**
	 * The naive alternative the deque replaces: a full rescan of the window.
	 *
	 * @param list<float> $window
	 */
	private static function naiveMax(array $window): float
	{
		$max = -\INF;
		foreach ($window as $value) {
			if ($value > $max) {
				$max = $value;
			}
		}
		return $max;
	}

	/** @param list<float> $window */
	private static function naiveMin(array $window): float
	{
		$min = \INF;
		foreach ($window as $value) {
			if ($value < $min) {
				$min = $value;
			}
		}
		return $min;
	}

	public function testCapacityMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		MonotonicWindow::max(0);
	}

	public function testValueIsNanBeforeTheFirstPush(): void
	{
		$max = MonotonicWindow::max(4);
		self::assertNan($max->value());
		self::assertSame(0, $max->count());
		self::assertFalse($max->isFull());

		$min = MonotonicWindow::min(4);
		self::assertNan($min->value());
	}

	/** @return iterable<string, array{int}> */
	public function capacities(): iterable
	{
		yield 'capacity 1' => [1];
		yield 'capacity 2' => [2];
		yield 'capacity 3' => [3];
		yield 'capacity 14' => [14];
		yield 'capacity 37' => [37];
	}

	/**
	 * @dataProvider capacities
	 */
	public function testMaxMatchesANaiveRescan(int $capacity): void
	{
		\mt_srand(1234 + $capacity);
		$window = MonotonicWindow::max($capacity);
		$naive = [];

		for ($i = 0; $i < 3000; $i++) {
			$value = (float) \mt_rand(-1000, 1000) / 8.0;
			$window->push($value);
			$naive[] = $value;
			if (\count($naive) > $capacity) {
				\array_shift($naive);
			}
			self::assertSame(self::naiveMax($naive), $window->value(), "capacity $capacity push $i");
			self::assertSame(\count($naive), $window->count());
		}
		self::assertTrue($window->isFull());
	}

	/**
	 * @dataProvider capacities
	 */
	public function testMinMatchesANaiveRescan(int $capacity): void
	{
		\mt_srand(9876 + $capacity);
		$window = MonotonicWindow::min($capacity);
		$naive = [];

		for ($i = 0; $i < 3000; $i++) {
			$value = (float) \mt_rand(-1000, 1000) / 8.0;
			$window->push($value);
			$naive[] = $value;
			if (\count($naive) > $capacity) {
				\array_shift($naive);
			}
			self::assertSame(self::naiveMin($naive), $window->value(), "capacity $capacity push $i");
		}
	}

	/**
	 * Repeated values are the case a "discard everything not strictly greater"
	 * deque gets wrong: the duplicates must not evict each other early.
	 *
	 * @dataProvider capacities
	 */
	public function testTiesAndPlateausMatchANaiveRescan(int $capacity): void
	{
		\mt_srand(555 + $capacity);
		$max = MonotonicWindow::max($capacity);
		$min = MonotonicWindow::min($capacity);
		$naive = [];

		for ($i = 0; $i < 3000; $i++) {
			// only five distinct values, so ties and plateaus are everywhere
			$value = (float) \mt_rand(0, 4);
			$max->push($value);
			$min->push($value);
			$naive[] = $value;
			if (\count($naive) > $capacity) {
				\array_shift($naive);
			}
			self::assertSame(self::naiveMax($naive), $max->value(), "max, capacity $capacity push $i");
			self::assertSame(self::naiveMin($naive), $min->value(), "min, capacity $capacity push $i");
		}
	}

	public function testMonotoneSequencesAreTheTwoDegenerateShapes(): void
	{
		$max = MonotonicWindow::max(3);
		$min = MonotonicWindow::min(3);
		// strictly rising: the newest value is both the max and the window's top
		foreach ([1.0, 2.0, 3.0, 4.0, 5.0] as $value) {
			$max->push($value);
			$min->push($value);
			self::assertSame($value, $max->value());
		}
		self::assertSame(3.0, $min->value());

		$max->reset();
		$min->reset();
		// strictly falling: every push evicts nothing from the max deque
		foreach ([5.0, 4.0, 3.0, 2.0, 1.0] as $value) {
			$max->push($value);
			$min->push($value);
			self::assertSame($value, $min->value());
		}
		self::assertSame(3.0, $max->value());
	}

	public function testResetEmptiesTheWindow(): void
	{
		$window = MonotonicWindow::max(3);
		foreach ([5.0, 1.0, 9.0] as $value) {
			$window->push($value);
		}
		self::assertSame(9.0, $window->value());

		$window->reset();
		self::assertNan($window->value());
		self::assertSame(0, $window->count());
		self::assertFalse($window->isFull());

		// and it refills from scratch: the values from before the reset are gone
		$window->push(2.0);
		self::assertSame(2.0, $window->value());
		self::assertFalse($window->isFull());
		$window->push(1.0);
		$window->push(0.5);
		self::assertTrue($window->isFull());
		self::assertSame(2.0, $window->value());
	}
}
