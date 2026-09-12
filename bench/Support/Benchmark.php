<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Bench\Support;

/**
 * One benchmark script = one class. run() returns metric name => value
 * (lower is better; microseconds per operation unless the name says otherwise).
 */
interface Benchmark
{
	public function name(): string;

	/** @return array<string, float> */
	public function run(): array;
}
