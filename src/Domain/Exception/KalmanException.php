<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Exception;

/**
 * Marker interface: every exception thrown by the library implements it,
 * so callers can `catch (KalmanException)` regardless of the concrete type.
 */
interface KalmanException extends \Throwable
{
}
