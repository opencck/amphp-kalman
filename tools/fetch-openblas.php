<?php declare(strict_types=1);

/**
 * Downloads a prebuilt OpenBLAS for the BLAS backend (ADR-006) into
 * tools/openblas/ so that BlasDenseTest and the blas_n* layout benchmarks run
 * locally. Windows: the official x64 zip from GitHub releases (libopenblas.dll
 * + its MinGW runtime DLLs). Linux/macOS: install the distribution package
 * instead (libopenblas0 / openblas) — this script only prints the command.
 *
 *   php tools/fetch-openblas.php [--version=0.3.34]
 *
 * Then:
 *   set KALMAN_BLAS_LIB=%CD%\tools\openblas\libopenblas.dll
 *   php -dextension=ffi -dffi.enable=1 vendor/bin/phpunit --filter BlasDenseTest
 *
 * A plain CLI tool: blocking I/O is fine here.
 */

$options = \getopt('', ['version::']);
$target = __DIR__ . '/openblas';

if (\PHP_OS_FAMILY !== 'Windows') {
	\fwrite(\STDOUT, "Non-Windows host: install OpenBLAS with your package manager, e.g.\n"
		. "  sudo apt-get install libopenblas0      # Debian/Ubuntu\n"
		. "  brew install openblas                   # macOS\n"
		. "and point KALMAN_BLAS_LIB at the shared library if it is not on the default search path.\n");
	exit(0);
}

$version = isset($options['version']) && \is_string($options['version']) ? $options['version'] : null;
$context = \stream_context_create(['http' => ['header' => "User-Agent: opencck/amphp-kalman fetch-openblas\r\n", 'timeout' => 60]]);
if ($version === null) {
	$json = \file_get_contents('https://api.github.com/repos/OpenMathLib/OpenBLAS/releases/latest', false, $context);
	if ($json === false) {
		\fwrite(\STDERR, "could not query GitHub releases; pass --version=0.3.34\n");
		exit(1);
	}
	/** @var array{tag_name?: string} $release */
	$release = \json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
	$version = \ltrim($release['tag_name'] ?? '', 'v');
	if ($version === '') {
		\fwrite(\STDERR, "unexpected GitHub response\n");
		exit(1);
	}
}
// the plain x64 build (32-bit integer interface — what the cblas prototypes in BlasBackend declare), not x64-64
$asset = "OpenBLAS-{$version}-x64.zip";
$url = "https://github.com/OpenMathLib/OpenBLAS/releases/download/v{$version}/{$asset}";
$zipPath = \sys_get_temp_dir() . '/' . $asset;

\fwrite(\STDOUT, "downloading $url\n");
$in = \fopen($url, 'rb', false, $context);
if ($in === false) {
	\fwrite(\STDERR, "download failed\n");
	exit(1);
}
$out = \fopen($zipPath, 'wb');
if ($out === false) {
	\fwrite(\STDERR, "cannot write $zipPath\n");
	exit(1);
}
\stream_copy_to_stream($in, $out);
\fclose($in);
\fclose($out);

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
	\fwrite(\STDERR, "cannot open $zipPath\n");
	exit(1);
}
if (!\is_dir($target) && !\mkdir($target, 0777, true)) {
	\fwrite(\STDERR, "cannot create $target\n");
	exit(1);
}
$copied = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
	$name = $zip->getNameIndex($i);
	if ($name === false || !\str_ends_with(\strtolower($name), '.dll')) {
		continue;
	}
	$data = $zip->getFromIndex($i);
	if ($data === false) {
		continue;
	}
	\file_put_contents($target . '/' . \basename($name), $data);
	$copied++;
}
$zip->close();
\unlink($zipPath);

$dll = $target . '/libopenblas.dll';
\fwrite(\STDOUT, "$copied DLL(s) in $target\n");
if (!\is_file($dll)) {
	\fwrite(\STDERR, "libopenblas.dll not found in the archive\n");
	exit(1);
}
\fwrite(\STDOUT, "set KALMAN_BLAS_LIB=" . \str_replace('/', '\\', $dll) . "\n"
	. "php -dextension=ffi -dffi.enable=1 vendor/bin/phpunit --filter BlasDenseTest\n");
