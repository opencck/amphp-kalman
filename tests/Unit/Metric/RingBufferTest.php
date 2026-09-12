<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Metric;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Metric\Support\RingBuffer;
use PHPUnit\Framework\TestCase;

/**
 * Fixed-capacity circular buffer: eviction order, 0-is-oldest indexing and
 * wraparound, checked against a naive `array_shift` window.
 */
final class RingBufferTest extends TestCase
{
	public function testCapacityMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new RingBuffer(0);
	}

	public function testPushReturnsNanWhileFillingThenEvictsOldestFirst(): void
	{
		$buffer = new RingBuffer(3);

		self::assertNan($buffer->push(1.0));
		self::assertNan($buffer->push(2.0));
		self::assertFalse($buffer->isFull());
		self::assertNan($buffer->push(3.0));
		self::assertTrue($buffer->isFull());
		self::assertSame(3, $buffer->count());

		// FIFO: the first value in is the first value out.
		self::assertSame(1.0, $buffer->push(4.0));
		self::assertSame(2.0, $buffer->push(5.0));
		self::assertSame(3.0, $buffer->push(6.0));
		self::assertSame(3, $buffer->count());
	}

	public function testAtIndexesOldestFirst(): void
	{
		$buffer = new RingBuffer(4);
		foreach ([10.0, 20.0, 30.0] as $value) {
			$buffer->push($value);
		}

		self::assertSame(10.0, $buffer->at(0));
		self::assertSame(20.0, $buffer->at(1));
		self::assertSame(30.0, $buffer->at(2));
		self::assertSame(10.0, $buffer->oldest());
		self::assertSame(30.0, $buffer->newest());
		self::assertSame([10.0, 20.0, 30.0], $buffer->toList());
	}

	public function testOldestAndNewestAreNanWhileEmpty(): void
	{
		$buffer = new RingBuffer(2);
		self::assertNan($buffer->oldest());
		self::assertNan($buffer->newest());
		self::assertSame(0, $buffer->count());
		self::assertSame([], $buffer->toList());
	}

	/** @dataProvider outOfRangeIndexes */
	public function testAtRejectsOutOfRangeIndexes(int $index): void
	{
		$buffer = new RingBuffer(4);
		$buffer->push(1.0);
		$buffer->push(2.0);

		$this->expectException(InvalidArgument::class);
		$buffer->at($index);
	}

	/** @return iterable<string, array{int}> */
	public function outOfRangeIndexes(): iterable
	{
		yield 'negative' => [-1];
		yield 'at size' => [2];
		yield 'past capacity' => [4];
		yield 'far past' => [1000];
	}

	public function testAtRejectsEveryIndexWhileEmpty(): void
	{
		$buffer = new RingBuffer(4);
		$this->expectException(InvalidArgument::class);
		$buffer->at(0);
	}

	/**
	 * The contents after any number of pushes are the last `capacity` values,
	 * oldest first — checked against a naive sliding window over 500 pushes so
	 * that the wraparound arithmetic is exercised many times over.
	 */
	public function testWraparoundMatchesNaiveWindow(): void
	{
		\mt_srand(20240917);
		foreach ([1, 2, 3, 7, 16] as $capacity) {
			$buffer = new RingBuffer($capacity);
			$naive = [];
			for ($i = 0; $i < 500; $i++) {
				$value = (float) \mt_rand(-10000, 10000) / 100.0;

				$naive[] = $value;
				$expectedEvicted = \NAN;
				if (\count($naive) > $capacity) {
					$expectedEvicted = \array_shift($naive);
				}

				$evicted = $buffer->push($value);
				if (\is_nan($expectedEvicted)) {
					self::assertNan($evicted, "capacity $capacity push $i");
				} else {
					self::assertSame($expectedEvicted, $evicted, "capacity $capacity push $i");
				}
				self::assertSame($naive, $buffer->toList(), "capacity $capacity push $i");
				self::assertSame(\count($naive), $buffer->count());
			}
		}
	}

	public function testResetEmptiesTheBuffer(): void
	{
		$buffer = new RingBuffer(3);
		$buffer->push(1.0);
		$buffer->push(2.0);
		$buffer->push(3.0);
		$buffer->reset();

		self::assertSame(0, $buffer->count());
		self::assertFalse($buffer->isFull());
		self::assertSame([], $buffer->toList());
		// and it refills from scratch, evicting nothing until full again
		self::assertNan($buffer->push(9.0));
		self::assertSame([9.0], $buffer->toList());
	}
}
