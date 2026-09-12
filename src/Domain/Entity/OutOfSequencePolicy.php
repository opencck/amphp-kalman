<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

/**
 * What the session does with a measurement whose exchange timestamp precedes
 * the last processed one (OOSM, §2.7) after the ReorderBuffer window.
 */
enum OutOfSequencePolicy: string
{
	/** Discard and count in diagnostics (HFT default). */
	case Drop = 'drop';

	/** One-step retrodiction (Phase 6): fold the late measurement in through F⁻¹. */
	case Retrodict = 'retrodict';

	/** Treat as a bug: the session stops with an exception. */
	case Fail = 'fail';
}
