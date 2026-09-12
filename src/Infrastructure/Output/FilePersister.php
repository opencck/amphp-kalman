<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Output;

use Amp\File;
use Amp\Pipeline\Queue;
use OpenCCK\Kalman\Domain\Entity\StateSnapshot;

/**
 * §5.8 atomic snapshot file: write to `path.tmp`, then rename over `path`,
 * so a reader never sees a torn file. Non-blocking via Amp\File.
 */
final class FilePersister
{
	private int $written = 0;

	public function __construct(
		private readonly string $path,
		private readonly SnapshotSerializer $serializer = new SnapshotSerializer(),
	) {
	}

	/**
	 * Consumes the snapshot queue until it completes.
	 *
	 * @param Queue<StateSnapshot> $snapshots
	 */
	public function consume(Queue $snapshots): void
	{
		foreach ($snapshots->iterate() as $snapshot) {
			$this->save($snapshot);
		}
	}

	public function save(StateSnapshot $snapshot): void
	{
		$tmp = $this->path . '.tmp';
		File\write($tmp, $this->serializer->encode($snapshot));
		File\move($tmp, $this->path);
		$this->written++;
	}

	public function load(): ?StateSnapshot
	{
		if (!File\exists($this->path)) {
			return null;
		}
		return $this->serializer->decode(File\read($this->path));
	}

	public function written(): int
	{
		return $this->written;
	}

	public function path(): string
	{
		return $this->path;
	}
}
