<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Contract;

use OpenCCK\Kalman\Domain\Metric\Descriptor\MetricDescriptor;

/**
 * A streaming metric: O(1) per observation, no allocations in the update path
 * (every buffer is sized in the constructor).
 *
 * Every metric has two faces (ROADMAP M1):
 *  - this object, fed observation by observation, for live trading;
 *  - a pure `@deterministic @offloadable` kernel that computes the whole
 *    series at once, for backtests and worker processes.
 * The two MUST agree bit-for-bit; `tests/Unit/Metric/*EquivalenceTest` proves it.
 *
 * `value()` is undefined until `isReady()` returns true — it returns NAN,
 * never a silent zero (ROADMAP M3).
 */
interface Metric
{
	/** Registry key, unique across the library (e.g. "rsi", "obi"). */
	public static function type(): string;

	/** Catalogue card: names, category, kernels, example (ROADMAP §5). */
	public static function describe(): MetricDescriptor;

	/** False while the warm-up window is still filling. */
	public function isReady(): bool;

	/** The primary output, or NAN when not ready. */
	public function value(): float;

	/**
	 * Every output of the metric, keyed by name. Single-valued metrics return
	 * one entry; MACD returns macd/signal/histogram, ADX returns adx/+di/−di/dx.
	 *
	 * @return array<string, float>
	 */
	public function values(): array;

	/** Drops all accumulated state; the metric warms up again from scratch. */
	public function reset(): void;

	/**
	 * Constructor parameters, so the metric can cross a process boundary.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array;

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): self;
}
