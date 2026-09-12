<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Output;

use Amp\File;
use OpenCCK\Kalman\Domain\Contract\StepRecorder;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Smoothing\FilterTrajectory;

/**
 * §2.12 disk spill for long trajectories: fixed-size binary records
 * (little-endian doubles: dt, ts, x⁻, P⁻, x, P) appended through Amp\File,
 * random access by step index for backward passes that do not fit in RAM.
 * load(from, to) rebuilds an in-memory FilterTrajectory for a window.
 */
final class TrajectoryFile implements StepRecorder
{
	private int $recordBytes;
	private int $count = 0;
	private ?File\File $handle = null;
	private string $pendingPrior = '';

	public function __construct(private readonly string $path, private readonly int $n)
	{
		if ($n < 1) {
			throw new InvalidArgument('n must be >= 1');
		}
		// dt(1) + ts(1) + x⁻(n) + P⁻(n²) + x(n) + P(n²), 8 bytes each
		$this->recordBytes = 8 * (2 + 2 * $n + 2 * $n * $n);
	}

	public function open(): void
	{
		$this->handle = File\openFile($this->path, 'w');
		$this->count = 0;
	}

	public function recordPrior(float $dt, array $xPrior, array $PPrior): void
	{
		$this->pendingPrior = \pack('e', $dt) . self::packFloats($xPrior) . self::packFloats($PPrior);
	}

	public function recordPosterior(array $xPost, array $PPost, ?int $timestampNs): void
	{
		if ($this->handle === null) {
			throw new InvalidArgument('TrajectoryFile is not open');
		}
		if ($this->pendingPrior === '') {
			$this->pendingPrior = \pack('e', 0.0) . self::packFloats($xPost) . self::packFloats($PPost);
		}
		$ts = \pack('e', (float) ($timestampNs ?? 0));
		$record = \substr($this->pendingPrior, 0, 8) . $ts . \substr($this->pendingPrior, 8) . self::packFloats($xPost) . self::packFloats($PPost);
		$this->handle->write($record);
		$this->pendingPrior = '';
		$this->count++;
	}

	public function close(): void
	{
		$this->handle?->end();
		$this->handle = null;
	}

	public function count(): int
	{
		return $this->count;
	}

	/** Loads steps [from, to) into memory. */
	public function load(int $from, int $to): FilterTrajectory
	{
		if ($from < 0 || $to <= $from) {
			throw new InvalidArgument('Invalid window');
		}
		$n = $this->n;
		$n2 = $n * $n;
		$trajectory = new FilterTrajectory($n, $to - $from);
		$file = File\openFile($this->path, 'r');
		try {
			$file->seek($from * $this->recordBytes);
			for ($k = $from; $k < $to; $k++) {
				$buffer = '';
				while (\strlen($buffer) < $this->recordBytes) {
					$chunk = $file->read(null, $this->recordBytes - \strlen($buffer));
					if ($chunk === null) {
						throw new InvalidArgument('Trajectory file truncated');
					}
					$buffer .= $chunk;
				}
				$floats = \unpack('e*', $buffer);
				if ($floats === false) {
					throw new InvalidArgument('Corrupt trajectory record');
				}
				$values = \array_values(\array_map('floatval', $floats));
				$dt = $values[0];
				$ts = (int) $values[1];
				$xPrior = \array_slice($values, 2, $n);
				$PPrior = \array_slice($values, 2 + $n, $n2);
				$xPost = \array_slice($values, 2 + $n + $n2, $n);
				$PPost = \array_slice($values, 2 + 2 * $n + $n2, $n2);
				$trajectory->recordPrior($dt, $xPrior, $PPrior);
				$trajectory->recordPosterior($xPost, $PPost, $ts);
			}
		} finally {
			$file->close();
		}
		return $trajectory;
	}

	/** Number of records in an existing file. */
	public function countOnDisk(): int
	{
		return \intdiv(File\getSize($this->path), $this->recordBytes);
	}

	public function path(): string
	{
		return $this->path;
	}

	/** @param array<int, float> $values */
	private static function packFloats(array $values): string
	{
		return \pack('e*', ...$values);
	}
}
