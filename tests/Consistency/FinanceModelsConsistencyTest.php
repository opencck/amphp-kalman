<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Consistency;

use OpenCCK\Kalman\Domain\Diagnostics\ConsistencyMonitor;
use OpenCCK\Kalman\Domain\Diagnostics\Nees;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Finance\BidAskBounce;
use OpenCCK\Kalman\Domain\Model\Finance\EtfBasket;
use OpenCCK\Kalman\Domain\Model\Finance\Microprice;
use OpenCCK\Kalman\Domain\Model\Finance\MultiVenue;
use OpenCCK\Kalman\Domain\Model\Finance\NelsonSiegel;
use OpenCCK\Kalman\Domain\Model\Finance\PairsHedge;
use OpenCCK\Kalman\Domain\Model\Finance\StochasticVolatility;
use OpenCCK\Kalman\Domain\Model\Finance\TimeVaryingBeta;
use OpenCCK\Kalman\Tests\Simulation\LinearGaussianSimulator;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * §7.4 for the model catalogue: every model simulated from its own equations,
 * mean NEES ∈ [0.9n, 1.1n], mean NIS ∈ [0.9, 1.1] per channel over 20 000 steps.
 * Catches wrong Q (dt²/2 vs dt³/3), missing dt, transposed F.
 */
final class FinanceModelsConsistencyTest extends TestCase
{
	private const STEPS = 20_000;

	/**
	 * @param callable(): array{0: KalmanFilter, 1: \OpenCCK\Kalman\Domain\Contract\MotionModel, 2: \OpenCCK\Kalman\Domain\Contract\ObservationModel} $build
	 * @param array<int, int>|null $channelsPerStep fixed channel set, or null for a random subset per step
	 */
	private function runStatic(callable $build, int $dtNs, int $seed, ?array $channelsPerStep = null): void
	{
		[$filter, $motion, $observation] = $build();
		$n = $filter->stateSize();
		$sim = new LinearGaussianSimulator($motion, $observation, $filter->mean(), $seed);
		$sim->drawInitialFrom($filter->mean(), $filter->covariance());
		$monitor = new ConsistencyMonitor($observation->channelCount());
		$neesSum = 0.0;
		$ts = 0;
		$m = $observation->channelCount();
		for ($k = 0; $k < self::STEPS; $k++) {
			$ts += $dtNs;
			$sim->advance($dtNs / 1e9);
			$channels = $channelsPerStep;
			if ($channels === null && $m > 1) {
				// asynchronous venues / quotes: random non-empty subset
				$channels = [];
				for ($c = 0; $c < $m; $c++) {
					if ($sim->rng()->uniform() < 0.7) {
						$channels[] = $c;
					}
				}
				if ($channels === []) {
					$channels = [$sim->rng()->int(0, $m - 1)];
				}
			}
			$monitor->record($filter->step($sim->observe($ts, $channels)));
			$neesSum += Nees::compute($sim->truth(), $filter->mean(), $filter->covariance(), $n);
		}
		$nees = $neesSum / self::STEPS;
		self::assertGreaterThan(0.9 * $n, $nees, "NEES $nees (n=$n) too low");
		self::assertLessThan(1.1 * $n, $nees, "NEES $nees (n=$n) too high");
		$nis = $monitor->meanNis();
		self::assertGreaterThan(0.9, $nis, "NIS $nis");
		self::assertLessThan(1.1, $nis, "NIS $nis");
	}

	public function testEtfBasket(): void
	{
		$this->runStatic(static function (): array {
			$etf = new EtfBasket(
				weights: [0.5, 0.3, 0.2],
				sigma: [4e-6, 1e-6, 5e-7, 1e-6, 3e-6, 2e-7, 5e-7, 2e-7, 2e-6],
				sigmaA: 0.002,
				premiumTheta: 0.05,
				premiumSigma: 0.01,
				quoteVariances: [1e-4, 2e-4, 3e-4],
				etfVariance: 5e-5,
			);
			return [$etf->filter([100.0, 50.0, 20.0], velocityPriorStd: 0.01, premiumPriorStd: 0.03), $etf, $etf->observation()];
		}, 500_000_000, 101);
	}

