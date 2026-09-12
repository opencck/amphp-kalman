<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Output;

use OpenCCK\Kalman\Domain\Entity\StateSnapshot;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * JSON encoding of snapshots for the wire and disk. Optional instrument
 * label; `compact` drops the full covariance and keeps the diagonal.
 */
final class SnapshotSerializer
{
	public function __construct(private readonly ?string $instrument = null, private readonly bool $compact = false)
	{
	}

	public function encode(StateSnapshot $snapshot): string
	{
		$data = $this->compact ? $snapshot->toCompactArray() : $snapshot->toArray();
		if ($this->instrument !== null) {
			$data = ['instrument' => $this->instrument] + $data;
		}
		return \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
	}

	public function decode(string $json): StateSnapshot
	{
		/** @var mixed $data */
		$data = \json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
		if (!\is_array($data) || !isset($data['n'], $data['x'], $data['P'])) {
			throw new InvalidArgument('Not a full snapshot payload (compact snapshots cannot be decoded)');
		}
		/** @var array{n: int, x: array<int, float|int>, P: array<int, float|int>, ts?: int|null, ll?: float, steps?: int} $data */
		return StateSnapshot::fromArray($data);
	}

	public function instrument(): ?string
	{
		return $this->instrument;
	}
}
