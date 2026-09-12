<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Factory;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Typed, validating access to plain config arrays (model / filter
 * descriptions that crossed a process boundary as JSON or IPC payloads).
 *
 * Reading an untyped config value into a local is a mixed assignment by
 * definition — narrowing it is exactly what this class does, one validated
 * accessor at a time.
 *
 * @psalm-suppress MixedAssignment
 */
final class ConfigReader
{
	private function __construct()
	{
	}

	/** @param array<string, mixed> $config */
	public static function float(array $config, string $key, ?float $default = null): float
	{
		if (!\array_key_exists($key, $config) || $config[$key] === null) {
			if ($default === null) {
				throw new InvalidArgument(\sprintf('Config key "%s" is required', $key));
			}
			return $default;
		}
		$v = $config[$key];
		if (\is_float($v)) {
			return $v;
		}
		if (\is_int($v)) {
			return (float) $v;
		}
		if (\is_string($v) && \is_numeric($v)) {
			return (float) $v;
		}
		throw new InvalidArgument(\sprintf('Config key "%s" must be numeric', $key));
	}

	/** @param array<string, mixed> $config */
	public static function int(array $config, string $key, ?int $default = null): int
	{
		if (!\array_key_exists($key, $config) || $config[$key] === null) {
			if ($default === null) {
				throw new InvalidArgument(\sprintf('Config key "%s" is required', $key));
			}
			return $default;
		}
		$v = $config[$key];
		if (\is_int($v)) {
			return $v;
		}
		if (\is_float($v) && \floor($v) === $v) {
			return (int) $v;
		}
		if (\is_string($v) && \preg_match('/^-?\d+$/', $v) === 1) {
			return (int) $v;
		}
		throw new InvalidArgument(\sprintf('Config key "%s" must be an integer', $key));
	}

	/** @param array<string, mixed> $config */
	public static function string(array $config, string $key, ?string $default = null): string
	{
		if (!\array_key_exists($key, $config) || $config[$key] === null) {
			if ($default === null) {
				throw new InvalidArgument(\sprintf('Config key "%s" is required', $key));
			}
			return $default;
		}
		$v = $config[$key];
		if (!\is_string($v)) {
			throw new InvalidArgument(\sprintf('Config key "%s" must be a string', $key));
		}
		return $v;
	}

	/** @param array<string, mixed> $config */
	public static function bool(array $config, string $key, ?bool $default = null): bool
	{
		if (!\array_key_exists($key, $config) || $config[$key] === null) {
			if ($default === null) {
				throw new InvalidArgument(\sprintf('Config key "%s" is required', $key));
			}
			return $default;
		}
		$v = $config[$key];
		if (\is_bool($v)) {
			return $v;
		}
		if (\is_int($v)) {
			return $v !== 0;
		}
		throw new InvalidArgument(\sprintf('Config key "%s" must be a boolean', $key));
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<int, float>
	 */
	public static function floatList(array $config, string $key, ?int $expectedCount = null): array
	{
		if (!\array_key_exists($key, $config) || !\is_array($config[$key])) {
			throw new InvalidArgument(\sprintf('Config key "%s" must be an array of numbers', $key));
		}
		$out = [];
		foreach ($config[$key] as $v) {
			if (\is_int($v) || \is_float($v)) {
				$out[] = (float) $v;
			} elseif (\is_string($v) && \is_numeric($v)) {
				$out[] = (float) $v;
			} else {
				throw new InvalidArgument(\sprintf('Config key "%s" contains a non-numeric element', $key));
			}
		}
		if ($expectedCount !== null && \count($out) !== $expectedCount) {
			throw new InvalidArgument(\sprintf('Config key "%s" must have %d elements, got %d', $key, $expectedCount, \count($out)));
		}
		return $out;
	}

	/**
	 * Optional float list: null when the key is absent.
	 *
	 * @param array<string, mixed> $config
	 * @return array<int, float>|null
	 */
	public static function optionalFloatList(array $config, string $key): ?array
	{
		if (!\array_key_exists($key, $config) || $config[$key] === null) {
			return null;
		}
		return self::floatList($config, $key);
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<int, string>
	 */
	public static function stringList(array $config, string $key): array
	{
		if (!\array_key_exists($key, $config) || !\is_array($config[$key])) {
			throw new InvalidArgument(\sprintf('Config key "%s" must be an array of strings', $key));
		}
		$out = [];
		foreach ($config[$key] as $v) {
			if (!\is_string($v)) {
				throw new InvalidArgument(\sprintf('Config key "%s" contains a non-string element', $key));
			}
			$out[] = $v;
		}
		return $out;
	}

	/**
	 * Sub-array (nested config).
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public static function map(array $config, string $key): array
	{
		if (!\array_key_exists($key, $config) || !\is_array($config[$key])) {
			throw new InvalidArgument(\sprintf('Config key "%s" must be an array', $key));
		}
		/** @var array<string, mixed> $sub */
		$sub = $config[$key];
		return $sub;
	}

	/**
	 * List of sparse rows: [[stateIndex => coefficient, ...], ...].
	 *
	 * @param array<string, mixed> $config
	 * @return array<int, array<int, float>>
	 */
	public static function sparseRows(array $config, string $key): array
	{
		if (!\array_key_exists($key, $config) || !\is_array($config[$key])) {
			throw new InvalidArgument(\sprintf('Config key "%s" must be an array of rows', $key));
		}
		$rows = [];
		foreach ($config[$key] as $row) {
			if (!\is_array($row)) {
				throw new InvalidArgument(\sprintf('Config key "%s": each row must be an array', $key));
			}
			$clean = [];
			foreach ($row as $j => $c) {
				if (!\is_int($j) || !(\is_int($c) || \is_float($c))) {
					throw new InvalidArgument(\sprintf('Config key "%s": rows must map int => number', $key));
				}
				$clean[$j] = (float) $c;
			}
			$rows[] = $clean;
		}
		return $rows;
	}
}