	public function testEtfBasketWithUdForm(): void
	{
		$this->runStatic(static function (): array {
			$etf = new EtfBasket([0.5, 0.5], [4e-6, 1e-6, 1e-6, 3e-6], 0.002, 0.0, 0.01, [1e-4, 2e-4], 5e-5);
			return [$etf->filter([100.0, 50.0], FilterConfig::default()->withForm(FilterForm::UD), 0.01, 0.03), $etf, $etf->observation()];
		}, 500_000_000, 102);
	}

	public function testMultiVenue(): void
	{
		$this->runStatic(static function (): array {
			$mv = new MultiVenue(sigmaA: 0.01, tauDelta: 60.0, sigmaDelta: 0.02, variances: [1e-4, 2e-4, 1.5e-4, 3e-4]);
			return [$mv->filter(100.0, velocityPriorStd: 0.02), $mv, $mv->observation()];
		}, 100_000_000, 103);
	}

	public function testNelsonSiegel(): void
	{
		$this->runStatic(static function (): array {
			$ns = new NelsonSiegel(
				lambda: 0.0609,
				maturities: [3.0, 6.0, 12.0, 24.0, 36.0, 60.0, 84.0, 120.0],
				theta: [0.02, 0.05, 0.1],
				mu: [5.0, -1.5, 0.5],
				sigma: [0.05, 0.08, 0.15],
				variances: \array_fill(0, 8, 4e-4),
			);
			return [$ns->filter(), $ns->motion(), $ns->observation()];
		}, 86_400_000_000_000, 104, [0, 1, 2, 3, 4, 5, 6, 7]);
	}

	public function testStochasticVolatilityWithGaussianProxyNoise(): void
	{
		// Simulated with Gaussian observation noise of variance π²/2: verifies the
		// linear-Gaussian machinery of the model (the real log χ² noise is only QML).
		$this->runStatic(static function (): array {
			$sv = new StochasticVolatility(mu: -9.0, phi: 0.98, sigmaEta: 0.15, barSeconds: 60.0);
			return [$sv->filter(), $sv, $sv->observation()];
		}, 60_000_000_000, 105, [0]);
	}

	public function testBidAskBounceWithUd(): void
	{
		$this->runStatic(static function (): array {
			$model = new BidAskBounce(sigmaA: 0.02, sigmaNu: 0.004, rho: -0.4, tickInterval: 0.5, tick: 0.01);
			return [$model->filter(50.0, velocityPriorStd: 0.02), $model, $model->observation()];
		}, 500_000_000, 106, [0]);
	}

	/**
	 * Regression models (H_t = [1, x_t]) have a weakly observable direction
	 * whose error decorrelates over thousands of steps, so a single-path time
	 * average of NEES is not ergodic. Consistency is therefore checked as an
	 * ENSEMBLE average over independent runs (200 runs × 100 steps), which is
	 * χ²(2)/N-distributed with N = 20 000 effective samples.
	 */
	public function testPairsHedgeWithTimeVaryingRegressor(): void
	{
		$neesSum = 0.0;
		$nisSum = 0.0;
		$count = 0;
		for ($run = 0; $run < 200; $run++) {
			$pairs = new PairsHedge(qAlpha: 1e-6, qBeta: 1e-8, sigmaEps: 0.05);
			$filter = $pairs->filter(0.5, 1.2, alphaPriorStd: 0.1, betaPriorStd: 0.1);
			$rng = new Rng(10_000 + $run);
			$alpha = 0.5 + 0.1 * $rng->normal();     // truth drawn from the prior
			$beta = 1.2 + 0.1 * $rng->normal();
			$ts = 0;
			$x = 100.0;
			for ($k = 0; $k < 100; $k++) {
				$ts += 1_000_000_000;
				$alpha += 1e-3 * $rng->normal();         // √(q_α·dt), dt = 1 s
				$beta += 1e-4 * $rng->normal();
				$x += 0.2 * $rng->normal();              // exogenous regressor path
				$y = $alpha + $beta * $x + 0.05 * $rng->normal();
				$pairs->setRegressor($x);
				$nisSum += $filter->step(Measurement::at($ts, [0 => $y]))->nis();
				$neesSum += Nees::compute([$alpha, $beta], $filter->mean(), $filter->covariance(), 2);
				$count++;
			}
		}
		$nees = $neesSum / $count;
		$nis = $nisSum / $count;
		self::assertGreaterThan(1.8, $nees, "ensemble NEES $nees");
		self::assertLessThan(2.2, $nees, "ensemble NEES $nees");
		self::assertGreaterThan(0.9, $nis, "ensemble NIS $nis");
		self::assertLessThan(1.1, $nis, "ensemble NIS $nis");
	}

