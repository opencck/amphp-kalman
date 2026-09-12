<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Async;

use Amp\File;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Domain\Smoothing\FilterTrajectory;
use OpenCCK\Kalman\Domain\Smoothing\RauchTungStriebel;
use OpenCCK\Kalman\Infrastructure\Output\TrajectoryFile;

final class TrajectoryFileTest extends AsyncTestCase
{
	public function testSpillAndReloadMatchesInMemoryTrajectory(): void
	{
		$path = \sys_get_temp_dir() . '/kalman-trajectory-' . \bin2hex(\random_bytes(4)) . '.bin';
		$llt = new LocalLinearTrend(0.5, 0.05);
		$filter = $llt->filter(100.0);
		$memory = new FilterTrajectory(2, 8);
		$disk = new TrajectoryFile($path, 2);
		$disk->open();
		$filter->setRecorder($memory);
		$ts = 0;
		$states = [];
		for ($k = 0; $k < 50; $k++) {
			$ts += 100_000_000;
			$filter->step(Measurement::at($ts, [0 => 100.0 + 0.01 * $k]));
			$states[] = [$memory->dt($k), $memory->priorMean($k), $memory->priorCovariance($k), $memory->posteriorMean($k), $memory->posteriorCovariance($k), $memory->timestampNs($k)];
		}
		foreach ($states as [$dt, $xp, $Pp, $x, $P, $t]) {
			$disk->recordPrior($dt, $xp, $Pp);
			$disk->recordPosterior($x, $P, $t);
		}
		$disk->close();
		try {
			self::assertSame(50, $disk->count());
			self::assertSame(50, $disk->countOnDisk());
			$window = $disk->load(10, 30);
			self::assertSame(20, $window->count());
			self::assertSame($memory->posteriorMean(10), $window->posteriorMean(0));
			self::assertSame($memory->priorCovariance(29), $window->priorCovariance(19));
			self::assertSame($memory->timestampNs(29), $window->timestampNs(19));
			$all = $disk->load(0, 50);
			$a = RauchTungStriebel::smooth($memory, $llt->motion());
			$b = RauchTungStriebel::smooth($all, $llt->motion());
			self::assertSame($a['mean'], $b['mean']);
		} finally {
			File\deleteFile($path);
		}
	}
}
