<?php declare(strict_types=1);

namespace OpenCCK\Kalman\Domain\Factory;

use OpenCCK\Kalman\Domain\Contract\CovarianceRepresentation;
use OpenCCK\Kalman\Domain\Covariance\BlasDense;
use OpenCCK\Kalman\Domain\Covariance\DenseSequential;
use OpenCCK\Kalman\Domain\Covariance\Joseph;
use OpenCCK\Kalman\Domain\Covariance\Kernels\Sequential2;
use OpenCCK\Kalman\Domain\Covariance\Kernels\Sequential4;
use OpenCCK\Kalman\Domain\Covariance\SquareRoot;
use OpenCCK\Kalman\Domain\Covariance\UD;
use OpenCCK\Kalman\Domain\Entity\Backend;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\FilterForm;
use OpenCCK\Kalman\Domain\Exception\UnsupportedOperation;

/**
 * Chooses the covariance representation for a FilterForm (and §6.3 backend).
 */
final class CovarianceFactory
{
	private function __construct()
	{
	}

	/**
	 * @param bool $unrolled use the generated loop-free kernels for n = 2, 4 (Sequential form only)
	 * @throws UnsupportedOperation Backend::Blas without ext-ffi / OpenBLAS, or with a non-Sequential form
	 */
	public static function create(FilterForm $form, int $n, bool $unrolled = true, Backend $backend = Backend::Php): CovarianceRepresentation
	{
		if ($backend === Backend::Blas) {
			if ($form !== FilterForm::Sequential) {
				throw new UnsupportedOperation('The BLAS backend supports the Sequential form only');
			}
			return new BlasDense($n);
		}
		if ($form === FilterForm::Sequential && $unrolled) {
			if ($n === 2) {
				return new Sequential2();
			}
			if ($n === 4) {
				return new Sequential4();
			}
		}
		return match ($form) {
			FilterForm::Sequential => new DenseSequential($n),
			FilterForm::Joseph => new Joseph($n),
			FilterForm::UD => new UD($n),
			FilterForm::SquareRoot => new SquareRoot($n),
		};
	}

	public static function fromConfig(FilterConfig $config, int $n): CovarianceRepresentation
	{
		return self::create($config->form, $n, $config->unrolledKernels, $config->backend);
	}
}
