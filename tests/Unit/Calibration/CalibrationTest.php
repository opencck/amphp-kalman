<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Calibration;

use OpenCCK\Kalman\App\Calibration\Calibrator;
use OpenCCK\Kalman\App\Calibration\InnovationLikelihood;
use OpenCCK\Kalman\App\Calibration\NelderMead;
use OpenCCK\Kalman\App\Calibration\Parametrization;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Tests\Simulation\LinearGaussianSimulator;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

final class CalibrationTest extends TestCase
{
	public function testParametrizationRoundTripsLogLinearAndCholesky(): void
	{
		$p = new Parametrization(['motion.sigmaA' => 'log', 'x0.1' => 'linear', 'motion.sigma' => 'cholesky:2']);
		self::assertSame(1 + 1 + 3, $p->dimension());
		$config = ['motion' => ['sigmaA' => 0.5, 'sigma' => [2.0, 0.3, 0.3, 1.0]], 'x0' => [1.0, -2.0]];
		$theta = $p->toTheta($config);
		self::assertEqualsWithDelta(\log(0.5), $theta[0], 1e-15);
		self::assertSame(-2.0, $theta[1]);
		$back = $p->apply($config, $theta);
		self::assertIsArray($back['motion']);
		self::assertEqualsWithDelta(0.5, $back['motion']['sigmaA'], 1e-14);
		self::assertIsArray($back['motion']['sigma']);
		self::assertLessThan(1e-12, Flat::maxAbsDiff([2.0, 0.3, 0.3, 1.0], $back['motion']['sigma']));
		self::assertEquals($p, Parametrization::fromArray($p->toArray()));
		// any theta produces a valid (positive / SPD) config
		$weird = $p->apply($config, [-30.0, 5.0, 3.0, -2.0, 1.5]);
		self::assertIsArray($weird['motion']);
		self::assertGreaterThan(0.0, $weird['motion']['sigmaA']);
		self::assertIsArray($weird['motion']['sigma']);
		self::assertTrue(\OpenCCK\Kalman\Domain\Linalg\Cholesky::isPositiveDefinite($weird['motion']['sigma'], 2));
	}

	public function testParametrizationRejectsUnknownPathAndTransform(): void
	{
		$this->expectException(InvalidArgument::class);
		(new Parametrization(['motion.nope' => 'log']))->toTheta(['motion' => ['sigmaA' => 1.0]]);
	}

	public function testNelderMeadMinimisesRosenbrockWithBatchEvaluator(): void
	{
		$batches = 0;
		$evaluate = static function (array $points) use (&$batches): array {
			$batches++;
			$out = [];
			foreach ($points as [$x, $y]) {
				$out[] = (1.0 - $x) ** 2 + 100.0 * ($y - $x * $x) ** 2;
			}
			return $out;
		};
		$result = (new NelderMead(tolerance: 1e-10, maxIterations: 5000))->minimize($evaluate, [-1.2, 1.0], [0.5, 0.5]);
		self::assertTrue($result['converged']);
		self::assertEqualsWithDelta(1.0, $result['x'][0], 1e-4);
		self::assertEqualsWithDelta(1.0, $result['x'][1], 1e-4);
		self::assertLessThan(1e-8, $result['f']);
		self::assertGreaterThan(0, $batches);
		// 3 for the simplex, then one batch of 4 per completed iteration (+2 per shrink)
		self::assertGreaterThanOrEqual(3 + 4 * ($result['iterations'] - 1), $result['evaluations']);
		self::assertSame($batches, 1 + ($result['evaluations'] - 3 - 4 * ($result['iterations'] - 1)) / 2 + ($result['iterations'] - 1));
	}

	public function testLikelihoodPeaksNearTrueParameters(): void
	{
		$truth = new LocalLinearTrend(sigmaA: 0.5, halfSpread: 0.05);
		$ticks = self::simulate($truth, 3000, seed: 51);
		$config = self::config(0.5, 0.05);
		$atTruth = InnovationLikelihood::evaluate($config, null, $ticks);
		$tooSmooth = InnovationLikelihood::evaluate(self::config(0.05, 0.05), null, $ticks);
		$tooNoisy = InnovationLikelihood::evaluate(self::config(5.0, 0.05), null, $ticks);
		$wrongR = InnovationLikelihood::evaluate(self::config(0.5, 0.5), null, $ticks);
		self::assertGreaterThan($tooSmooth, $atTruth);
		self::assertGreaterThan($tooNoisy, $atTruth);
		self::assertGreaterThan($wrongR, $atTruth);
	}

	public function testCalibratorRecoversSigmaAAndR(): void
	{
		$truth = new LocalLinearTrend(sigmaA: 0.5, halfSpread: 0.05);
		$ticks = self::simulate($truth, 4000, seed: 52);
		$start = self::config(2.0, 0.2);   // 4× off on both
		$calibrator = new Calibrator(
			new Parametrization(['motion.sigmaA' => 'log', 'observation.variances.0' => 'log']),
			new NelderMead(tolerance: 1e-6, maxIterations: 200),
		);
		$result = $calibrator->calibrate($start, null, $ticks);
		self::assertIsArray($result['config']['motion']);
		self::assertIsArray($result['config']['observation']);
		$sigmaA = $result['config']['motion']['sigmaA'];
		$r = $result['config']['observation']['variances'][0];
		self::assertEqualsWithDelta(0.5, $sigmaA, 0.15, "sigmaA $sigmaA");
		self::assertEqualsWithDelta(0.0025, $r, 0.0006, "r $r");
		self::assertGreaterThan(InnovationLikelihood::evaluate($start, null, $ticks), $result['logLikelihood']);
	}

	/** @return array<string, mixed> */
	public static function config(float $sigmaA, float $halfSpread): array
	{
		return [
			'motion' => ['type' => 'constant-velocity', 'sigmaA' => $sigmaA],
			'observation' => ['type' => 'static-observation', 'rows' => [[0 => 1.0]], 'variances' => [$halfSpread * $halfSpread]],
			'x0' => [100.0, 0.0],
			'P0' => [0.01, 0.0, 0.0, 1.0],
		];
	}

	/** @return array<int, array{ts: int, values: array<int, float>}> */
	public static function simulate(LocalLinearTrend $model, int $steps, int $seed): array
	{
		$sim = new LinearGaussianSimulator($model->motion(), $model->observation(), [100.0, 0.0], $seed);
		$sim->drawInitialFrom([100.0, 0.0], [0.01, 0.0, 0.0, 1.0]);
		$rng = new Rng($seed + 1);
		$ticks = [];
		$ts = 0;
		for ($k = 0; $k < $steps; $k++) {
			$dtNs = $rng->int(50_000_000, 150_000_000);
			$ts += $dtNs;
			$sim->advance($dtNs / 1e9);
			$m = $sim->observe($ts);
			$ticks[] = ['ts' => $ts, 'values' => $m->values];
		}
		return $ticks;
	}
}
