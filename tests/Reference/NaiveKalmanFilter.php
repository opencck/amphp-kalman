<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Tests\Reference;

/**
 * Textbook Kalman filter, equations (1)–(7) of docs/theory.md §3, with nested
 * arrays, an explicit matrix inverse (Gauss–Jordan) and the unstable
 * form P = (I − KH)P. Deliberately slow and naive: it is the oracle every
 * production form is compared against on short random runs (BRIEF §5).
 */
final class NaiveKalmanFilter
{
	/** @var array<int, float> */
	public array $x;

	/** @var array<int, array<int, float>> */
	public array $P;

	public float $logLikelihood = 0.0;

	/**
	 * @param array<int, float> $x0
	 * @param array<int, array<int, float>> $P0
	 */
	public function __construct(array $x0, array $P0)
	{
		$this->x = $x0;
		$this->P = $P0;
	}

	/**
	 * @param array<int, array<int, float>> $F
	 * @param array<int, array<int, float>> $Q
	 * @param array<int, float>|null $u
	 */
	public function predict(array $F, array $Q, ?array $u = null): void
	{
		$this->x = self::matVec($F, $this->x);
		if ($u !== null) {
			foreach ($u as $i => $v) {
				$this->x[$i] += $v;
			}
		}
		$this->P = self::add(self::mul(self::mul($F, $this->P), self::transpose($F)), $Q);
	}

	/**
	 * Block update with all given channels at once.
	 *
	 * @param array<int, array<int, float>> $H m×n
	 * @param array<int, array<int, float>> $R m×m
	 * @param array<int, float> $z m
	 */
	public function correct(array $H, array $R, array $z): void
	{
		$m = \count($z);
		$n = \count($this->x);
		$Hx = self::matVec($H, $this->x);
		$y = [];
		for ($i = 0; $i < $m; $i++) {
			$y[] = $z[$i] - $Hx[$i];
		}
		$PHt = self::mul($this->P, self::transpose($H));
		$S = self::add(self::mul($H, $PHt), $R);
		$Sinv = self::inverse($S);
		$K = self::mul($PHt, $Sinv);
		$Ky = self::matVec($K, $y);
		for ($i = 0; $i < $n; $i++) {
			$this->x[$i] += $Ky[$i];
		}
		$I = self::identity($n);
		$IKH = self::sub($I, self::mul($K, $H));
		$this->P = self::mul($IKH, $this->P);

		$this->logLikelihood -= 0.5 * (self::dot($y, self::matVec($Sinv, $y)) + \log(self::determinant($S)) + $m * \log(2.0 * \M_PI));
	}

	// ───────────────────────────────────────────────── nested-array helpers

	/**
	 * @param array<int, array<int, float>> $A
	 * @param array<int, array<int, float>> $B
	 * @return array<int, array<int, float>>
	 */
	public static function mul(array $A, array $B): array
	{
		$n = \count($A);
		$k = \count($B);
		$m = \count($B[0]);
		$C = [];
		for ($i = 0; $i < $n; $i++) {
			$row = [];
			for ($j = 0; $j < $m; $j++) {
				$s = 0.0;
				for ($p = 0; $p < $k; $p++) {
					$s += $A[$i][$p] * $B[$p][$j];
				}
				$row[] = $s;
			}
			$C[] = $row;
		}
		return $C;
	}

	/**
	 * @param array<int, array<int, float>> $A
	 * @param array<int, float> $x
	 * @return array<int, float>
	 */
	public static function matVec(array $A, array $x): array
	{
		$y = [];
		foreach ($A as $row) {
			$s = 0.0;
			foreach ($row as $j => $v) {
				$s += $v * $x[$j];
			}
			$y[] = $s;
		}
		return $y;
	}

	/**
	 * @param array<int, array<int, float>> $A
	 * @return array<int, array<int, float>>
	 */
	public static function transpose(array $A): array
	{
		$n = \count($A);
		$m = \count($A[0]);
		$T = [];
		for ($j = 0; $j < $m; $j++) {
			$row = [];
			for ($i = 0; $i < $n; $i++) {
				$row[] = $A[$i][$j];
			}
			$T[] = $row;
		}
		return $T;
	}

	/**
	 * @param array<int, array<int, float>> $A
	 * @param array<int, array<int, float>> $B
	 * @return array<int, array<int, float>>
	 */
	public static function add(array $A, array $B): array
	{
		foreach ($A as $i => $row) {
			foreach ($row as $j => $v) {
				$A[$i][$j] = $v + $B[$i][$j];
			}
		}
		return $A;
	}

