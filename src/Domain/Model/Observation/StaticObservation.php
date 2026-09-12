<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Observation;

use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * Observation model with fixed sparse rows and fixed diagonal R.
 *
 *   new StaticObservation(rows: [[0 => 1.0], [0 => 0.5, 2 => 1.0]], variances: [0.01, 0.04], names: ['bid', 'etf'])
 */
final class StaticObservation implements ObservationModel, SerializableModel
{
	/** @var array<int, array<int, float>> */
	private array $rows;

	/** @var array<int, float> */
	private array $variances;

	/** @var array<int, string> */
	private array $names;

	private int $m;

	/**
	 * @param array<int, array<int, float|int>> $rows sparse rows: state index => coefficient
	 * @param array<int, float|int> $variances r_i > 0
	 * @param array<int, string>|null $names defaults to "ch0", "ch1", …
	 */
	public function __construct(array $rows, array $variances, ?array $names = null)
	{
		$m = \count($rows);
		if ($m < 1) {
			throw new InvalidArgument('At least one channel is required');
		}
		if (\count($variances) !== $m) {
			throw new InvalidArgument('variances count must equal rows count');
		}
		if ($names !== null && \count($names) !== $m) {
			throw new InvalidArgument('names count must equal rows count');
		}
		$this->rows = [];
		$this->variances = [];
		foreach ($rows as $i => $row) {
			if ($row === []) {
				throw new InvalidArgument(\sprintf('Channel %d row is empty', $i));
			}
			$clean = [];
			foreach ($row as $j => $c) {
				if ($j < 0) {
					throw new InvalidArgument(\sprintf('Channel %d: negative state index', $i));
				}
				$clean[$j] = (float) $c;
			}
			\ksort($clean);
			$this->rows[] = $clean;
			$v = (float) $variances[$i];
			if (!($v > 0.0) || !\is_finite($v)) {
				throw new InvalidArgument(\sprintf('Channel %d variance must be > 0 and finite', $i));
			}
			$this->variances[] = $v;
		}
		$this->names = $names ?? \array_map(static fn (int $i): string => 'ch' . $i, \range(0, $m - 1));
		$this->m = $m;
	}

	/**
	 * Builds from a dense H (m×n) and a variance vector.
	 *
	 * @param array<int, float> $H m×n row-major
	 * @param array<int, float> $variances
	 * @param array<int, string>|null $names
	 */
	public static function fromDense(array $H, int $m, int $n, array $variances, ?array $names = null): self
	{
		$rows = [];
		for ($i = 0; $i < $m; $i++) {
			$rows[] = Flat::sparseRow(\array_slice($H, $i * $n, $n));
		}
		return new self($rows, $variances, $names);
	}

	public function channelCount(): int
	{
		return $this->m;
	}

	public function channelRow(int $channel): array
	{
		return $this->rows[$channel];
	}

	public function channelVariance(int $channel): float
	{
		return $this->variances[$channel];
	}

	public function channelName(int $channel): string
	{
		return $this->names[$channel];
	}

	/** @return array<int, float> dense H, m×n */
	public function denseMatrix(int $n): array
	{
		$H = [];
		foreach ($this->rows as $row) {
			foreach (Flat::denseRow($row, $n) as $v) {
				$H[] = $v;
			}
		}
		return $H;
	}

	/** @return array<int, float> */
	public function variances(): array
	{
		return $this->variances;
	}

	public function withVariance(int $channel, float $variance): self
	{
		$variances = $this->variances;
		$variances[$channel] = $variance;
		return new self($this->rows, $variances, $this->names);
	}

	public static function type(): string
	{
		return 'static-observation';
	}

	public function toArray(): array
	{
		return [
			'type' => self::type(),
			'rows' => $this->rows,
			'variances' => $this->variances,
			'names' => $this->names,
		];
	}

	public static function fromArray(array $config): static
	{
		return new self(
			ConfigReader::sparseRows($config, 'rows'),
			ConfigReader::floatList($config, 'variances'),
			isset($config['names']) ? ConfigReader::stringList($config, 'names') : null,
		);
	}
}
