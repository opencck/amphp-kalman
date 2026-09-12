<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * A model that can be described by a plain array (scalars and arrays only),
 * so that it can cross a process boundary: worker Tasks, queue messages,
 * snapshot files. Reconstructed through {@see \OpenCCK\Kalman\Domain\Factory\ModelRegistry}.
 *
 * The array MUST contain a 'type' key with the registered type name.
 */
interface SerializableModel
{
	/** Registered short type name, e.g. "constant-velocity". */
	public static function type(): string;

	/** @return array<string, mixed> with key 'type' */
	public function toArray(): array;

	/** @param array<string, mixed> $config */
	public static function fromArray(array $config): static;
}
