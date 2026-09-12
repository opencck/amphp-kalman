<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Model;

use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Factory\ModelRegistry;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Finance\BidAskBounce;
use OpenCCK\Kalman\Domain\Model\Finance\EtfBasket;
use OpenCCK\Kalman\Domain\Model\Finance\IntradayProfile;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Domain\Model\Finance\Microprice;
use OpenCCK\Kalman\Domain\Model\Finance\MultiVenue;
use OpenCCK\Kalman\Domain\Model\Finance\NelsonSiegel;
use OpenCCK\Kalman\Domain\Model\Finance\PairsHedge;
use OpenCCK\Kalman\Domain\Model\Finance\StochasticVolatility;
use OpenCCK\Kalman\Domain\Model\Finance\TimeVaryingBeta;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Generic\HeteroscedasticMotionModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class FinanceModelsTest extends TestCase
{
	/** @return iterable<string, array{SparseMotionModel}> */
	public function sparseFinanceModels(): iterable
	{
		yield 'etf' => [new EtfBasket([0.5, 0.3, 0.2], [4e-6, 1e-6, 5e-7, 1e-6, 3e-6, 2e-7, 5e-7, 2e-7, 2e-6], 0.01, 0.1, 0.02, [1e-4, 2e-4, 3e-4], 5e-5)];
		yield 'pairs' => [new PairsHedge(1e-6, 1e-8, 0.05)];
		yield 'pairs+spread' => [new PairsHedge(1e-6, 1e-8, 0.05, 0.01, 0.1)];
		yield 'multi-venue' => [new MultiVenue(0.05, 30.0, 0.02, [1e-4, 2e-4, 1.5e-4, 3e-4])];
		yield 'beta' => [new TimeVaryingBeta(1e-8, 1e-9, 0.01)];
		yield 'sv' => [new StochasticVolatility(-9.0, 0.98, 0.15, 60.0)];
		yield 'bounce' => [new BidAskBounce(0.05, 0.004, -0.4, 0.5, 0.01)];
	}

	/** @dataProvider sparseFinanceModels */
	public function testSparseKernelMatchesDense(SparseMotionModel $model): void
	{
		$rng = new Rng(800);
		$n = $model->stateSize();
		foreach ([0.05, 0.7, 3.0] as $dt) {
			$P = $rng->spdMatrix($n);
			$x = $rng->vector($n);
			$F = $model->transition($dt);
			$u = $model->control($dt);
			$xe = Flat::matVec($F, $x, $n, $n);
			if ($u !== null) {
				$xe = Flat::add($xe, $u);
			}
			$Pe = Flat::sandwich($F, $P, $n);
			$xs = $x;
			$Ps = $P;
			$model->advanceInPlace($xs, $Ps, $dt);
			self::assertLessThan(1e-12, Flat::maxAbsDiff($xe, $xs), $model::class);
			self::assertLessThan(1e-12 * \max(1.0, Flat::maxAbs($Pe)), Flat::maxAbsDiff($Pe, $Ps), $model::class);
			self::assertTrue(Flat::isSymmetric($Ps, $n), $model::class);
			self::assertTrue(Flat::isSymmetric($model->processNoise($dt), $n), $model::class . ' Q');
		}
	}

	public function testAllFinanceModelsAreRegisteredAndRoundTrip(): void
	{
		$models = [
			new LocalLinearTrend(0.5, 0.05, 0.01),
			new EtfBasket([0.5, 0.5], [1e-6, 2e-7, 2e-7, 1e-6], 0.01, 0.0, 0.02, [1e-4, 1e-4], 5e-5),
			new PairsHedge(1e-6, 1e-8, 0.05, 0.01, 0.1),
			new MultiVenue(0.05, 30.0, 0.02, [1e-4, 2e-4]),
			new TimeVaryingBeta(1e-8, 1e-9, 0.01),
			new StochasticVolatility(-9.0, 0.98, 0.15, 60.0),
			new Microprice(0.05, 0.01, 1e-3),
			new NelsonSiegel(0.0609, [3.0, 12.0, 60.0, 120.0], [0.01, 0.02, 0.05], [5.0, -1.0, 0.5], [0.1, 0.1, 0.2], [1e-4, 1e-4, 1e-4, 1e-4]),
			new BidAskBounce(0.05, 0.004, -0.4, 0.5, 0.01),
		];
		foreach ($models as $model) {
			$array = $model->toArray();
			self::assertSame($model::type(), $array['type']);
			self::assertContains($model::type(), ModelRegistry::types());
			$rebuilt = ModelRegistry::build($array);
			self::assertEquals($model->toArray(), $rebuilt->toArray());
		}
	}

	public function testEtfBasketObservationAndNav(): void
	{
		$etf = new EtfBasket([0.6, 0.4], [1e-6, 2e-7, 2e-7, 1e-6], 0.01, 0.05, 0.02, [1e-4, 1e-4], 5e-5);
		self::assertSame(5, $etf->stateSize());
		self::assertSame(2, $etf->velocityIndex(0));
		self::assertSame(4, $etf->premiumIndex());
		$obs = $etf->observation();
		self::assertSame(3, $obs->channelCount());
		self::assertSame([0 => 0.6, 1 => 0.4, 4 => 1.0], $obs->channelRow(2));
		$filter = $etf->filter([100.0, 50.0]);
		self::assertEqualsWithDelta(80.0, $etf->nav($filter), 1e-12);
		// rebalance: new weights → new observation, premium variance inflated
		$obs2 = $etf->observationFor([0.5, 0.5]);
		self::assertSame([0 => 0.5, 1 => 0.5, 4 => 1.0], $obs2->channelRow(2));
		$before = $filter->variance(4);
		$filter->addVariance([4 => 0.01]);
		self::assertEqualsWithDelta($before + 0.01, $filter->variance(4), 1e-15);
		// split 2:1 on constituent 0
		$filter->scaleState([0 => 0.5, 2 => 0.5]);
		self::assertEqualsWithDelta(50.0, $filter->meanAt(0), 1e-12);
	}

	public function testPairsHedgeRegressorAndZScore(): void
	{
		$pairs = new PairsHedge(1e-6, 1e-8, 0.05);
		$filter = $pairs->filter(0.0, 1.0);
		$pairs->setRegressor(10.0);
		self::assertSame([0 => 1.0, 1 => 10.0], $pairs->observation()->channelRow(0));
		$filter->step(Measurement::at(0, [0 => 10.5]));
		self::assertEqualsWithDelta(0.5 / \sqrt($filter->lastInnovationVariance(0)), PairsHedge::zScore($filter), 1e-12);
		self::assertNull($pairs->spreadHalfLife());
		self::assertEqualsWithDelta(\M_LN2 / 0.01, (new PairsHedge(1e-6, 1e-8, 0.05, 0.01, 0.1))->spreadHalfLife() ?? 0.0, 1e-9);
	}

	public function testStochasticVolatilityObservationTransform(): void
	{
		$z = StochasticVolatility::observe(0.01);
		self::assertEqualsWithDelta(\log(1e-4) + 1.2704, $z, 1e-7);
		self::assertTrue(\is_finite(StochasticVolatility::observe(0.0))); // floor prevents log(0)
		$sv = new StochasticVolatility(-9.0, 0.98, 0.15, 60.0);
		self::assertEqualsWithDelta(0.98, $sv->transition(60.0)[0], 1e-12);
		self::assertEqualsWithDelta(0.98 ** 0.5, $sv->transition(30.0)[0], 1e-12);
		$filter = $sv->filter();
		self::assertEqualsWithDelta(\exp(-4.5), StochasticVolatility::volatility($filter), 1e-12);
	}

	public function testMicropriceBookUpdate(): void
	{
		$mp = new Microprice(0.05, 0.01, 1e-3);
		self::assertEqualsWithDelta(100.0 + 0.01 * 0.75, Microprice::microprice(100.0, 100.01, 300.0, 100.0), 1e-12);
		$m = $mp->updateBook(1, 100.0, 100.02, 500.0, 500.0);
		self::assertEqualsWithDelta(100.01, $m->value(Microprice::CHANNEL_MID), 1e-12);
		self::assertEqualsWithDelta(100.01, $m->value(Microprice::CHANNEL_MICRO), 1e-12);
		self::assertEqualsWithDelta(1e-3 / 1000.0, $mp->observation()->channelVariance(1), 1e-15);
		self::assertEqualsWithDelta(1e-4 + 1e-4 / 12.0, $mp->observation()->channelVariance(0), 1e-15);
		$filter = $mp->filter(100.0);
		$filter->step($m);
		self::assertCount(2, $filter->lastChannels());
	}

	public function testNelsonSiegelLoadings(): void
	{
		$ns = new NelsonSiegel(0.0609, [3.0, 120.0], [0.01, 0.02, 0.05], [5.0, -1.0, 0.5], [0.1, 0.1, 0.2], [1e-4, 1e-4]);
		[$l, $s, $c] = $ns->loadings(3.0);
		self::assertSame(1.0, $l);
		self::assertGreaterThan(0.9, $s);          // short maturity: slope loading → 1
		self::assertLessThan(0.1, $c);             // curvature small at both ends
		[, $s2] = $ns->loadings(120.0);
		self::assertLessThan(0.15, $s2);           // long maturity: slope loading → 0
		$filter = $ns->filter();
		self::assertSame(3, $filter->stateSize());
		self::assertEqualsWithDelta(5.0 + $s * (-1.0) + $c * 0.5, $ns->yield($filter, 3.0), 1e-12);
	}

	public function testHeteroscedasticScalesQ(): void
	{
		$inner = new ConstantVelocity(0.5);
		$h = new HeteroscedasticMotionModel($inner);
		$h->setScales([0 => 100.0]);
		$h->setMultiplier(2.0);
		$Q = $inner->processNoise(0.1);
		$Qh = $h->processNoise(0.1);
		self::assertEqualsWithDelta($Q[0] * 100.0 * 100.0 * 2.0, $Qh[0], 1e-15);
		self::assertEqualsWithDelta($Q[1] * 100.0 * 2.0, $Qh[1], 1e-15);
		self::assertEqualsWithDelta($Q[3] * 2.0, $Qh[3], 1e-15);
		self::assertTrue(Flat::isSymmetric($Qh, 2));
		self::assertSame($inner->transition(0.1), $h->transition(0.1));
	}

	public function testIntradayProfile(): void
	{
		$profile = IntradayProfile::equityUShape();
		$open = 9 * 3600 + 30 * 60;
		$close = 16 * 3600;
		$mid = \intdiv($open + $close, 2);
		self::assertGreaterThan($profile->multiplierAtSecond($mid), $profile->multiplierAtSecond($open));
		self::assertGreaterThan($profile->multiplierAtSecond($mid), $profile->multiplierAtSecond($close));
		// normalised: mean over the session ≈ 1
		$sum = 0.0;
		for ($t = $open; $t < $close; $t += 60) {
			$sum += $profile->multiplierAtSecond($t);
		}
		self::assertEqualsWithDelta(1.0, $sum / (($close - $open) / 60), 0.01);
		// timestamp path (UTC offset 0): 10:00 UTC
		$ts = (10 * 3600) * 1_000_000_000;
		self::assertEqualsWithDelta($profile->multiplierAtSecond(10 * 3600), $profile->multiplier($ts), 1e-15);
	}
}
