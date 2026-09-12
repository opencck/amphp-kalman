<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Entity;

/**
 * Numerically stable covariance forms (§2.5). Selected via FilterConfig.
 */
enum FilterForm: string
{
	/** Dense P, per-channel scalar rank-1 downdate, exact symmetry. Default. */
	case Sequential = 'sequential';

	/** Dense P, Joseph stabilised update (I−KH)P(I−KH)ᵀ + KRKᵀ. */
	case Joseph = 'joseph';

	/** P = U·D·Uᵀ, Bierman correct + Thornton (MWGS) predict. Recommended for n ≤ 32. */
	case UD = 'ud';

	/** P = S·Sᵀ, Potter correct + Householder predict. Maximum stability. */
	case SquareRoot = 'square-root';
}
