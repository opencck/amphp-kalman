<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Diagnostics;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * χ² quantiles for gates and consistency bounds, without ext-stats.
 *
 *  - df = 1: exact via the normal quantile,  χ²₁(p) = Φ⁻¹((1+p)/2)².
 *  - df = 2: exact,  χ²₂(p) = −2·ln(1−p).
 *  - df ≥ 3: Wilson–Hilferty approximation, relative error < 1 % for df ≥ 3
 *            and < 0.1 % for df ≥ 10 — adequate for monitoring bounds.
 *
 * The normal quantile is Acklam's rational approximation (relative error
 * 1.15e-9) refined by one Halley step against erfc.
 */
final class ChiSquare
{
	private function __construct()
	{
	}

	public static function quantile(int $df, float $p): float
	{
		if ($df < 1) {
			throw new InvalidArgument('Degrees of freedom must be >= 1');
		}
		if ($p <= 0.0 || $p >= 1.0) {
			throw new InvalidArgument('Probability must be in (0, 1)');
		}
		if ($df === 1) {
			$z = self::normalQuantile(0.5 * (1.0 + $p));
			return $z * $z;
		}
		if ($df === 2) {
			return -2.0 * \log(1.0 - $p);
		}
		// Wilson–Hilferty starting point, then Newton on the survival function
		$z = self::normalQuantile($p);
		$k = 2.0 / (9.0 * $df);
		$t = 1.0 - $k + $z * \sqrt($k);
		$x = $df * $t * $t * $t;
		if ($x <= 0.0) {
			$x = 0.5;
		}
		$target = 1.0 - $p;
		$a = 0.5 * $df;
		$logNorm = -$a * \log(2.0) - self::logGamma($a);
		for ($i = 0; $i < 50; $i++) {
			$f = self::survival($df, $x) - $target;
			$pdf = \exp($logNorm + ($a - 1.0) * \log($x) - 0.5 * $x);
			if ($pdf <= 0.0) {
				break;
			}
			$step = $f / $pdf;   // survival' = −pdf → x_new = x + f/pdf
			$xNew = $x + $step;
			if ($xNew <= 0.0) {
				$xNew = 0.5 * $x;
			}
			if (\abs($xNew - $x) < 1e-12 * \max(1.0, $x)) {
				$x = $xNew;
				break;
			}
			$x = $xNew;
		}
		return $x;
	}

	/**
	 * Two-sided (1−α) interval for the MEAN of N i.i.d. χ²(df) variables,
	 * i.e. for a rolling-average NIS/NEES: [χ²_{N·df}(α/2), χ²_{N·df}(1−α/2)] / N.
	 *
	 * @return array{0: float, 1: float}
	 */
	public static function meanBounds(int $df, int $samples, float $alpha = 0.05): array
	{
		if ($samples < 1) {
			throw new InvalidArgument('Sample count must be >= 1');
		}
		$total = $df * $samples;
		return [
			self::quantile($total, 0.5 * $alpha) / $samples,
			self::quantile($total, 1.0 - 0.5 * $alpha) / $samples,
		];
	}

