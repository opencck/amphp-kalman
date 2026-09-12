<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Descriptor;

/**
 * The eleven categories of the measurement catalogue (ROADMAP §5 p.4): the
 * eight taken from the reference spreadsheet, plus microstructure, filter
 * state and diagnostics, which the reference has no equivalent of.
 */
enum MetricCategory: string
{
	case Price = 'price';
	case Momentum = 'momentum';
	case Trend = 'trend';
	case Volume = 'volume';
	case Liquidity = 'liquidity';
	case Volatility = 'volatility';
	case CrossAsset = 'cross-asset';
	case Regime = 'regime';
	case Microstructure = 'microstructure';
	case FilterState = 'filter-state';
	case Diagnostics = 'diagnostics';

	public function labelEn(): string
	{
		return match ($this) {
			self::Price => 'price',
			self::Momentum => 'momentum',
			self::Trend => 'trend',
			self::Volume => 'volume',
			self::Liquidity => 'liquidity and order book',
			self::Volatility => 'volatility',
			self::CrossAsset => 'cross-asset',
			self::Regime => 'fair value and regime',
			self::Microstructure => 'microstructure',
			self::FilterState => 'filter state',
			self::Diagnostics => 'diagnostics',
		};
	}

	public function labelRu(): string
	{
		return match ($this) {
			self::Price => 'ценовые',
			self::Momentum => 'импульсные',
			self::Trend => 'трендовые',
			self::Volume => 'объёмные',
			self::Liquidity => 'ликвидность и стакан',
			self::Volatility => 'волатильность',
			self::CrossAsset => 'кросс-активные',
			self::Regime => 'справедливая цена и режим',
			self::Microstructure => 'микроструктура',
			self::FilterState => 'состояние фильтра',
			self::Diagnostics => 'диагностика',
		};
	}

	/** Order in which categories appear in the generated catalogue. */
	public function rank(): int
	{
		return match ($this) {
			self::Price => 1,
			self::Momentum => 2,
			self::Trend => 3,
			self::Volume => 4,
			self::Liquidity => 5,
			self::Volatility => 6,
			self::CrossAsset => 7,
			self::Regime => 8,
			self::Microstructure => 9,
			self::FilterState => 10,
			self::Diagnostics => 11,
		};
	}
}
