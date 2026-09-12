<?php declare(strict_types=1);

namespace OpenCCK\Kalman\App\Calibration;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Linalg\Cholesky;

/**
 * §2.11.2 maps a free parameter vector θ ∈ ℝ^d onto a serialisable model
 * config. Each entry is "dotted.config.path" => transform:
 *
 *   'log'          positive scalar,  value = exp(θ_i)
 *   'linear'       unconstrained scalar
 *   'cholesky:n'   SPD n×n flat matrix from n(n+1)/2 numbers: lower-triangular L
 *                  with exp() on the diagonal, value = L·Lᵀ
 *
 * Example: new Parametrization(['motion.sigmaA' => 'log', 'observation.variances.0' => 'log'])
 *
 * Fully serialisable (toArray/fromArray) so worker processes can rebuild it.
 */
final class Parametrization
{
	/** @var array<string, string> */
	private array $entries;

	/** @param array<string, string> $entries */
	public function __construct(array $entries)
	{
		if ($entries === []) {
			throw new InvalidArgument('At least one parameter is required');
		}
		foreach ($entries as $path => $transform) {
			if (!\in_array($transform, ['log', 'linear'], true) && \preg_match('/^cholesky:\d+$/', $transform) !== 1) {
				throw new InvalidArgument(\sprintf('Unknown transform "%s" for %s', $transform, $path));
			}
		}
		$this->entries = $entries;
	}

	public function dimension(): int
	{
		$d = 0;
		foreach ($this->entries as $transform) {
			$d += self::width($transform);
		}
		return $d;
	}

	/** @return array<string, string> */
	public function entries(): array
	{
		return $this->entries;
	}

	/**
	 * Extracts θ from a config (forward transform).
	 *
	 * @param array<string, mixed> $config
	 * @return array<int, float>
	 */
	public function toTheta(array $config): array
	{
		return self::extractTheta($this->entries, $config);
	}

	/**
	 * Writes θ into a copy of the config (inverse transform).
	 *
	 * @param array<string, mixed> $config
	 * @param array<int, float> $theta
	 * @return array<string, mixed>
	 */
	public function apply(array $config, array $theta): array
	{
		return self::applyTheta($this->entries, $config, $theta);
	}

	/**
	 * Forward transform as a stateless kernel: entries are the serialised
	 * parametrisation (see toArray()).
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<string, string> $entries
	 * @param array<string, mixed> $config
	 * @return array<int, float>
	 */
	public static function extractTheta(array $entries, array $config): array
	{
		$theta = [];
		foreach ($entries as $path => $transform) {
			$value = self::get($config, $path);
			if ($transform === 'log') {
				$v = self::scalar($value, $path);
				if ($v <= 0.0) {
					throw new InvalidArgument("$path must be > 0 for a log transform");
				}
				$theta[] = \log($v);
			} elseif ($transform === 'linear') {
				$theta[] = self::scalar($value, $path);
			} else {
				$n = self::choleskySize($transform);
				if (!\is_array($value)) {
					throw new InvalidArgument("$path must be a flat matrix");
				}
				$M = [];
				/** @psalm-suppress MixedAssignment — config values are untyped by definition; scalar() validates each one */
				foreach ($value as $entry) {
					$M[] = self::scalar($entry, $path);
				}
				$L = Cholesky::decompose($M, $n);
				for ($i = 0; $i < $n; $i++) {
					for ($j = 0; $j <= $i; $j++) {
						$theta[] = $i === $j ? \log($L[$i * $n + $j]) : $L[$i * $n + $j];
					}
				}
			}
		}
		return $theta;
	}

