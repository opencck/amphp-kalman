<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference;

use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Linalg\Flat;
use OpenCCK\Kalman\Domain\Model\Observation\StaticObservation;
use OpenCCK\Kalman\Tests\Support\RandomLinearModel;
use OpenCCK\Kalman\Tests\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * §7.2: every production form == textbook filter on 100 random steps with
 * random F, Q, H, diagonal R. Absolute tolerance 1e-9.
 */
final class SequentialVsNaiveTest extends TestCase
{
	/** @return iterable<string, array{int, int, int, FilterForm}> */
	public function cases(): iterable
	{
		foreach (self::availableForms() as $form) {
			foreach ([[1, 1], [2, 1], [3, 2], [5, 3], [8, 4], [12, 6]] as [$n, $m]) {
				yield "{$form->value} n=$n m=$m" => [$n, $m, 100, $form];
			}
		}
	}

	/** @return list<FilterForm> */
	public static function availableForms(): array
	{
		$forms = [];
		foreach (FilterForm::cases() as $form) {
			$class = match ($form) {
				FilterForm::Sequential => \OpenCCK\Kalman\Domain\Covariance\DenseSequential::class,
				FilterForm::Joseph => \OpenCCK\Kalman\Domain\Covariance\Joseph::class,
				FilterForm::UD => \OpenCCK\Kalman\Domain\Covariance\UD::class,
				FilterForm::SquareRoot => \OpenCCK\Kalman\Domain\Covariance\SquareRoot::class,
			};
			if (\class_exists($class)) {
				$forms[] = $form;
			}
		}
		return $forms;
	}

	/** @dataProvider cases */
	public function testMatchesNaiveOnRandomModel(int $n, int $m, int $steps, FilterForm $form): void
	{
		$rng = new Rng(1000 * $n + $m);
		$model = new RandomLinearModel($rng, $n, $m);
		$obs = new StaticObservation($model->rows(), $model->variances());

		$x0 = $rng->vector($n);
		$P0 = $rng->spdMatrix($n);

		$fast = new KalmanFilter($model, $obs, $x0, $P0, FilterConfig::default()->withForm($form)->withMatrixCache(false));
		$naive = new NaiveKalmanFilter($x0, NaiveKalmanFilter::fromFlat($P0, $n, $n));

		$H = NaiveKalmanFilter::fromFlat($model->denseH(), $m, $n);
		$Rdiag = $model->variances();

		for ($k = 0; $k < $steps; $k++) {
			$dt = $rng->uniformBetween(0.1, 1.0);
			$fast->predict($dt);
			$naive->predict(
				NaiveKalmanFilter::fromFlat($model->transition($dt), $n, $n),
				NaiveKalmanFilter::fromFlat($model->processNoise($dt), $n, $n),
				$model->control($dt),
			);

			// observe a random non-empty subset of channels
			$channels = [];
			for ($c = 0; $c < $m; $c++) {
				if ($rng->uniform() < 0.75) {
					$channels[] = $c;
				}
			}
			if ($channels === []) {
				$channels = [$rng->int(0, $m - 1)];
			}

			$values = [];
			$Hsub = [];
			$Rsub = [];
			$zsub = [];
			foreach ($channels as $i => $c) {
				$z = $rng->normal() * 3.0;
				$values[$c] = $z;
				$Hsub[] = $H[$c];
				$row = \array_fill(0, \count($channels), 0.0);
				$row[$i] = $Rdiag[$c];
				$Rsub[] = $row;
				$zsub[] = $z;
			}

			$fast->correct(Measurement::at($k, $values));
			$naive->correct($Hsub, $Rsub, $zsub);

			self::assertLessThan(1e-9, Flat::maxAbsDiff($naive->x, $fast->mean()), "mean diverged at step $k");
			self::assertLessThan(1e-9, Flat::maxAbsDiff(NaiveKalmanFilter::toFlat($naive->P), $fast->covariance()), "covariance diverged at step $k");
		}

		self::assertEqualsWithDelta($naive->logLikelihood, $fast->logLikelihood(), 1e-7 * \max(1.0, \abs($naive->logLikelihood)));
	}
}
