<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Metric\Support;

/**
 * What closes a bar.
 *
 * Calendar time is the familiar choice and the worst-behaved one: bars carry
 * wildly different amounts of information depending on the hour, and their
 * returns are neither identically distributed nor close to normal. Volume and
 * tick bars sample the market at a constant rate of activity instead, which is
 * why VPIN is defined on volume buckets (ROADMAP §4.9 M-30).
 */
enum BarClock: string
{
	case Time = 'time';
	case Volume = 'volume';
	case Ticks = 'ticks';
}
