<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Filter;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Exception\OutOfSequenceMeasurement;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Generic\RandomWalk;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use PHPUnit\Framework\TestCase;

final class KalmanFilterTest extends TestCase
{
	private function randomWalkFilter(float $sigma = 1.0, float $r = 0.25, ?FilterConfig $config = null): KalmanFilter
	{
		return new KalmanFilter(
			new RandomWalk($sigma),
			new StaticObservation([[0 => 1.0]], [$r]),
			[0.0],
			[1.0],
			$config,
		);
	}

	public function testPredictGrowsVarianceByQ(): void
	{
		$f = $this->randomWalkFilter(sigma: 2.0);
		$f->predict(0.5);
		self::assertEqualsWithDelta(1.0 + 4.0 * 0.5, $f->variance(0), 1e-15);
		$f->predict(0.0); // no-op
		self::assertEqualsWithDelta(3.0, $f->variance(0), 1e-15);
	}

	public function testScalarCorrectMatchesClosedForm(): void
	{
		// P⁻ = 1, r = 0.25 → K = 1/1.25 = 0.8, P⁺ = 1 − 0.8 = 0.2
		$f = $this->randomWalkFilter(r: 0.25);
		$result = $f->correct(Measurement::at(0, [0 => 2.0]));
		self::assertEqualsWithDelta(1.6, $f->meanAt(0), 1e-15);
		self::assertEqualsWithDelta(0.2, $f->variance(0), 1e-15);
		self::assertCount(1, $result->outcomes);
		$o = $result->outcomes[0];
		self::assertSame(2.0, $o->innovation);
		self::assertSame(1.25, $o->innovationVariance);
		self::assertSame(1.0, $o->weight);
		self::assertEqualsWithDelta(4.0 / 1.25, $result->nis(), 1e-15);
		$expectedLl = -0.5 * (4.0 / 1.25 + \log(1.25) + \log(2 * \M_PI));
		self::assertEqualsWithDelta($expectedLl, $result->logLikelihood, 1e-14);
		self::assertEqualsWithDelta($expectedLl, $f->logLikelihood(), 1e-14);
	}

	public function testStepDerivesDtFromTimestamps(): void
	{
		$f = $this->randomWalkFilter(sigma: 1.0, r: 1e6); // huge r → correction ≈ 0
		$r1 = $f->step(Measurement::at(1_000_000_000, [0 => 0.0]));
		self::assertSame(0.0, $r1->dt);
		$r2 = $f->step(Measurement::at(3_000_000_000, [0 => 0.0]));
		self::assertSame(2.0, $r2->dt);
		self::assertSame(2.0, $f->lastDt());
		self::assertSame(3_000_000_000, $f->lastTimestampNs());
		// P grew by Q = σ²·dt = 2 (minus a negligible correction)
		self::assertEqualsWithDelta(3.0, $f->variance(0), 1e-4);
	}

	public function testOutOfSequenceThrows(): void
	{
		$f = $this->randomWalkFilter();
		$f->step(Measurement::at(2_000, [0 => 0.0]));
		$this->expectException(OutOfSequenceMeasurement::class);
		$f->step(Measurement::at(1_000, [0 => 0.0]));
	}

	public function testSameTimestampCorrectsWithoutPredict(): void
	{
		$f = $this->randomWalkFilter(r: 1.0);
		$f->step(Measurement::at(5, [0 => 0.0]));
		$before = $f->variance(0);
		$r = $f->step(Measurement::at(5, [0 => 0.0]));
		self::assertSame(0.0, $r->dt);
		self::assertLessThan($before, $f->variance(0));
	}

	public function testBlindStepOnlyPredicts(): void
	{
		$f = $this->randomWalkFilter(sigma: 1.0);
		$f->step(Measurement::at(0, [0 => 0.0]));
		$v = $f->variance(0);
		$r = $f->step(Measurement::blind(1_000_000_000));
		self::assertTrue($r->isBlind());
		self::assertSame(0, $r->acceptedCount());
		self::assertEqualsWithDelta($v + 1.0, $f->variance(0), 1e-15);
	}

	public function testMissingChannelIsSkipped(): void
	{
		$obs = new StaticObservation([[0 => 1.0], [1 => 1.0]], [0.1, 0.1]);
		$f = new KalmanFilter(new ConstantVelocity(1.0), $obs, [0.0, 0.0], [1.0, 0.0, 0.0, 1.0]);
		$r = $f->correct(Measurement::at(0, [1 => 0.5]));
		self::assertCount(1, $r->outcomes);
		self::assertSame(1, $r->outcomes[0]->channel);
		self::assertSame(1.0, $f->variance(0));      // untouched
		self::assertLessThan(1.0, $f->variance(1));
		self::assertSame(-1.0, $f->lastWeight(0));
		self::assertSame(1.0, $f->lastWeight(1));
		self::assertSame([1], $f->lastChannels());
	}

	public function testGateRejectsOutlierWithoutChangingState(): void
	{
		$f = $this->randomWalkFilter(r: 0.25, config: FilterConfig::default()->withGating(GatingPolicy::sigma(3.0)));
		$snapshotBefore = $f->snapshot();
		$r = $f->correct(Measurement::at(0, [0 => 100.0]));   // d² = 10000/1.25 ≫ 9
		self::assertSame(0.0, $r->outcomes[0]->weight);
		self::assertTrue($r->outcomes[0]->rejected());
		self::assertSame(1, $r->rejectedCount());
		self::assertSame(0.0, $r->logLikelihood);
		self::assertSame($snapshotBefore->mean, $f->mean());
		self::assertSame($snapshotBefore->covariance, $f->covariance());
		self::assertSame(0.0, $f->lastWeight(0));
	}

