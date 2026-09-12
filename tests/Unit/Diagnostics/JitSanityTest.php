<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Unit\Diagnostics;

use OpenCCK\Kalman\Domain\Diagnostics\JitSanity;
use OpenCCK\Kalman\Domain\Discretization\ClosedForm;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use PHPUnit\Framework\TestCase;

/**
 * §8 / ADR-007: the engine the tests run on must not miscompile the kernel
 * shapes the library uses. engineBugs() may be non-empty on PHP 8.2/8.3 with
 * any JIT mode — those shapes are avoided by the library and only reported.
 */
final class JitSanityTest extends TestCase
{
	public function testLibraryShapesAreCorrectOnThisEngine(): void
	{
		$context = 'jit=' . (string) \ini_get('opcache.jit') . ' PHP ' . \PHP_VERSION;
		self::assertSame([], JitSanity::failures(), $context);
		self::assertTrue(JitSanity::passes());
		JitSanity::verify();
		// informational: PHP 8.2/8.3 report the two known shapes here; the library avoids them
		self::assertLessThanOrEqual(3, \count(JitSanity::engineBugs()));
	}

	public function testConstantVelocityQMatchesClosedFormBitForBit(): void
	{
		$cv = new ConstantVelocity(1.5);
		foreach ([0.1, 0.5, 1.0, 1.0 / 3.0, 60.0] as $dt) {
			$Q = $cv->processNoise($dt);
			$ref = ClosedForm::constantVelocity(1.5, $dt)['Q'];
			self::assertSame($ref, $Q);
			self::assertSame($Q[1], $Q[2]);
			self::assertEqualsWithDelta(2.25 * 0.5 * $dt * $dt, $Q[1], 1e-15 * (1.0 + $dt * $dt));
		}
	}
}
