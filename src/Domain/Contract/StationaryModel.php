<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Contract;

/**
 * Marker: F(dt), Q(dt) and B·u(dt) depend only on dt, never on the step
 * index, wall-clock time or external state. Only stationary models are
 * eligible for the F/Q matrix cache in FilterConfig.
 */
interface StationaryModel
{
}
