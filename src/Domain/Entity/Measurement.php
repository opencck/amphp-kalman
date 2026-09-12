<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * One observation event: a subset of channels observed at an exchange
 * timestamp. Missing channels are simply absent keys — the filter skips
 * them (sequential form, §2.7). A blind measurement carries no channels
 * and produces a pure prediction step.
 *
 * Timestamps are int nanoseconds since the Unix epoch and MUST be exchange
 * time, never receive time; the optional receivedNs exists for latency
 * monitoring only.
 */
final readonly class Measurement
{
	/**
	 * @param array<int, float> $values channel => value
	 */
	private function __construct(
		public array $values,
		public int $timestampNs,
		public ?int $receivedNs = null,
	) {
	}

	/**
	 * @param array<int, float|int> $values channel => value
	 */
	public static function at(int $timestampNs, array $values, ?int $receivedNs = null): self
	{
		$clean = [];
		foreach ($values as $channel => $value) {
			if ($channel < 0) {
				throw new InvalidArgument(\sprintf('Channel index must be >= 0, got %d', $channel));
			}
			$v = (float) $value;
			if (!\is_finite($v)) {
				throw new InvalidArgument(\sprintf('Channel %d value is not finite', $channel));
			}
			$clean[$channel] = $v;
		}
		return new self($clean, $timestampNs, $receivedNs);
	}

	/** Prediction-only step (no channels observed). */
	public static function blind(int $timestampNs): self
	{
		return new self([], $timestampNs, null);
	}

	public function isBlind(): bool
	{
		return $this->values === [];
	}

	public function has(int $channel): bool
	{
		return \array_key_exists($channel, $this->values);
	}

	public function value(int $channel): float
	{
		if (!\array_key_exists($channel, $this->values)) {
			throw new InvalidArgument(\sprintf('Channel %d is not present in this measurement', $channel));
		}
		return $this->values[$channel];
	}

	/** @return list<int> */
	public function channels(): array
	{
		return \array_keys($this->values);
	}

	public function channelCount(): int
	{
		return \count($this->values);
	}

	/** Receive-minus-exchange latency in nanoseconds, or null when unknown. */
	public function latencyNs(): ?int
	{
		return $this->receivedNs === null ? null : $this->receivedNs - $this->timestampNs;
	}

	public function withTimestamp(int $timestampNs): self
	{
		return new self($this->values, $timestampNs, $this->receivedNs);
	}

	/** @return array{ts: int, values: array<int, float>, received?: int} */
	public function toArray(): array
	{
		$out = ['ts' => $this->timestampNs, 'values' => $this->values];
		if ($this->receivedNs !== null) {
			$out['received'] = $this->receivedNs;
		}
		return $out;
	}

	/** @param array{ts: int, values: array<int, float|int>, received?: int|null} $data */
	public static function fromArray(array $data): self
	{
		return self::at($data['ts'], $data['values'], $data['received'] ?? null);
	}
}
