<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Exception\DimensionMismatch;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Linalg\Flat;

/**
 * Discrete-time linear model with FIXED per-step F and Q (and optional u),
 * independent of dt — for regularly sampled bars and for the output of the
 * EM algorithm, which estimates Q per step. dt is ignored; use it only when
 * every step has the same interval.
 */
final class DiscreteLinear implements MotionModel, StationaryModel, SerializableModel
{
	/**
	 * @param array<int, float> $F n×n
	 * @param array<int, float> $Q n×n
	 * @param array<int, float>|null $u n
	 */
	public function __construct(
		private readonly array $F,
		private readonly array $Q,
		private readonly int $n,
		private readonly ?array $u = null,
	) {
		if (\count($F) !== $n * $n) {
			throw DimensionMismatch::forMatrix('F', $n * $n, \count($F));
		}
		if (\count($Q) !== $n * $n) {
			throw DimensionMismatch::forMatrix('Q', $n * $n, \count($Q));
		}
		if ($u !== null && \count($u) !== $n) {
			throw DimensionMismatch::forVector('u', $n, \count($u));
		}
	}

	/** Freezes any motion model at a fixed dt. */
	public static function fromModel(MotionModel $model, float $dt): self
	{
		return new self($model->transition($dt), Flat::symmetrize($model->processNoise($dt), $model->stateSize()), $model->stateSize(), $model->control($dt));
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function transition(float $dt): array
	{
		return $this->F;
	}

	public function processNoise(float $dt): array
	{
		return $this->Q;
	}

	public function control(float $dt): ?array
	{
		return $this->u;
	}

	public static function type(): string
	{
		return 'discrete-linear';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'n' => $this->n, 'F' => $this->F, 'Q' => $this->Q, 'u' => $this->u];
	}

	public static function fromArray(array $config): static
	{
		$n = ConfigReader::int($config, 'n');
		return new self(
			ConfigReader::floatList($config, 'F', $n * $n),
			ConfigReader::floatList($config, 'Q', $n * $n),
			$n,
			ConfigReader::optionalFloatList($config, 'u'),
		);
	}
}