	/** Φ⁻¹(p): inverse standard normal CDF. */
	public static function normalQuantile(float $p): float
	{
		if ($p <= 0.0 || $p >= 1.0) {
			throw new InvalidArgument('Probability must be in (0, 1)');
		}

		$a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
			1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
		$b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
			6.680131188771972e+01, -1.328068155288572e+01];
		$c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
			-2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
		$d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
			3.754408661907416e+00];

		$pLow = 0.02425;
		$pHigh = 1.0 - $pLow;

		if ($p < $pLow) {
			$q = \sqrt(-2.0 * \log($p));
			$x = ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
				/ (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
		} elseif ($p <= $pHigh) {
			$q = $p - 0.5;
			$r = $q * $q;
			$x = ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
				/ ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1.0);
		} else {
			$q = \sqrt(-2.0 * \log(1.0 - $p));
			$x = -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
				/ (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
		}

		// One Halley refinement step: e = Φ(x) − p, u = e·√(2π)·exp(x²/2)
		$e = 0.5 * self::erfc(-$x / \M_SQRT2) - $p;
		$u = $e * \sqrt(2.0 * \M_PI) * \exp($x * $x / 2.0);
		$x -= $u / (1.0 + $x * $u / 2.0);

		return $x;
	}

	/** Φ(x): standard normal CDF. */
	public static function normalCdf(float $x): float
	{
		return 0.5 * self::erfc(-$x / \M_SQRT2);
	}

	/**
	 * Complementary error function, W. J. Cody's rational Chebyshev
	 * approximation as used in Numerical Recipes (erfccheb), |ε| < 1.2e-7
	 * relative; sufficient for the refinement step above and for p-values.
	 */
	public static function erfc(float $x): float
	{
		$z = $x < 0.0 ? -$x : $x;
		$t = 2.0 / (2.0 + $z);
		$ty = 4.0 * $t - 2.0;
		$cof = [-1.3026537197817094, 6.4196979235649026e-1, 1.9476473204185836e-2,
			-9.561514786808631e-3, -9.46595344482036e-4, 3.66839497852761e-4,
			4.2523324806907e-5, -2.0278578112534e-5, -1.624290004647e-6,
			1.303655835580e-6, 1.5626441722e-8, -8.5238095915e-8,
			6.529054439e-9, 5.059343495e-9, -9.91364156e-10,
			-2.27365122e-10, 9.6467911e-11, 2.394038e-12,
			-6.886027e-12, 8.94487e-13, 3.13092e-13,
			-1.12708e-13, 3.81e-16, 7.106e-15,
			-1.523e-15, -9.4e-17, 1.21e-16, -2.8e-17];
		$d = 0.0;
		$dd = 0.0;
		for ($j = \count($cof) - 1; $j > 0; $j--) {
			$tmp = $d;
			$d = $ty * $d - $dd + $cof[$j];
			$dd = $tmp;
		}
		$res = $t * \exp(-$z * $z + 0.5 * ($cof[0] + $ty * $d) - $dd);
		return $x >= 0.0 ? $res : 2.0 - $res;
	}

	/**
	 * Upper-tail probability P(χ²(df) > x) via the regularised incomplete
	 * gamma function Q(df/2, x/2) (series / continued fraction).
	 */
	public static function survival(int $df, float $x): float
	{
		if ($x <= 0.0) {
			return 1.0;
		}
		$a = 0.5 * $df;
		$z = 0.5 * $x;
		if ($z < $a + 1.0) {
			// series for P(a, z)
			$ap = $a;
			$sum = 1.0 / $a;
			$del = $sum;
			for ($i = 0; $i < 500; $i++) {
				$ap += 1.0;
				$del *= $z / $ap;
				$sum += $del;
				if (\abs($del) < \abs($sum) * 1e-15) {
					break;
				}
			}
			return 1.0 - $sum * \exp(-$z + $a * \log($z) - self::logGamma($a));
		}
		// continued fraction for Q(a, z) (Lentz)
		$b = $z + 1.0 - $a;
		$c = 1.0 / 1e-300;
		$d = 1.0 / $b;
		$h = $d;
		for ($i = 1; $i < 500; $i++) {
			$an = -$i * ($i - $a);
			$b += 2.0;
			$d = $an * $d + $b;
			if (\abs($d) < 1e-300) {
				$d = 1e-300;
			}
			$c = $b + $an / $c;
			if (\abs($c) < 1e-300) {
				$c = 1e-300;
			}
			$d = 1.0 / $d;
			$del = $d * $c;
			$h *= $del;
			if (\abs($del - 1.0) < 1e-15) {
				break;
			}
		}
		return \exp(-$z + $a * \log($z) - self::logGamma($a)) * $h;
	}

	/** ln Γ(x), Lanczos approximation (g = 7, n = 9). */
	public static function logGamma(float $x): float
	{
		$g = [0.99999999999980993, 676.5203681218851, -1259.1392167224028,
			771.32342877765313, -176.61502916214059, 12.507343278686905,
			-0.13857109526572012, 9.9843695780195716e-6, 1.5056327351493116e-7];
		if ($x < 0.5) {
			return \log(\M_PI / \sin(\M_PI * $x)) - self::logGamma(1.0 - $x);
		}
		$x -= 1.0;
		$a = $g[0];
		$t = $x + 7.5;
		for ($i = 1; $i < 9; $i++) {
			$a += $g[$i] / ($x + $i);
		}
		return 0.5 * \log(2.0 * \M_PI) + ($x + 0.5) * \log($t) - $t + \log($a);
	}
}