	/** Same ensemble protocol as the pairs model (see above). */
	public function testTimeVaryingBeta(): void
	{
		$neesSum = 0.0;
		$count = 0;
		$inBand = 0;
		$runs = 800;
		for ($run = 0; $run < $runs; $run++) {
			$model = new TimeVaryingBeta(qAlpha: 1e-9, qBeta: 1e-7, sigmaIdio: 0.01);
			$filter = $model->filter(0.0, 1.0, alphaPriorStd: 0.001, betaPriorStd: 0.2);
			$rng = new Rng(20_000 + $run);
			$alpha = 0.001 * $rng->normal();
			$beta = 1.0 + 0.2 * $rng->normal();
			$ts = 0;
			for ($k = 0; $k < 40; $k++) {
				$ts += 60_000_000_000;      // 1-minute returns
				$alpha += \sqrt(1e-9 * 60.0) * $rng->normal();
				$beta += \sqrt(1e-7 * 60.0) * $rng->normal();
				$rm = 0.01 * $rng->normal();
				$ra = $alpha + $beta * $rm + 0.01 * $rng->normal();
				$model->setMarketReturn($rm);
				$filter->step(Measurement::at($ts, [0 => $ra]));
				$neesSum += Nees::compute([$alpha, $beta], $filter->mean(), $filter->covariance(), 2);
				$count++;
			}
			[$lo, $hi] = TimeVaryingBeta::betaBand($filter, 2.0);
			if ($beta > $lo && $beta < $hi) {
				$inBand++;
			}
		}
		$nees = $neesSum / $count;
		self::assertGreaterThan(1.8, $nees, "ensemble NEES $nees");
		self::assertLessThan(2.2, $nees, "ensemble NEES $nees");
		// ±2σ band should contain the truth ≈ 95 % of the time
		self::assertGreaterThan(0.90, $inBand / $runs);
	}

	public function testMicropriceWithDepthDependentNoise(): void
	{
		$mp = new Microprice(sigmaA: 0.01, tick: 0.01, depthScale: 5e-3);
		$filter = $mp->filter(100.0, velocityPriorStd: 0.02);
		$rng = new Rng(109);
		$p = 100.0 + \sqrt($filter->variance(0)) * $rng->normal();
		$v = 0.02 * $rng->normal();
		$monitor = new ConsistencyMonitor(2);
		$neesSum = 0.0;
		$ts = 0;
		$dt = 0.2;
		$motion = $mp->motion();
		for ($k = 0; $k < self::STEPS; $k++) {
			$ts += 200_000_000;
			$w = $rng->multivariateNormal($motion->processNoise($dt), 2);
			$p += $dt * $v + $w[0];
			$v += $w[1];
			$depth = $rng->uniformBetween(200.0, 2000.0);
			$half = 0.005;
			$rMid = $half * $half + 1e-4 / 12.0;
			$rMicro = 5e-3 / $depth;
			// synthetic book consistent with the model: mid = p + noise, microprice = p + noise
			$mid = $p + \sqrt($rMid) * $rng->normal();
			$micro = $p + \sqrt($rMicro) * $rng->normal();
			// updateBook sets R from the spread/depth; we then override the values with the simulated ones
			$mp->updateBook($ts, $mid - $half, $mid + $half, $depth / 2, $depth / 2);
			$monitor->record($filter->step(Measurement::at($ts, [Microprice::CHANNEL_MID => $mid, Microprice::CHANNEL_MICRO => $micro])));
			$neesSum += Nees::compute([$p, $v], $filter->mean(), $filter->covariance(), 2);
		}
		$nees = $neesSum / self::STEPS;
		self::assertGreaterThan(1.8, $nees, "NEES $nees");
		self::assertLessThan(2.2, $nees, "NEES $nees");
		self::assertGreaterThan(0.9, $monitor->meanNis());
		self::assertLessThan(1.1, $monitor->meanNis());
	}
}