	public function testHuberDownWeightsLargeInnovation(): void
	{
		$plain = $this->randomWalkFilter(r: 0.25);
		$robust = $this->randomWalkFilter(r: 0.25, config: FilterConfig::default()->withGating(GatingPolicy::huber(1.345)));
		$plain->correct(Measurement::at(0, [0 => 10.0]));
		$r = $robust->correct(Measurement::at(0, [0 => 10.0]));
		$w = $r->outcomes[0]->weight;
		self::assertGreaterThan(0.0, $w);
		self::assertLessThan(1.0, $w);
		// robust estimate moves less toward the outlier
		self::assertLessThan($plain->meanAt(0), $robust->meanAt(0));
		// and keeps more uncertainty
		self::assertGreaterThan($plain->variance(0), $robust->variance(0));
	}

	public function testSnapshotRoundTrip(): void
	{
		$f = new KalmanFilter(
			new ConstantVelocity(0.5),
			new StaticObservation([[0 => 1.0]], [0.04]),
			[100.0, 0.0],
			[1.0, 0.0, 0.0, 1.0],
		);
		for ($k = 0; $k < 20; $k++) {
			$f->step(Measurement::at($k * 100_000_000, [0 => 100.0 + 0.1 * $k]));
		}
		$snap = $f->snapshot();
		self::assertSame(2, $snap->size);
		self::assertSame(20, $snap->steps);
		self::assertSame(19 * 100_000_000, $snap->timestampNs);

		$g = KalmanFilter::fromSnapshot($f->motion(), $f->observation(), $snap);
		self::assertSame($f->mean(), $g->mean());
		self::assertSame($f->covariance(), $g->covariance());
		self::assertSame($f->lastTimestampNs(), $g->lastTimestampNs());
		self::assertSame($f->logLikelihood(), $g->logLikelihood());

		// both continue identically
		$m = Measurement::at(25 * 100_000_000, [0 => 102.7]);
		$f->step($m);
		$g->step($m);
		self::assertSame($f->mean(), $g->mean());
		self::assertSame($f->covariance(), $g->covariance());
	}

	public function testResetClearsClockAndLikelihood(): void
	{
		$f = $this->randomWalkFilter();
		$f->step(Measurement::at(10, [0 => 1.0]));
		$f->reset([5.0], [2.0]);
		self::assertSame([5.0], $f->mean());
		self::assertSame(2.0, $f->variance(0));
		self::assertNull($f->lastTimestampNs());
		self::assertSame(0.0, $f->logLikelihood());
		self::assertSame(0, $f->steps());
	}

	public function testRejectsIndefiniteInitialCovariance(): void
	{
		$this->expectException(InvalidArgument::class);
		new KalmanFilter(new RandomWalk(1.0), new StaticObservation([[0 => 1.0]], [1.0]), [0.0], [-1.0]);
	}

	public function testRejectsNegativeDt(): void
	{
		$this->expectException(InvalidArgument::class);
		$this->randomWalkFilter()->predict(-1.0);
	}

	public function testRejectsUnknownChannel(): void
	{
		$this->expectException(InvalidArgument::class);
		$this->randomWalkFilter()->correct(Measurement::at(0, [3 => 1.0]));
	}

	public function testStepRawMatchesStep(): void
	{
		$a = $this->randomWalkFilter(sigma: 0.3, r: 0.1);
		$b = $this->randomWalkFilter(sigma: 0.3, r: 0.1);
		for ($k = 0; $k < 50; $k++) {
			$ts = $k * 10_000_000;
			$z = \sin($k / 5.0);
			$a->step(Measurement::at($ts, [0 => $z]));
			$b->stepRaw($ts, [0 => $z]);
		}
		self::assertSame($a->mean(), $b->mean());
		self::assertSame($a->covariance(), $b->covariance());
		self::assertSame($a->logLikelihood(), $b->logLikelihood());
		self::assertSame($a->lastInnovation(0), $b->lastInnovation(0));
		self::assertSame($a->lastInnovationVariance(0), $b->lastInnovationVariance(0));
	}

	public function testMatrixCacheDoesNotChangeResults(): void
	{
		$make = static fn (bool $cache): KalmanFilter => new KalmanFilter(
			new ConstantVelocity(0.7),
			new StaticObservation([[0 => 1.0]], [0.05]),
			[0.0, 0.0],
			[1.0, 0.0, 0.0, 1.0],
			FilterConfig::default()->withMatrixCache($cache),
		);
		$cached = $make(true);
		$plain = $make(false);
		$dts = [100, 250, 100, 100, 250, 333, 100];
		$ts = 0;
		foreach ($dts as $k => $ms) {
			$ts += $ms * 1_000_000;
			$m = Measurement::at($ts, [0 => (float) $k]);
			$cached->step($m);
			$plain->step($m);
		}
		self::assertSame($plain->mean(), $cached->mean());
		self::assertSame($plain->covariance(), $cached->covariance());
	}
}
