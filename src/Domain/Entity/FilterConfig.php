<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;

/**
 * Immutable filter configuration. Build with the fluent withers:
 *
 *   FilterConfig::default()->withForm(FilterForm::UD)->withGating(GatingPolicy::chiSquare(0.01))
 */
final readonly class FilterConfig
{
	private function __construct(
		public FilterForm $form,
		public GatingPolicy $gating,
		/** Cache F(dt), Q(dt) keyed by quantised dt. Only for StationaryModel. */
		public bool $matrixCache,
		public int $matrixCacheSize,
		/** dt quantum (seconds) for the cache key. 1e-6 = microsecond. */
		public float $dtQuantum,
		/** Consecutive rejections on one channel before ModelBreakSuspected. */
		public int $modelBreakThreshold,
		/** Use the generated loop-free kernels for n = 2, 4 (Sequential form). */
		public bool $unrolledKernels,
		/** Linear-algebra backend (§6.3): pure PHP or OpenBLAS via FFI. */
		public Backend $backend,
	) {
	}

	public static function default(): self
	{
		return new self(
			form: FilterForm::Sequential,
			gating: GatingPolicy::none(),
			matrixCache: true,
			matrixCacheSize: 64,
			dtQuantum: 1e-6,
			modelBreakThreshold: 5,
			unrolledKernels: true,
			backend: Backend::Php,
		);
	}

	public function withForm(FilterForm $form): self
	{
		return new self($form, $this->gating, $this->matrixCache, $this->matrixCacheSize, $this->dtQuantum, $this->modelBreakThreshold, $this->unrolledKernels, $this->backend);
	}

	public function withGating(GatingPolicy $gating): self
	{
		return new self($this->form, $gating, $this->matrixCache, $this->matrixCacheSize, $this->dtQuantum, $this->modelBreakThreshold, $this->unrolledKernels, $this->backend);
	}

	public function withMatrixCache(bool $enabled, int $size = 64, float $dtQuantum = 1e-6): self
	{
		if ($size < 1) {
			throw new InvalidArgument('Matrix cache size must be >= 1');
		}
		if ($dtQuantum <= 0.0) {
			throw new InvalidArgument('dt quantum must be > 0');
		}
		return new self($this->form, $this->gating, $enabled, $size, $dtQuantum, $this->modelBreakThreshold, $this->unrolledKernels, $this->backend);
	}

	public function withModelBreakThreshold(int $threshold): self
	{
		if ($threshold < 1) {
			throw new InvalidArgument('Model break threshold must be >= 1');
		}
		return new self($this->form, $this->gating, $this->matrixCache, $this->matrixCacheSize, $this->dtQuantum, $threshold, $this->unrolledKernels, $this->backend);
	}

	public function withUnrolledKernels(bool $enabled): self
	{
		return new self($this->form, $this->gating, $this->matrixCache, $this->matrixCacheSize, $this->dtQuantum, $this->modelBreakThreshold, $enabled, $this->backend);
	}

	/**
	 * §6.3: Backend::Blas requires ext-ffi and an OpenBLAS shared library
	 * (resolved by Domain\Linalg\Ffi\BlasBackend) and the Sequential form.
	 */
	public function withBackend(Backend $backend): self
	{
		if ($backend === Backend::Blas && $this->form !== FilterForm::Sequential) {
			throw new InvalidArgument('The BLAS backend supports the Sequential form only');
		}
		return new self($this->form, $this->gating, $this->matrixCache, $this->matrixCacheSize, $this->dtQuantum, $this->modelBreakThreshold, $this->unrolledKernels, $backend);
	}

	/** @return array{form: string, gating: array{name: string, mode: int, parameter: float}, matrixCache: bool, matrixCacheSize: int, dtQuantum: float, modelBreakThreshold: int, unrolledKernels: bool, backend: string} */
	public function toArray(): array
	{
		return [
			'form' => $this->form->value,
			'gating' => $this->gating->toArray(),
			'matrixCache' => $this->matrixCache,
			'matrixCacheSize' => $this->matrixCacheSize,
			'dtQuantum' => $this->dtQuantum,
			'modelBreakThreshold' => $this->modelBreakThreshold,
			'unrolledKernels' => $this->unrolledKernels,
			'backend' => $this->backend->value,
		];
	}

	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): self
	{
		$default = self::default();
		$gating = $default->gating;
		if (isset($data['gating']) && \is_array($data['gating'])) {
			/** @var array<string, mixed> $g */
			$g = $data['gating'];
			$gating = GatingPolicy::fromArray($g);
		}
		return new self(
			FilterForm::from(ConfigReader::string($data, 'form', $default->form->value)),
			$gating,
			ConfigReader::bool($data, 'matrixCache', $default->matrixCache),
			ConfigReader::int($data, 'matrixCacheSize', $default->matrixCacheSize),
			ConfigReader::float($data, 'dtQuantum', $default->dtQuantum),
			ConfigReader::int($data, 'modelBreakThreshold', $default->modelBreakThreshold),
			ConfigReader::bool($data, 'unrolledKernels', $default->unrolledKernels),
			Backend::from(ConfigReader::string($data, 'backend', $default->backend->value)),
		);
	}
}
