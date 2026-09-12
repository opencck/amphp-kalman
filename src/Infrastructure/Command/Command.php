<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Infrastructure\Command;

/**
 * Marker for everything that travels through a FilterSession inbox besides
 * Measurement: snapshot requests and corporate actions. Commands are
 * executed by the owner fiber in arrival order (§4.5 linearisability).
 */
interface Command
{
}
