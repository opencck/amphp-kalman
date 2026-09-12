<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Ingest;

use Amp\ByteStream\Compression\DecompressingReadableStream;
use Amp\ByteStream\ReadableStream;
use Amp\File;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use function Amp\ByteStream\splitLines;

/**
 * §5.11 streams a tick history file line by line without blocking the loop
 * (Amp\File + splitLines). `.gz` files are decompressed on the fly.
 */
final class HistoryReader
{
	private function __construct()
	{
	}

	/**
	 * @return \Generator<int, array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>}>
	 */
	public static function stream(string $path): \Generator
	{
		$file = File\openFile($path, 'r');
		$stream = self::wrap($file, $path);
		try {
			foreach (splitLines($stream) as $line) {
				if (\trim($line) === '' || $line[0] === '#') {
					continue;
				}
				yield TickCodec::decodeLine($line);
			}
		} finally {
			$file->close();
		}
	}

	/**
	 * @return \Generator<int, Measurement>
	 */
	public static function measurements(string $path): \Generator
	{
		foreach (self::stream($path) as $raw) {
			yield Measurement::at($raw['ts'], $raw['values']);
		}
	}

	/**
	 * Reads the whole file into memory (small histories, tests).
	 *
	 * @return array<int, array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>}>
	 */
	public static function readAll(string $path): array
	{
		$out = [];
		foreach (self::stream($path) as $tick) {
			$out[] = $tick;
		}
		return $out;
	}

	/**
	 * Writes measurements or raw ticks (with optional per-tick rows) as JSON
	 * lines (gzip when the path ends in .gz).
	 *
	 * @param iterable<Measurement|array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>}> $measurements
	 */
	public static function write(string $path, iterable $measurements): int
	{
		$lines = [];
		$count = 0;
		foreach ($measurements as $m) {
			$lines[] = $m instanceof Measurement ? TickCodec::encodeLine($m) : TickCodec::encodeRaw($m);
			$count++;
		}
		$content = \implode("\n", $lines) . "\n";
		if (\str_ends_with($path, '.gz')) {
			$encoded = \gzencode($content);
			if ($encoded === false) {
				throw new \RuntimeException('gzencode failed');
			}
			$content = $encoded;
		}
		File\write($path, $content);
		return $count;
	}

	private static function wrap(ReadableStream $stream, string $path): ReadableStream
	{
		if (\str_ends_with($path, '.gz')) {
			return new DecompressingReadableStream($stream, \ZLIB_ENCODING_GZIP);
		}
		return $stream;
	}
}
