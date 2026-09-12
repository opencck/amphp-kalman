<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Nonlinear observation z = h(x) + v for EKF / UKF. Channels are still
 * processed one at a time (diagonal R): project() gives all channel
 * predictions, jacobianRow() the gradient of one channel.
 */
interface NonlinearObservationModel
{
	public function channelCount(): int;

	/**
	 * @param array<int, float> $x
	 * @return array<int, float> h(x), m elements
	 */
	public function project(array $x): array;

	/**
	 * Prediction of ONE channel, h_i(x) — the hot path of the sequential
	 * EKF/UKF correction (project() would compute all m channels).
	 *
	 * @param array<int, float> $x
	 */
	public function projectChannel(int $channel, array $x): float;

	/**
	 * Gradient ∂h_i/∂x evaluated at x, as a sparse row (state index => value).
	 *
	 * @param array<int, float> $x
	 * @return array<int, float>
	 */
	public function jacobianRow(int $channel, array $x): array;

	public function channelVariance(int $channel): float;

	public function channelName(int $channel): string;
}
