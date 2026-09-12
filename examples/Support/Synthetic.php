<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Examples\Support;

use OpenCCK\Kalman\Domain\Entity\Bar;
use OpenCCK\Kalman\Domain\Entity\OrderBook;
use OpenCCK\Kalman\Domain\Entity\Trade;
use OpenCCK\Kalman\Domain\Entity\TradeSide;

/**
 * Deterministic synthetic market data for the examples.
 *
 * Every generator seeds `mt_srand` explicitly, so two runs print the same
 * numbers and an example can be used as a rough regression check. Nothing here
 * pretends to be a realistic market simulator — the point is to produce data
 * with a *known* property (a trend of exactly this slope, a book with exactly
 * this much size inside ten basis points) so that an example can show an
 * indicator recovering it.
 */
final class Synthetic
{
	private function __construct()
	{
	}

	/** Uniform noise in [−1, 1] with a standard deviation of 1/√3. */
	private static function noise(): float
	{
		return \mt_rand(0, 2_000_000) / 1_000_000.0 - 1.0;
	}

	/**
	 * A random walk with an optional drift per step.
	 *
	 * @return list<float>
	 */
	public static function prices(int $count, float $start = 100.0, float $volatility = 0.2, float $drift = 0.0, int $seed = 7): array
	{
		\mt_srand($seed);
		$out = [];
		$price = $start;
		for ($i = 0; $i < $count; $i++) {
			$price += $drift + self::noise() * $volatility;
			$out[] = $price;
		}
		return $out;
	}

	/**
	 * A price path that trends up, then chops sideways, then trends down —
	 * so one series exercises both the trend and the flat regime.
	 *
	 * @return list<float>
	 */
	public static function regimes(int $count, float $start = 100.0, float $volatility = 0.2, int $seed = 11): array
	{
		\mt_srand($seed);
		$out = [];
		$price = $start;
		$third = \intdiv($count, 3);
		for ($i = 0; $i < $count; $i++) {
			$drift = $i < $third ? 0.08 : ($i < 2 * $third ? 0.0 : -0.08);
			$price += $drift + self::noise() * $volatility;
			$out[] = $price;
		}
		return $out;
	}

	/**
	 * Evenly spaced exchange timestamps in nanoseconds.
	 *
	 * @return list<int>
	 */
	public static function timestamps(int $count, float $intervalSeconds = 1.0, int $startNs = 1_700_000_000_000_000_000): array
	{
		$step = (int) ($intervalSeconds * 1e9);
		$out = [];
		for ($i = 0; $i < $count; $i++) {
			$out[] = $startNs + $i * $step;
		}
		return $out;
	}

	/**
	 * OHLCV bars built from an intrabar path, so the highs and lows are real
	 * extremes rather than decorations around the close.
	 *
	 * @return list<Bar>
	 */
	public static function bars(int $count, float $start = 100.0, float $volatility = 0.01, float $drift = 0.0, int $seed = 13, int $stepsPerBar = 20): array
	{
		\mt_srand($seed);
		$bars = [];
		$price = $start;
		$ns = 1_700_000_000_000_000_000;
		$minute = 60_000_000_000;
		$perStep = $volatility / \sqrt((float) $stepsPerBar) * \M_SQRT3;
		for ($i = 0; $i < $count; $i++) {
			$open = $price;
			$high = $open;
			$low = $open;
			$logReturn = 0.0;
			for ($k = 0; $k < $stepsPerBar; $k++) {
				$logReturn += $drift / $stepsPerBar + self::noise() * $perStep;
				$x = $open * \exp($logReturn);
				if ($x > $high) {
					$high = $x;
				}
				if ($x < $low) {
					$low = $x;
				}
			}
			$close = $open * \exp($logReturn);
			$volume = 100.0 + \abs(self::noise()) * 400.0;
			$bars[] = Bar::of($ns, $ns + $minute - 1, $open, $high, $low, $close, $volume, $stepsPerBar);
			$ns += $minute;
			$price = $close;
		}
		return $bars;
	}

	/**
	 * A trade tape with a controllable buyer share: 0.5 is balanced flow,
	 * 0.9 is the one-sided flow that makes a market maker nervous.
	 *
	 * @return list<Trade>
	 */
	public static function trades(int $count, float $start = 100.0, float $buyShare = 0.5, float $tick = 0.01, int $seed = 17): array
	{
		\mt_srand($seed);
		$out = [];
		$price = $start;
		$ns = 1_700_000_000_000_000_000;
		for ($i = 0; $i < $count; $i++) {
			$isBuy = \mt_rand(0, 1_000_000) / 1_000_000.0 < $buyShare;
			$price += ($isBuy ? 1 : -1) * $tick * \abs(self::noise());
			$size = 0.1 + \abs(self::noise()) * 2.0;
			$out[] = Trade::at($ns, $price, $size, $isBuy ? TradeSide::Buy : TradeSide::Sell);
			$ns += 250_000_000;
		}
		return $out;
	}

	/**
	 * A book with `levels` levels a tick apart on each side and linearly
	 * growing size, optionally tilted: `tilt` multiplies every bid size, so
	 * 3.0 gives an imbalance of exactly +0.5 at any depth.
	 */
	public static function book(
		float $mid = 100.0,
		int $levels = 20,
		float $tick = 0.01,
		float $tilt = 1.0,
		int $timestampNs = 1_700_000_000_000_000_000,
	): OrderBook {
		$bids = [];
		$asks = [];
		for ($i = 0; $i < $levels; $i++) {
			$size = 1.0 + $i;
			$bids[] = [\round($mid - $tick * ($i + 1), 6), $size * $tilt];
			$asks[] = [\round($mid + $tick * ($i + 1), 6), $size];
		}
		return OrderBook::of($timestampNs, $bids, $asks);
	}

	/**
	 * A sequence of books whose mid follows the given prices, so a book metric
	 * can be watched over time.
	 *
	 * @param list<float> $mids
	 * @return list<OrderBook>
	 */
	public static function bookStream(array $mids, int $levels = 10, float $tick = 0.01, int $seed = 19): array
	{
		\mt_srand($seed);
		$out = [];
		$ns = 1_700_000_000_000_000_000;
		foreach ($mids as $mid) {
			$tilt = 1.0 + self::noise() * 0.5;
			$out[] = self::book($mid, $levels, $tick, $tilt > 0.1 ? $tilt : 0.1, $ns);
			$ns += 100_000_000;
		}
		return $out;
	}

	/**
	 * Formats a float for a terminal column, printing a dash for NAN.
	 *
	 * The dash is padded by character count, not by `str_pad`, which counts
	 * bytes: the em-dash is three of them in UTF-8, so `str_pad` would leave
	 * the NAN column two characters short of every other row.
	 *
	 * The same trap waits one level up. Passing the result through
	 * `printf('%12s', …)` pads by bytes again, so a NAN cell inside a wider
	 * field still lands short. Either size the field to `decimals + 4` so no
	 * padding happens, or pad it yourself with `mb_strlen()`; several examples
	 * do the latter and say so where they do it.
	 */
	public static function fmt(float $value, int $decimals = 4): string
	{
		if (!\is_nan($value)) {
			return \number_format($value, $decimals, '.', '');
		}
		$width = $decimals + 4;
		return \str_repeat(' ', \max($width - 1, 0)) . '—';
	}

	/** Prints a heading the examples share, so their output looks the same. */
	public static function heading(string $title, string $subtitle = ''): void
	{
		echo "\n", $title, "\n", \str_repeat('=', \mb_strlen($title)), "\n";
		if ($subtitle !== '') {
			echo $subtitle, "\n";
		}
		echo "\n";
	}
}
