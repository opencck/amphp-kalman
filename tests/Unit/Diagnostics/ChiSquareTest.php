<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Diagnostics;

use OpenCCK\Kalman\Domain\Diagnostics\ChiSquare;
use PHPUnit\Framework\TestCase;

final class ChiSquareTest extends TestCase
{
	public function testNormalQuantileKnownValues(): void
	{
		self::assertEqualsWithDelta(0.0, ChiSquare::normalQuantile(0.5), 1e-12);
		self::assertEqualsWithDelta(1.6448536269514722, ChiSquare::normalQuantile(0.95), 1e-9);
		self::assertEqualsWithDelta(1.959963984540054, ChiSquare::normalQuantile(0.975), 1e-9);
		self::assertEqualsWithDelta(2.5758293035489004, ChiSquare::normalQuantile(0.995), 1e-9);
		self::assertEqualsWithDelta(-2.3263478740408408, ChiSquare::normalQuantile(0.01), 1e-9);
		self::assertEqualsWithDelta(3.090232306167813, ChiSquare::normalQuantile(0.999), 1e-8);
	}

	public function testNormalCdfInvertsQuantile(): void
	{
		foreach ([0.001, 0.05, 0.3, 0.5, 0.8, 0.99] as $p) {
			self::assertEqualsWithDelta($p, ChiSquare::normalCdf(ChiSquare::normalQuantile($p)), 2e-7);
		}
	}

	/** Table from docs/theory.md §8. */
	public function testChiSquareTable(): void
	{
		self::assertEqualsWithDelta(6.635, ChiSquare::quantile(1, 0.99), 5e-3);
		self::assertEqualsWithDelta(10.828, ChiSquare::quantile(1, 0.999), 5e-3);
		self::assertEqualsWithDelta(9.210, ChiSquare::quantile(2, 0.99), 5e-3);
		self::assertEqualsWithDelta(13.816, ChiSquare::quantile(2, 0.999), 5e-3);
		self::assertEqualsWithDelta(13.277, ChiSquare::quantile(4, 0.99), 1e-3);
		self::assertEqualsWithDelta(18.467, ChiSquare::quantile(4, 0.999), 1e-3);
		self::assertEqualsWithDelta(124.342, ChiSquare::quantile(100, 0.95), 1e-2);
		self::assertEqualsWithDelta(7.815, ChiSquare::quantile(3, 0.95), 1e-3);
	}

	public function testMeanBoundsMatchRoadmapExample(): void
	{
		// N = 100, m = 1 → 95 % interval ≈ [0.74, 1.30]
		[$lo, $hi] = ChiSquare::meanBounds(1, 100);
		self::assertEqualsWithDelta(0.74, $lo, 0.01);
		self::assertEqualsWithDelta(1.30, $hi, 0.01);
	}

	public function testSurvivalMatchesQuantile(): void
	{
		foreach ([1, 2, 5, 10] as $df) {
			foreach ([0.9, 0.99] as $p) {
				$x = ChiSquare::quantile($df, $p);
				self::assertEqualsWithDelta(1.0 - $p, ChiSquare::survival($df, $x), $df >= 3 ? 3e-3 : 1e-6);
			}
		}
	}

	public function testLogGamma(): void
	{
		self::assertEqualsWithDelta(\log(24.0), ChiSquare::logGamma(5.0), 1e-10);
		self::assertEqualsWithDelta(0.5 * \log(\M_PI), ChiSquare::logGamma(0.5), 1e-10);
	}
}
