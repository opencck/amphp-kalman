<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Diagnostics;

use OpenCCK\Kalman\Domain\Diagnostics\ConsistencyMonitor;
use OpenCCK\Kalman\Domain\Entity\ChannelOutcome;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Entity\UpdateResult;
use PHPUnit\Framework\TestCase;

final class ConsistencyMonitorTest extends TestCase
{
	private static function result(float ...$innovations): UpdateResult
	{
		$outcomes = [];
		$ch = 0;
		foreach ($innovations as $y) {
			$outcomes[] = new ChannelOutcome($ch++, $y, 1.0, 1.0);
		}
		return new UpdateResult($outcomes, 0.0, 0.1);
	}

	public function testRollingNisAndCounts(): void
	{
		$m = new ConsistencyMonitor(2, window: 4);
		$m->record(self::result(1.0, 2.0));   // nis 1 + 4 = 5 over 2 dof
		$m->record(self::result(0.0, 1.0));   // 0 + 1
		self::assertEqualsWithDelta(6.0 / 4.0, $m->rollingNis(), 1e-12);
		self::assertEqualsWithDelta(6.0 / 4.0, $m->meanNis(), 1e-12);
		self::assertSame(4, $m->totalAccepted());
		self::assertSame(2, $m->stepsRecorded());
		self::assertEqualsWithDelta(0.5, $m->channel(0)->rollingNis(), 1e-12);      // (1 + 0)/2
		self::assertEqualsWithDelta(2.5, $m->channel(1)->rollingNis(), 1e-12);      // (4 + 1)/2
	}

	public function testWindowEvictsOldValues(): void
	{
		$m = new ConsistencyMonitor(1, window: 2);
		$m->record(self::result(10.0));
		$m->record(self::result(1.0));
		$m->record(self::result(1.0));
		self::assertEqualsWithDelta(1.0, $m->rollingNis(), 1e-12);
		self::assertEqualsWithDelta((100.0 + 1.0 + 1.0) / 3.0, $m->meanNis(), 1e-12);
	}

	public function testRejectionStreakTriggersModelBreak(): void
	{
		$m = new ConsistencyMonitor(1, modelBreakThreshold: 3);
		$rejected = new UpdateResult([new ChannelOutcome(0, 50.0, 1.0, 0.0)], 0.0, 0.1);
		$m->record($rejected);
		$m->record($rejected);
		self::assertFalse($m->modelBreakSuspected());
		$m->record($rejected);
		self::assertTrue($m->modelBreakSuspected());
		self::assertSame(1, $m->modelBreakEvents());
		self::assertSame(3, $m->totalRejected());
		self::assertSame(1.0, $m->rejectionRate());
		$m->record(self::result(0.5));
		self::assertFalse($m->modelBreakSuspected());
		self::assertSame(3, $m->channel(0)->maxConsecutiveRejections());
	}

	public function testBlindAndDropped(): void
	{
		$m = new ConsistencyMonitor(1);
		$m->record(new UpdateResult([], 0.0, 1.0));
		$m->recordDropped(Measurement::blind(0));
		self::assertSame(1, $m->blindSteps());
		self::assertSame(1, $m->dropped());
		self::assertNan($m->meanNis());
		self::assertFalse($m->isInconsistent());
		$report = $m->report();
		self::assertSame(1, $report['steps']);
		self::assertArrayHasKey('channels', $report);
	}

	public function testAutocorrelationOfAlternatingSignIsNegative(): void
	{
		$m = new ConsistencyMonitor(1, lags: 3);
		for ($k = 0; $k < 200; $k++) {
			$m->record(self::result($k % 2 === 0 ? 1.0 : -1.0));
		}
		self::assertEqualsWithDelta(-1.0, $m->channel(0)->autocorrelation(1), 1e-2);
		self::assertEqualsWithDelta(1.0, $m->channel(0)->autocorrelation(2), 2e-2);
		self::assertNan($m->channel(0)->autocorrelation(4));
	}
}
