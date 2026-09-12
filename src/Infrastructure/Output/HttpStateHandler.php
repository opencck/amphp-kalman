<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Output;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpenCCK\Kalman\Infrastructure\Async\FilterSession;

/**
 * §5.7 GET /state/{instrument} → JSON snapshot. The snapshot is requested
 * through the session inbox and awaited here — this is an I/O boundary, so
 * the await is legitimate. Requires amphp/http-server.
 *
 * @phpstan-type SessionMap array<string, FilterSession>
 */
final class HttpStateHandler implements RequestHandler
{
	/** @var array<string, FilterSession> */
	private array $sessions;

	/**
	 * @param array<string, FilterSession> $sessions instrument => owner session
	 */
	public function __construct(array $sessions, private readonly bool $compact = false)
	{
		$this->sessions = $sessions;
	}

	public function handleRequest(Request $request): Response
	{
		$path = \rtrim($request->getUri()->getPath(), '/');
		$instrument = \basename($path);
		if ($instrument === '' || !isset($this->sessions[$instrument])) {
			return new Response(
				HttpStatus::NOT_FOUND,
				['content-type' => 'application/json'],
				\json_encode(['error' => 'unknown instrument', 'known' => \array_keys($this->sessions)], \JSON_THROW_ON_ERROR),
			);
		}
		$snapshot = $this->sessions[$instrument]->requestSnapshot()->await();
		$serializer = new SnapshotSerializer($instrument, $this->compact);
		return new Response(
			HttpStatus::OK,
			['content-type' => 'application/json', 'cache-control' => 'no-store'],
			$serializer->encode($snapshot),
		);
	}

	public function register(string $instrument, FilterSession $session): void
	{
		$this->sessions[$instrument] = $session;
	}
}
