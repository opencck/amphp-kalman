<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Model\Finance;

use OpenCCK\Kalman\Domain\Contract\SerializableModel;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\Measurement;
use OpenCCK\Kalman\Domain\Exception\InvalidArgument;
use OpenCCK\Kalman\Domain\Factory\ConfigReader;
use OpenCCK\Kalman\Domain\Filter\KalmanFilter;
use OpenCCK\Kalman\Domain\Model\Generic\ConstantVelocity;
use OpenCCK\Kalman\Domain\Model\Observation\MutableObservation;

/**
 * §3.7 Order-book microprice: fair price inside the spread from volume imbalance.
 *
 *   x = [p, v] (CV)
 *   z = [mid, microprice],  microprice = (bid·V_ask + ask·V_bid)/(V_ask + V_bid)
 *   H = [1 0; 1 0],  R = diag(r_mid, r_micro,t)
 *   r_mid   = half_spread² + tick²/12
 *   r_micro = depthScale / (V_bid + V_ask)   — thinner book → noisier microprice
 *
 * updateBook() recomputes r_micro and returns the two-channel measurement.
 */
final class Microprice implements SerializableModel
{
	public const CHANNEL_MID = 0;
	public const CHANNEL_MICRO = 1;

	private MutableObservation $observation;

	public function __construct(
		private readonly float $sigmaA,
		private readonly float $tick,
		private readonly float $depthScale,
		private readonly float $minMicroVariance = 1e-12,
	) {
		if ($sigmaA <= 0.0 || $tick <= 0.0 || $depthScale <= 0.0) {
			throw new InvalidArgument('sigmaA, tick and depthScale must be > 0');
		}
		$this->observation = new MutableObservation(
			[[0 => 1.0], [0 => 1.0]],
			[$tick * $tick / 12.0, $depthScale],
			['mid', 'microprice'],
		);
	}

	public function motion(): ConstantVelocity
	{
		return new ConstantVelocity($this->sigmaA);
	}

	public function observation(): MutableObservation
	{
		return $this->observation;
	}

	public static function microprice(float $bid, float $ask, float $bidVolume, float $askVolume): float
	{
		$total = $bidVolume + $askVolume;
		if ($total <= 0.0) {
			return 0.5 * ($bid + $ask);
		}
		return ($bid * $askVolume + $ask * $bidVolume) / $total;
	}

	/**
	 * Updates R from the current book and returns the measurement [mid, microprice].
	 */
	public function updateBook(int $timestampNs, float $bid, float $ask, float $bidVolume, float $askVolume): Measurement
	{
		if ($ask < $bid) {
			throw new InvalidArgument('ask must be >= bid');
		}
		$half = 0.5 * ($ask - $bid);
		$rMid = $half * $half + $this->tick * $this->tick / 12.0;
		$depth = $bidVolume + $askVolume;
		$rMicro = $depth > 0.0 ? $this->depthScale / $depth : $rMid;
		if ($rMicro < $this->minMicroVariance) {
			$rMicro = $this->minMicroVariance;
		}
		$this->observation->setVariance(self::CHANNEL_MID, $rMid);
		$this->observation->setVariance(self::CHANNEL_MICRO, $rMicro);
		return Measurement::at($timestampNs, [
			self::CHANNEL_MID => 0.5 * ($bid + $ask),
			self::CHANNEL_MICRO => self::microprice($bid, $ask, $bidVolume, $askVolume),
		]);
	}

	public function filter(float $firstMid, ?FilterConfig $config = null, float $velocityPriorStd = 0.1): KalmanFilter
	{
		$r = $this->observation->channelVariance(self::CHANNEL_MID);
		return new KalmanFilter($this->motion(), $this->observation, [$firstMid, 0.0], [$r * 4.0, 0.0, 0.0, $velocityPriorStd * $velocityPriorStd], $config);
	}

	public static function type(): string
	{
		return 'microprice';
	}

	public function toArray(): array
	{
		return ['type' => self::type(), 'sigmaA' => $this->sigmaA, 'tick' => $this->tick, 'depthScale' => $this->depthScale, 'minMicroVariance' => $this->minMicroVariance];
	}

	public static function fromArray(array $config): static
	{
		return new self(
			ConfigReader::float($config, 'sigmaA'),
			ConfigReader::float($config, 'tick'),
			ConfigReader::float($config, 'depthScale'),
			ConfigReader::float($config, 'minMicroVariance', 1e-12),
		);
	}
}
