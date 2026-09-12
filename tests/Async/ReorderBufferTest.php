<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Infrastructure\Async\ReorderBuffer;
use OpenCCK\Kalman\Infrastructure\Command\SnapshotRequest;
use PHPUnit\Framework\TestCase;

final class ReorderBufferTest extends TestCase
{
	/**
	 * @param iterable<mixed> $items
	 * @return array<int, mixed>
	 */
	private static function collect(ReorderBuffer $buffer, iterable $items): array
	{
		$out = [];
		foreach ($buffer->apply($items) as $x) {
			$out[] = $x;
		}
		return $out;
	}

	/**
	 * @param array<int, mixed> $items
	 * @return array<int, int>
	 */
	private static function timestamps(array $items): array
	{
		$ts = [];
		foreach ($items as $x) {
			self::assertInstanceOf(Measurement::class, $x);
			$ts[] = $x->timestampNs;
		}
		return $ts;
	}

	public function testReordersWithinWindow(): void
	{
		$m = static fn (int $ts): Measurement => Measurement::at($ts, [0 => (float) $ts]);
		$out = self::collect(new ReorderBuffer(50), [$m(100), $m(130), $m(110), $m(200), $m(190), $m(300)]);
		self::assertSame([100, 110, 130, 190, 200, 300], self::timestamps($out));
	}

	public function testReleasesOnlyOlderThanWatermarkMinusWindow(): void
	{
		$m = static fn (int $ts): Measurement => Measurement::at($ts, [0 => 1.0]);
		$buffer = new ReorderBuffer(100);
		$gen = $buffer->apply([$m(1000), $m(1050), $m(1200)]);
		$released = [];
		foreach ($gen as $x) {
			self::assertInstanceOf(Measurement::class, $x);
			$released[] = $x->timestampNs;
			if (\count($released) === 1) {
				// after seeing 1200, only 1000 (≤ 1200 − 100) is releasable; 1050 is still held
				self::assertSame(1000, $released[0]);
			}
		}
		self::assertSame([1000, 1050, 1200], $released);
	}

	public function testLateArrivalIsStillReleasedInOrderOfExtraction(): void
	{
		$m = static fn (int $ts): Measurement => Measurement::at($ts, [0 => 1.0]);
		// 500 arrives after the watermark has moved to 1000 with window 100: it is released
		// immediately (it is below the watermark) — before 1000, so the session sees it in order.
		$out = self::collect(new ReorderBuffer(100), [$m(1000), $m(500), $m(1200)]);
		self::assertSame([500, 1000, 1200], self::timestamps($out));
	}

	public function testTiesKeepArrivalOrder(): void
	{
		$a = Measurement::at(10, [0 => 1.0]);
		$b = Measurement::at(10, [1 => 2.0]);
		$out = self::collect(new ReorderBuffer(0), [$a, $b, Measurement::at(11, [0 => 0.0])]);
		self::assertSame($a, $out[0]);
		self::assertSame($b, $out[1]);
	}

	public function testCommandsPassThroughAfterReleasableMeasurements(): void
	{
		$m = static fn (int $ts): Measurement => Measurement::at($ts, [0 => 1.0]);
		$cmd = new SnapshotRequest();
		$out = self::collect(new ReorderBuffer(100), [$m(1000), $m(1150), $cmd, $m(1300)]);
		// at the command: watermark 1150 → 1000 releasable, 1150 held; command passes; then 1150, 1300
		self::assertInstanceOf(Measurement::class, $out[0]);
		self::assertSame(1000, $out[0]->timestampNs);
		self::assertSame($cmd, $out[1]);
		self::assertInstanceOf(Measurement::class, $out[2]);
		self::assertSame(1150, $out[2]->timestampNs);
	}
}
