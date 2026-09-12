<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Exception;

/**
 * A measurement's timestamp precedes the filter's last processed timestamp.
 * The filter itself never goes back in time; ordering (ReorderBuffer) and
 * the OutOfSequencePolicy live in the session layer.
 */
final class OutOfSequenceMeasurement extends \RuntimeException implements KalmanException
{
	public function __construct(public readonly int $timestampNs, public readonly int $lastTimestampNs)
	{
		parent::__construct(\sprintf(
			'Measurement at %d ns precedes last processed timestamp %d ns by %d ns',
			$timestampNs,
			$lastTimestampNs,
			$lastTimestampNs - $timestampNs,
		));
	}
}
