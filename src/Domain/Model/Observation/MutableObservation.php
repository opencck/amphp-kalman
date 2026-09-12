<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Observation;

use OpenCCK\Kalman\Domain\Contract\ObservationModel;
use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Observation model whose rows and variances change from tick to tick
 * (time-varying H_t / R_t: pairs hedge-ratio, dynamic beta, microprice
 * with depth-dependent noise). The caller updates it BEFORE each step —
 * the filter re-reads rows and variances on every correction.
 *
 * Serialisable as its CURRENT rows/variances (type "mutable-observation");
 * across a process boundary the per-tick rows travel with the ticks
 * (tick key "rows", see FilterBatch / TickCodec).
 */
final class MutableObservation implements ObservationModel, SerializableModel
{
	/** @var array<int, array<int, float>> */
	private array $rows;

	/** @var array<int, float> */
	private array $variances;

	/** @var array<int, string> */
	private array $names;

	/**
	 * @param array<int, array<int, float>> $rows
	 * @param array<int, float> $variances
	 * @param array<int, string>|null $names
	 */
	public function __construct(array $rows, array $variances, ?array $names = null)
	{
		$m = \count($rows);
		if ($m < 1 || \count($variances) !== $m) {
			throw new InvalidArgument('rows and variances must be non-empty and of equal length');
		}
		$this->rows = \array_values($rows);
		$this->variances = \array_values($variances);
		$this->names = $names !== null ? \array_values($names) : \array_map(static fn (int $i): string => 'ch' . $i, \range(0, $m - 1));
	}

	public function channelCount(): int
	{
		return \count($this->rows);
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

	/** @param array<int, float> $row */
	public function setRow(int $channel, array $row): void
	{
		if (!isset($this->rows[$channel])) {
			throw new InvalidArgument(\sprintf('Channel %d out of range', $channel));
		}
		$this->rows[$channel] = $row;
	}

	/** Sets one coefficient of a row (e.g. the regressor x_t in H_t = [1, x_t]). */
	public function setCoefficient(int $channel, int $stateIndex, float $value): void
	{
		$this->rows[$channel][$stateIndex] = $value;
	}

	public function setVariance(int $channel, float $variance): void
	{
		if (!($variance > 0.0)) {
			throw new InvalidArgument('variance must be > 0');
		}
		$this->variances[$channel] = $variance;
	}

	public static function type(): string
	{
		return 'mutable-observation';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'rows' => $this->rows, 'variances' => $this->variances, 'names' => $this->names];
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