	/**
	 * @param array<int, array<int, float>> $A
	 * @param array<int, array<int, float>> $B
	 * @return array<int, array<int, float>>
	 */
	public static function sub(array $A, array $B): array
	{
		foreach ($A as $i => $row) {
			foreach ($row as $j => $v) {
				$A[$i][$j] = $v - $B[$i][$j];
			}
		}
		return $A;
	}

	/** @return array<int, array<int, float>> */
	public static function identity(int $n): array
	{
		$I = [];
		for ($i = 0; $i < $n; $i++) {
			$row = \array_fill(0, $n, 0.0);
			$row[$i] = 1.0;
			$I[] = $row;
		}
		return $I;
	}

	/**
	 * @param array<int, float> $a
	 * @param array<int, float> $b
	 */
	public static function dot(array $a, array $b): float
	{
		$s = 0.0;
		foreach ($a as $i => $v) {
			$s += $v * $b[$i];
		}
		return $s;
	}

	/**
	 * Gauss–Jordan inverse with partial pivoting.
	 *
	 * @param array<int, array<int, float>> $A
	 * @return array<int, array<int, float>>
	 */
	public static function inverse(array $A): array
	{
		$n = \count($A);
		$M = [];
		for ($i = 0; $i < $n; $i++) {
			$row = $A[$i];
			for ($j = 0; $j < $n; $j++) {
				$row[] = $i === $j ? 1.0 : 0.0;
			}
			$M[] = $row;
		}
		for ($col = 0; $col < $n; $col++) {
			$pivot = $col;
			for ($r = $col + 1; $r < $n; $r++) {
				if (\abs($M[$r][$col]) > \abs($M[$pivot][$col])) {
					$pivot = $r;
				}
			}
			if ($pivot !== $col) {
				[$M[$col], $M[$pivot]] = [$M[$pivot], $M[$col]];
			}
			$p = $M[$col][$col];
			if ($p === 0.0) {
				throw new \RuntimeException('Singular matrix');
			}
			for ($j = 0; $j < 2 * $n; $j++) {
				$M[$col][$j] /= $p;
			}
			for ($r = 0; $r < $n; $r++) {
				if ($r === $col) {
					continue;
				}
				$f = $M[$r][$col];
				if ($f === 0.0) {
					continue;
				}
				for ($j = 0; $j < 2 * $n; $j++) {
					$M[$r][$j] -= $f * $M[$col][$j];
				}
			}
		}
		$inv = [];
		for ($i = 0; $i < $n; $i++) {
			$inv[] = \array_slice($M[$i], $n);
		}
		return $inv;
	}

	/**
	 * Determinant via LU with partial pivoting.
	 *
	 * @param array<int, array<int, float>> $A
	 */
	public static function determinant(array $A): float
	{
		$n = \count($A);
		$det = 1.0;
		for ($col = 0; $col < $n; $col++) {
			$pivot = $col;
			for ($r = $col + 1; $r < $n; $r++) {
				if (\abs($A[$r][$col]) > \abs($A[$pivot][$col])) {
					$pivot = $r;
				}
			}
			if ($pivot !== $col) {
				[$A[$col], $A[$pivot]] = [$A[$pivot], $A[$col]];
				$det = -$det;
			}
			$p = $A[$col][$col];
			if ($p === 0.0) {
				return 0.0;
			}
			$det *= $p;
			for ($r = $col + 1; $r < $n; $r++) {
				$f = $A[$r][$col] / $p;
				for ($j = $col; $j < $n; $j++) {
					$A[$r][$j] -= $f * $A[$col][$j];
				}
			}
		}
		return $det;
	}

	/**
	 * @param array<int, float> $flat
	 * @return array<int, array<int, float>>
	 */
	public static function fromFlat(array $flat, int $rows, int $cols): array
	{
		$out = [];
		for ($i = 0; $i < $rows; $i++) {
			$out[] = \array_slice($flat, $i * $cols, $cols);
		}
		return $out;
	}

	/**
	 * @param array<int, array<int, float>> $nested
	 * @return array<int, float>
	 */
	public static function toFlat(array $nested): array
	{
		$out = [];
		foreach ($nested as $row) {
			foreach ($row as $v) {
				$out[] = $v;
			}
		}
		return $out;
	}
}
