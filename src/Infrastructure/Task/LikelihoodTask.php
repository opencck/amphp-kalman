<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Task;

use Amp\Cancellation;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use function Amp\File\filesystem;
use OpenCCK\Kalman\App\Calibration\InnovationLikelihood;
use OpenCCK\Kalman\Infrastructure\Ingest\HistoryReader;

/**
 * §5.10 one likelihood evaluation in a worker process. Everything in the
 * constructor is a plain array/scalar (serialised to the worker); the history
 * is either a file path (the worker reads it itself) or an in-memory tick
 * list for small histories / tests.
 *
 * The decoded history is cached per worker process (see history()).
 *
 * File I/O inside the worker uses the BLOCKING filesystem driver on purpose:
 * the default ParallelFilesystemDriver would spawn a nested worker pool in
 * every worker process (slow start-up, lingering children that stall the
 * parent's shutdown on Windows), and a dedicated worker has nothing else to
 * do while it reads — blocking is the correct choice there (§5.9).
 *
 * @implements Task<mixed, mixed, mixed>
 */
final class LikelihoodTask implements Task
{
	/**
	 * @param array<string, mixed> $modelConfig
	 * @param array<string, mixed>|null $filterConfig
	 * @param array<string, string> $parametrization
	 * @param array<int, float> $theta
	 * @param array<int, array{ts: int, values: array<int, float>}>|null $ticks
	 */
	public function __construct(
		private readonly array $modelConfig,
		private readonly ?array $filterConfig,
		private readonly array $parametrization,
		private readonly array $theta,
		private readonly ?string $historyPath = null,
		private readonly ?array $ticks = null,
	) {
	}

	public function run(Channel $channel, Cancellation $cancellation): float
	{
		$ticks = $this->ticks;
		if ($ticks === null) {
			if ($this->historyPath === null) {
				throw new \InvalidArgumentException('Either historyPath or ticks must be given');
			}
			$ticks = self::history($this->historyPath);
		}
		return InnovationLikelihood::evaluateTheta($this->modelConfig, $this->filterConfig, $this->parametrization, $this->theta, $ticks);
	}

	/** @var array<string, array{0: string, 1: array<int, array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>}>}> path => [fingerprint, ticks] */
	private static array $historyCache = [];

	/**
	 * The decoded history is kept per worker process (one entry per path): a
	 * simplex iteration submits several LikelihoodTasks per worker for the same
	 * file, and decoding 10⁵–10⁶ JSON lines costs more than the filter run itself.
	 * The cache is invalidated when the file's size or mtime changes.
	 *
	 * @return array<int, array{ts: int, values: array<int, float>, rows?: array<int, array<int, float>>}>
	 */
	private static function history(string $path): array
	{
		self::useBlockingFilesystem();
		\clearstatcache(true, $path);
		$size = \filesize($path);
		$mtime = \filemtime($path);
		$fingerprint = ($size === false ? '?' : (string) $size) . ':' . ($mtime === false ? '?' : (string) $mtime);
		$cached = self::$historyCache[$path] ?? null;
		if ($cached !== null && $cached[0] === $fingerprint) {
			return $cached[1];
		}
		$ticks = HistoryReader::readAll($path);
		self::$historyCache = [$path => [$fingerprint, $ticks]]; // one history per worker keeps memory bounded
		return $ticks;
	}

	private static bool $blockingFilesystem = false;

	/** Once per worker process. */
	private static function useBlockingFilesystem(): void
	{
		if (!self::$blockingFilesystem) {
			filesystem(new BlockingFilesystemDriver());
			self::$blockingFilesystem = true;
		}
	}
}
