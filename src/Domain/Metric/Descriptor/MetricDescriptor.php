<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Descriptor;

/**
 * The catalogue card of one measurement (ROADMAP §5 p.2).
 *
 * `tools/generate-metric-index.php` turns the registry of these into the two
 * summary tables in `examples/README.md` and `examples/README.ru.md`, and
 * `tests/Architecture/MetricCatalogueTest` fails if the committed tables drift
 * from the code or if an example file named here does not exist.
 */
final readonly class MetricDescriptor
{
	/**
	 * @param string $type registry key, e.g. "rsi"
	 * @param string $id catalogue number, e.g. "M-05"
	 * @param string $symbol notation used in code and docs, e.g. "RSI"
	 * @param list<MetricInput> $inputs
	 * @param list<string> $kernels offloadable static kernels, e.g. "Rsi::wilder()"
	 * @param string $example path relative to the repository root
	 */
	public function __construct(
		public string $type,
		public string $id,
		public string $symbol,
		public MetricCategory $category,
		public array $inputs,
		public array $kernels,
		public string $nameEn,
		public string $nameRu,
		public string $algoEn,
		public string $algoRu,
		public string $plainEn,
		public string $plainRu,
		public string $example,
	) {
	}

	/** @return array{type: string, id: string, symbol: string, category: string, inputs: list<string>, kernels: list<string>, nameEn: string, nameRu: string, algoEn: string, algoRu: string, plainEn: string, plainRu: string, example: string} */
	public function toArray(): array
	{
		return [
			'type' => $this->type,
			'id' => $this->id,
			'symbol' => $this->symbol,
			'category' => $this->category->value,
			'inputs' => \array_map(static fn (MetricInput $i): string => $i->value, $this->inputs),
			'kernels' => $this->kernels,
			'nameEn' => $this->nameEn,
			'nameRu' => $this->nameRu,
			'algoEn' => $this->algoEn,
			'algoRu' => $this->algoRu,
			'plainEn' => $this->plainEn,
			'plainRu' => $this->plainRu,
			'example' => $this->example,
		];
	}
}
