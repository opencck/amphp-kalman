<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Channel-wise, sparse observation model with diagonal R.
 *
 * Channel i observes z_i = h_i · x + v_i,  v_i ~ N(0, r_i). Rows are sparse
 * maps "state index => coefficient" so the filter can compute P·h_iᵀ in
 * O(n · nnz) instead of O(n²). Channels are processed one at a time
 * (sequential scalar correction), so R must be diagonal; use
 * {@see CorrelatedObservationModel} plus the decorrelating adapter otherwise.
 *
 * Rows and variances MAY change between calls (time-varying H_t, R_t):
 * the filter re-reads them on every correction.
 */
interface ObservationModel
{
	public function channelCount(): int;

	/**
	 * @return array<int, float> state index => coefficient (sparse row of H)
	 */
	public function channelRow(int $channel): array;

	/** Measurement noise variance r_i (must be > 0). */
	public function channelVariance(int $channel): float;

	public function channelName(int $channel): string;
}
