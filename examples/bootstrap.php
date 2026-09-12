<?php declare(strict_types=1);

/**
 * Bootstrap shared by every example.
 *
 * The examples ship inside the package — `.gitattributes` deliberately keeps
 * `examples/` out of the export-ignore set, because `examples/README.md` is the
 * generated catalogue of everything the library measures. That means an example
 * has to run from two layouts: this repository, where Composer's autoloader is
 * `examples/../vendor/autoload.php`, and a consumer's install, where the package
 * sits at `vendor/opencck/amphp-kalman/` and the autoloader is one directory
 * above the vendor directory's package folders instead.
 *
 * The `OpenCCK\Kalman\Examples\` namespace is declared in `autoload-dev`, which
 * a consumer never registers, so it is mapped here rather than in composer.json's
 * production `autoload`: `Support\Synthetic` is a fixture for the demos and has
 * no business in the installed package's classmap.
 */

$autoload = null;
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $candidate) {
	if (\is_file($candidate)) {
		$autoload = $candidate;
		break;
	}
}

if ($autoload === null) {
	\fwrite(\STDERR, "Composer's autoloader was not found. Run `composer install` in the package directory.\n");
	exit(1);
}

/** @psalm-suppress UnresolvableInclude — the path is picked at runtime on purpose; which of the two layouts applies is not knowable statically */
require $autoload;

// Appended after Composer's loader: in this repository autoload-dev already
// resolves the namespace and this never fires; from vendor/ it is the only one.
\spl_autoload_register(static function (string $class): void {
	$prefix = 'OpenCCK\\Kalman\\Examples\\';
	if (!\str_starts_with($class, $prefix)) {
		return;
	}
	$file = __DIR__ . '/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';
	if (\is_file($file)) {
		require $file;
	}
});
