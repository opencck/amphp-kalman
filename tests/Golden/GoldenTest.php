<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Golden;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Finance\BidAskBounce;
use OpenCCK\Kalman\Domain\Model\Finance\EtfBasket;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
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
 * §7.6 golden files: a deterministic 1000-step run per catalogue model,
 * final mean / covariance / log-likelihood stored in tests/Golden/*.json.
 * Any change requires an explicit regeneration:  KALMAN_UPDATE_GOLDEN=1 composer test
 * (and a justification in the PR).
 */
final class GoldenTest extends TestCase
{
	private const STEPS = 1000;
	private const TOLERANCE = 1e-9;

	/** @return iterable<string, array{string}> */
	public function models(): iterable
	{
		foreach (\array_keys(self::catalogue()) as $name) {
			yield $name => [$name];
		}
	}

	/** @dataProvider models */
	public function testGolden(string $name): void
	{
		$run = self::catalogue()[$name];
		$actual = $run();
		$path = __DIR__ . '/' . $name . '.json';
		$update = \getenv('KALMAN_UPDATE_GOLDEN') === '1';
		if ($update || !\is_file($path)) {
			\file_put_contents($path, \json_encode($actual, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT) . "\n");
			if (!$update) {
				self::markTestIncomplete("golden file created for $name — re-run to compare");
			}
		}
		/** @var array{x: array<int, float>, P: array<int, float>, ll: float, steps: int} $expected */
		$expected = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
		self::assertSame($expected['steps'], $actual['steps']);
		self::assertLessThan(self::TOLERANCE, Flat::maxAbsDiff($expected['x'], $actual['x']), "$name: mean changed");
		self::assertLessThan(self::TOLERANCE, Flat::maxAbsDiff($expected['P'], $actual['P']), "$name: covariance changed");
		self::assertEqualsWithDelta($expected['ll'], $actual['ll'], self::TOLERANCE * \max(1.0, \abs($expected['ll'])), "$name: log-likelihood changed");
	}

	/**
	 * @return array{x: array<int, float>, P: array<int, float>, ll: float, steps: int}
	 */
	private static function simulate(KalmanFilter $filter, MotionModel $motion, ObservationModel $observation, int $dtNs, int $seed): array
	{
		$sim = new LinearGaussianSimulator($motion, $observation, $filter->mean(), $seed);
		$sim->drawInitialFrom($filter->mean(), $filter->covariance());
		$ts = 0;
		for ($k = 0; $k < self::STEPS; $k++) {
			$ts += $dtNs;
			$sim->advance($dtNs / 1e9);
			$filter->step($sim->observe($ts));
		}
		return self::result($filter);
	}

	/** @return array{x: array<int, float>, P: array<int, float>, ll: float, steps: int} */
	private static function result(KalmanFilter $filter): array
	{
		return ['x' => $filter->mean(), 'P' => $filter->covariance(), 'll' => $filter->logLikelihood(), 'steps' => $filter->steps()];
	}

	/** @return array<string, callable(): array{x: array<int, float>, P: array<int, float>, ll: float, steps: int}> */
	private static function catalogue(): array
	{
		return [
			'local-linear-trend' => static function (): array {
				$m = new LocalLinearTrend(0.5, 0.05, 0.01);
				return self::simulate($m->filter(100.0), $m->motion(), $m->observation(), 100_000_000, 1);
			},
			'etf-basket' => static function (): array {
				$m = new EtfBasket([0.5, 0.3, 0.2], [4e-6, 1e-6, 5e-7, 1e-6, 3e-6, 2e-7, 5e-7, 2e-7, 2e-6], 0.002, 0.05, 0.01, [1e-4, 2e-4, 3e-4], 5e-5);
				return self::simulate($m->filter([100.0, 50.0, 20.0]), $m, $m->observation(), 500_000_000, 2);
			},
			'multi-venue' => static function (): array {
				$m = new MultiVenue(0.01, 60.0, 0.02, [1e-4, 2e-4, 1.5e-4, 3e-4]);
				return self::simulate($m->filter(100.0), $m, $m->observation(), 100_000_000, 3);
			},
			'nelson-siegel' => static function (): array {
				$m = new NelsonSiegel(0.0609, [3.0, 6.0, 12.0, 24.0, 36.0, 60.0, 84.0, 120.0], [0.02, 0.05, 0.1], [5.0, -1.5, 0.5], [0.05, 0.08, 0.15], \array_fill(0, 8, 4e-4));
				return self::simulate($m->filter(), $m->motion(), $m->observation(), 86_400_000_000_000, 4);
			},
			'stochastic-volatility' => static function (): array {
				$m = new StochasticVolatility(-9.0, 0.98, 0.15, 60.0);
				return self::simulate($m->filter(), $m, $m->observation(), 60_000_000_000, 5);
			},
			'bid-ask-bounce' => static function (): array {
				$m = new BidAskBounce(0.02, 0.004, -0.4, 0.5, 0.01);
				return self::simulate($m->filter(50.0), $m, $m->observation(), 500_000_000, 6);
			},
			'pairs-hedge' => static function (): array {
				$pairs = new PairsHedge(1e-6, 1e-8, 0.05, 0.01, 0.1);
				$filter = $pairs->filter(0.5, 1.2, FilterConfig::default()->withForm(FilterForm::UD));
				$rng = new Rng(7);
				$ts = 0;
				$x = 100.0;
				for ($k = 0; $k < self::STEPS; $k++) {
					$ts += 1_000_000_000;
					$x += 0.2 * $rng->normal();
					$pairs->setRegressor($x);
					$filter->step(\OpenCCK\Kalman\Domain\Entity\Measurement::at($ts, [0 => 0.5 + 1.2 * $x + 0.05 * $rng->normal()]));
				}
				return self::result($filter);
			},
			'time-varying-beta' => static function (): array {
				$m = new TimeVaryingBeta(1e-9, 1e-7, 0.01);
				$filter = $m->filter();
				$rng = new Rng(8);
				$ts = 0;
				for ($k = 0; $k < self::STEPS; $k++) {
					$ts += 60_000_000_000;
					$rm = 0.002 * $rng->normal();
					$m->setMarketReturn($rm);
					$filter->step(\OpenCCK\Kalman\Domain\Entity\Measurement::at($ts, [0 => 1.1 * $rm + 0.01 * $rng->normal()]));
				}
				return self::result($filter);
			},
			'microprice' => static function (): array {
				$m = new Microprice(0.01, 0.01, 5e-3);
				$filter = $m->filter(100.0);
				$rng = new Rng(9);
				$ts = 0;
				$p = 100.0;
				for ($k = 0; $k < self::STEPS; $k++) {
					$ts += 200_000_000;
					$p += 0.002 * $rng->normal();
					$depth = $rng->uniformBetween(200.0, 2000.0);
					$filter->step($m->updateBook($ts, $p - 0.005, $p + 0.005, $depth * $rng->uniformBetween(0.3, 0.7), $depth * 0.5));
				}
				return self::result($filter);
			},
		];
	}
}
