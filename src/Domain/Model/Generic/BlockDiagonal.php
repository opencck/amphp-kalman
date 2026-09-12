<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Generic;

use OpenCCK\Kalman\Domain\Contract\MotionModel;
use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Contract\SparseMotionModel;
use OpenCCK\Kalman\Domain\Contract\StationaryModel;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ModelRegistry;

/**
 * Independent sub-models stacked into one state: F and Q are block-diagonal,
 * the covariance P is NOT (the filter learns cross-correlations through the
 * observations). Used for Nelson–Siegel (3 × OU) and ad-hoc compositions.
 *
 * advanceInPlace applies P ← F·P·Fᵀ block-wise in O(n²·max block size).
 */
final class BlockDiagonal implements SparseMotionModel, StationaryModel, SerializableModel
{
	/** @var array<int, MotionModel> */
	private array $blocks;

	/** @var array<int, int> start offset of each block */
	private array $offsets = [];

	/** @var array<int, int> */
	private array $sizes = [];

	private int $n = 0;
	private bool $stationary = true;

	/** @param array<int, MotionModel> $blocks */
	public function __construct(array $blocks)
	{
		if ($blocks === []) {
			throw new InvalidArgument('At least one block is required');
		}
		$this->blocks = \array_values($blocks);
		foreach ($this->blocks as $block) {
			$this->offsets[] = $this->n;
			$size = $block->stateSize();
			$this->sizes[] = $size;
			$this->n += $size;
			if (!$block instanceof StationaryModel) {
				$this->stationary = false;
			}
		}
	}

	/** @return array<int, MotionModel> */
	public function blocks(): array
	{
		return $this->blocks;
	}

	public function offset(int $block): int
	{
		return $this->offsets[$block];
	}

	public function isStationary(): bool
	{
		return $this->stationary;
	}

	public function stateSize(): int
	{
		return $this->n;
	}

	public function transition(float $dt): array
	{
		$n = $this->n;
		$F = \array_fill(0, $n * $n, 0.0);
		foreach ($this->blocks as $b => $block) {
			$o = $this->offsets[$b];
			$s = $this->sizes[$b];
			$Fb = $block->transition($dt);
			for ($i = 0; $i < $s; $i++) {
				for ($j = 0; $j < $s; $j++) {
					$F[($o + $i) * $n + $o + $j] = $Fb[$i * $s + $j];
				}
			}
		}
		return $F;
	}

	public function processNoise(float $dt): array
	{
		$n = $this->n;
		$Q = \array_fill(0, $n * $n, 0.0);
		foreach ($this->blocks as $b => $block) {
			$o = $this->offsets[$b];
			$s = $this->sizes[$b];
			$Qb = $block->processNoise($dt);
			for ($i = 0; $i < $s; $i++) {
				for ($j = 0; $j < $s; $j++) {
					$Q[($o + $i) * $n + $o + $j] = $Qb[$i * $s + $j];
				}
			}
		}
		return $Q;
	}

	public function control(float $dt): ?array
	{
		$u = null;
		foreach ($this->blocks as $b => $block) {
			$ub = $block->control($dt);
			if ($ub === null) {
				continue;
			}
			$u ??= \array_fill(0, $this->n, 0.0);
			$o = $this->offsets[$b];
			foreach ($ub as $i => $v) {
				$u[$o + $i] = $v;
			}
		}
		return $u;
	}

	public function advanceInPlace(array &$x, array &$P, float $dt): void
	{
		$n = $this->n;
		$count = \count($this->blocks);
		/** @var array<int, array<int, float>> $Fs */
		$Fs = [];
		foreach ($this->blocks as $b => $block) {
			$Fs[$b] = $block->transition($dt);
		}
		// x ← F x + u
		$xNew = \array_fill(0, $n, 0.0);
		foreach ($this->blocks as $b => $block) {
			$o = $this->offsets[$b];
			$s = $this->sizes[$b];
			$Fb = $Fs[$b];
			$ub = $block->control($dt);
			for ($i = 0; $i < $s; $i++) {
				$sum = 0.0;
				for ($j = 0; $j < $s; $j++) {
					$sum += $Fb[$i * $s + $j] * $x[$o + $j];
				}
				$xNew[$o + $i] = $sum + ($ub === null ? 0.0 : $ub[$i]);
			}
		}
		for ($i = 0; $i < $n; $i++) {
			$x[$i] = $xNew[$i];
		}

		// T = F P : for each row block b, rows o..o+s-1 ← Fb · rows
		$T = $P;
		for ($b = 0; $b < $count; $b++) {
			$o = $this->offsets[$b];
			$s = $this->sizes[$b];
			$Fb = $Fs[$b];
			for ($i = 0; $i < $s; $i++) {
				$row = ($o + $i) * $n;
				for ($j = 0; $j < $n; $j++) {
					$sum = 0.0;
					for ($k = 0; $k < $s; $k++) {
						$sum += $Fb[$i * $s + $k] * $P[($o + $k) * $n + $j];
					}
					$T[$row + $j] = $sum;
				}
			}
		}
		// P = T Fᵀ : for each column block c, cols o..o+s-1 ← T[:, block] · Fcᵀ ; upper triangle then mirror
		for ($c = 0; $c < $count; $c++) {
			$o = $this->offsets[$c];
			$s = $this->sizes[$c];
			$Fc = $Fs[$c];
			for ($i = 0; $i < $n; $i++) {
				$row = $i * $n;
				for ($j = 0; $j < $s; $j++) {
					$col = $o + $j;
					if ($col < $i) {
						continue;
					}
					$sum = 0.0;
					for ($k = 0; $k < $s; $k++) {
						$sum += $T[$row + $o + $k] * $Fc[$j * $s + $k];
					}
					$P[$row + $col] = $sum;
					$P[$col * $n + $i] = $sum;
				}
			}
		}
	}

	public static function type(): string
	{
		return 'block-diagonal';
	}

	public function toArray(): array
	{
		$blocks = [];
		foreach ($this->blocks as $block) {
			if (!$block instanceof SerializableModel) {
				throw new InvalidArgument('All blocks must be serialisable to export a BlockDiagonal model');
			}
			$blocks[] = $block->toArray();
		}
		return ['type' => self::type(), 'blocks' => $blocks];
	}

	public static function fromArray(array $config): static
	{
		if (!isset($config['blocks']) || !\is_array($config['blocks'])) {
			throw new InvalidArgument('block-diagonal config requires "blocks"');
		}
		$blocks = [];
		foreach ($config['blocks'] as $spec) {
			if (!\is_array($spec)) {
				throw new InvalidArgument('Each block must be a config array');
			}
			/** @var array<string, mixed> $spec */
			$model = ModelRegistry::build($spec);
			if (!$model instanceof MotionModel) {
				throw new InvalidArgument('Blocks must be motion models');
			}
			$blocks[] = $model;
		}
		return new self($blocks);
	}
}
