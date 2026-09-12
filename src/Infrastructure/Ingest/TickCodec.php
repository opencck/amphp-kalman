<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;

/**
 * Line formats for history files (backtests, calibration):
 *
 *   JSON lines:  {"ts":1700000000000000000,"values":{"0":100.5,"2":99.8}}
 *   CSV:         1700000000000000000,0:100.5,2:99.8          (channel:value pairs)
 *   CSV dense:   1700000000000000000,100.5,,99.8             (positional, empty = missing)
 *
 * decodeLine() returns the raw array shape used by FilterBatch / stepRaw();
 * decodeMeasurement() wraps it into the value object.
 *
 * Decoded JSON/CSV fields are untyped by definition and validated on the spot.
 *
 * @psalm-suppress MixedAssignment
 */
final class TickCodec
{
	private function __construct()
	{
	}

	/**
	 * @return array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>}
	 */
	public static function decodeLine(string $line): array
	{
		$line = \trim($line);
		if ($line === '') {
			throw new InvalidArgument('Empty tick line');
		}
		if ($line[0] === '{') {
			/** @var mixed $data */
			$data = \json_decode($line, true, 8, \JSON_THROW_ON_ERROR);
			if (!\is_array($data) || !isset($data['ts']) || !isset($data['values']) || !\is_array($data['values'])) {
				throw new InvalidArgument('JSON tick requires "ts" and "values"');
			}
			$values = [];
			foreach ($data['values'] as $ch => $v) {
				if (!\is_numeric($v)) {
					throw new InvalidArgument('Tick value must be numeric');
				}
				$values[(int) $ch] = (float) $v;
			}
			\ksort($values);
			$tick = ['ts' => self::toInt($data['ts']), 'values' => $values];
			if (isset($data['rows']) && \is_array($data['rows'])) {
				$rows = [];
				foreach ($data['rows'] as $ch => $row) {
					if (!\is_array($row)) {
						throw new InvalidArgument('Tick "rows" entries must be arrays');
					}
					$clean = [];
					foreach ($row as $j => $c) {
						if (!\is_numeric($c)) {
							throw new InvalidArgument('Row coefficients must be numeric');
						}
						$clean[(int) $j] = (float) $c;
					}
					\ksort($clean);
					$rows[(int) $ch] = $clean;
				}
				$tick['rows'] = $rows;
			}
			return $tick;
		}
		$parts = \explode(',', $line);
		$ts = self::toInt(\trim($parts[0]));
		$values = [];
		$count = \count($parts);
		for ($i = 1; $i < $count; $i++) {
			$field = \trim($parts[$i]);
			if ($field === '') {
				continue;
			}
			$colon = \strpos($field, ':');
			if ($colon !== false) {
				$ch = (int) \substr($field, 0, $colon);
				$v = \substr($field, $colon + 1);
			} else {
				$ch = $i - 1;
				$v = $field;
			}
			if (!\is_numeric($v)) {
				throw new InvalidArgument(\sprintf('Non-numeric tick value "%s"', $v));
			}
			$values[$ch] = (float) $v;
		}
		return ['ts' => $ts, 'values' => $values];
	}

	public static function decodeMeasurement(string $line): Measurement
	{
		$raw = self::decodeLine($line);
		return Measurement::at($raw['ts'], $raw['values']);
	}

	/**
	 * JSON-lines encoding (one line, no trailing newline). Optional per-tick
	 * observation rows (channel => sparse row) for time-varying H_t.
	 *
	 * @param array<int, array<int, float>>|null $rows
	 */
	public static function encodeLine(Measurement $m, ?array $rows = null): string
	{
		$values = [];
		foreach ($m->values as $ch => $v) {
			$values[(string) $ch] = $v;
		}
		$data = ['ts' => $m->timestampNs, 'values' => $values];
		if ($rows !== null) {
			$encoded = [];
			foreach ($rows as $ch => $row) {
				$r = [];
				foreach ($row as $j => $c) {
					$r[(string) $j] = $c;
				}
				$encoded[(string) $ch] = $r;
			}
			$data['rows'] = $encoded;
		}
		return \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
	}

	/**
	 * Encodes a raw tick array (as produced by decodeLine / used by FilterBatch).
	 *
	 * @param array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>} $tick
	 */
	public static function encodeRaw(array $tick): string
	{
		return self::encodeLine(Measurement::at($tick['ts'], $tick['values']), $tick['rows'] ?? null);
	}

	/**
	 * Compact CSV with channel:value pairs.
	 */
	public static function encodeCsv(Measurement $m): string
	{
		$fields = [(string) $m->timestampNs];
		foreach ($m->values as $ch => $v) {
			$fields[] = $ch . ':' . self::formatFloat($v);
		}
		return \implode(',', $fields);
	}

	private static function formatFloat(float $v): string
	{
		return \rtrim(\rtrim(\sprintf('%.17g', $v), '0'), '.') ?: '0';
	}

	private static function toInt(mixed $v): int
	{
		if (\is_int($v)) {
			return $v;
		}
		if (\is_string($v) && \preg_match('/^-?\d+$/', $v) === 1) {
			return (int) $v;
		}
		if (\is_float($v) && \floor($v) === $v) {
			return (int) $v;
		}
		throw new InvalidArgument('Timestamp must be an integer number of nanoseconds');
	}
}