	/**
	 * Inverse transform as a stateless kernel.
	 *
	 * @deterministic
	 * @offloadable
	 * @param array<string, string> $entries
	 * @param array<string, mixed> $config
	 * @param array<int, float> $theta
	 * @return array<string, mixed>
	 */
	public static function applyTheta(array $entries, array $config, array $theta): array
	{
		$d = 0;
		foreach ($entries as $transform) {
			$d += self::width($transform);
		}
		if (\count($theta) !== $d) {
			throw new InvalidArgument(\sprintf('theta must have %d entries, got %d', $d, \count($theta)));
		}
		$k = 0;
		foreach ($entries as $path => $transform) {
			if ($transform === 'log') {
				$config = self::with($config, $path, \exp($theta[$k++]));
			} elseif ($transform === 'linear') {
				$config = self::with($config, $path, $theta[$k++]);
			} else {
				$n = self::choleskySize($transform);
				$L = \array_fill(0, $n * $n, 0.0);
				for ($i = 0; $i < $n; $i++) {
					for ($j = 0; $j <= $i; $j++) {
						$L[$i * $n + $j] = $i === $j ? \exp($theta[$k++]) : $theta[$k++];
					}
				}
				$M = \array_fill(0, $n * $n, 0.0);
				for ($i = 0; $i < $n; $i++) {
					for ($j = 0; $j <= $i; $j++) {
						$s = 0.0;
						for ($p = 0; $p <= $j; $p++) {
							$s += $L[$i * $n + $p] * $L[$j * $n + $p];
						}
						$M[$i * $n + $j] = $s;
						$M[$j * $n + $i] = $s;
					}
				}
				$config = self::with($config, $path, $M);
			}
		}
		return $config;
	}

	/** @return array<string, string> */
	public function toArray(): array
	{
		return $this->entries;
	}

	/** @param array<string, string> $entries */
	public static function fromArray(array $entries): self
	{
		return new self($entries);
	}

	private static function width(string $transform): int
	{
		if ($transform === 'log' || $transform === 'linear') {
			return 1;
		}
		$n = self::choleskySize($transform);
		return \intdiv($n * ($n + 1), 2);
	}

	private static function choleskySize(string $transform): int
	{
		return (int) \substr($transform, \strlen('cholesky:'));
	}

	private static function scalar(mixed $value, string $path): float
	{
		if (\is_int($value) || \is_float($value)) {
			return (float) $value;
		}
		throw new InvalidArgument("$path must be numeric");
	}

	/** @param array<string, mixed> $config */
	private static function get(array $config, string $path): mixed
	{
		/** @var array<mixed> $node */
		$node = $config;
		$segments = \explode('.', $path);
		$last = \array_pop($segments);
		foreach ($segments as $segment) {
			if (!\array_key_exists($segment, $node) || !\is_array($node[$segment])) {
				throw new InvalidArgument("Config path \"$path\" not found");
			}
			/** @var array<mixed> $node */
			$node = $node[$segment];
		}
		if (!\array_key_exists($last, $node)) {
			throw new InvalidArgument("Config path \"$path\" not found");
		}
		return $node[$last];
	}

	/**
	 * Returns a copy of the config with the value at the dotted path replaced.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	private static function with(array $config, string $path, mixed $value): array
	{
		$segments = \explode('.', $path);
		/** @var array<string, mixed> $updated assign() preserves the key type of its input */
		$updated = self::assign($config, $segments, $value, $path);
		return $updated;
	}

	/**
	 * The config tree is untyped by construction: this method writes a mixed value
	 * into it and validates the shape of every node it walks through.
	 *
	 * @param array<mixed> $node
	 * @param array<int, string> $segments
	 * @return array<mixed>
	 *
	 * @psalm-suppress MixedAssignment
	 */
	private static function assign(array $node, array $segments, mixed $value, string $path): array
	{
		$segment = \array_shift($segments);
		if ($segment === null || !\array_key_exists($segment, $node)) {
			throw new InvalidArgument("Config path \"$path\" not found");
		}
		if ($segments === []) {
			$node[$segment] = $value;
			return $node;
		}
		$child = $node[$segment];
		if (!\is_array($child)) {
			throw new InvalidArgument("Config path \"$path\" not found");
		}
		$node[$segment] = self::assign($child, $segments, $value, $path);
		return $node;
	}
}
