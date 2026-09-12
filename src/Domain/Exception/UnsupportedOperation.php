<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Exception;

/**
 * The requested operation is not available for this filter variant
 * (e.g. missing channels on a steady-state filter with a constant gain).
 */
final class UnsupportedOperation extends \LogicException implements KalmanException
{
}
