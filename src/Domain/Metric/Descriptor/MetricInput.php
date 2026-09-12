<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Descriptor;

/** What a measurement consumes. Shown in the "Input" column of the catalogue. */
enum MetricInput: string
{
	case Ticks = 'ticks';
	case Trades = 'trades';
	case Bars = 'bars';
	case Book = 'book';
	case Multi = 'multi';
	case AnyMetric = 'any-metric';

	public function labelEn(): string
	{
		return match ($this) {
			self::Ticks => 'ticks',
			self::Trades => 'trades',
			self::Bars => 'bars',
			self::Book => 'book',
			self::Multi => 'multi',
			self::AnyMetric => 'any metric',
		};
	}

	public function labelRu(): string
	{
		return match ($this) {
			self::Ticks => 'тики',
			self::Trades => 'сделки',
			self::Bars => 'бары',
			self::Book => 'стакан',
			self::Multi => 'мульти',
			self::AnyMetric => 'любая метрика',
		};
	}
}
