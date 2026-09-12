<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Exception;

/**
 * A computation produced NaN / Inf or violated a hard numerical invariant.
 */
final class NumericalFailure extends \RuntimeException implements KalmanException
{
}
