<?php declare(strict_types=1);

/**
 * Backtest of the local linear trend model on a tick file (§8 Phase 6 example):
 * filter → RTS smoother → report (NIS, NEES when a truth column exists,
 * filter vs smoother RMSE and lag). Optionally re-estimates Q, R with EM first.
 *
 *   php examples/backtest-trend.php --synthetic [--em]
 *   php examples/backtest-trend.php history.jsonl [--em]
 *
 * JSON lines: {"ts": <ns>, "values": {"0": price}} — optional "truth": [p, v].
 */

use OpenCCK\Kalman\App\Backtest\Engine;
use OpenCCK\Kalman\App\Calibration\ExpectationMaximization;
use OpenCCK\Kalman\Domain\Entity\FilterConfig;
use OpenCCK\Kalman\Domain\Entity\GatingPolicy;
use OpenCCK\Kalman\Domain\Factory\FilterFactory;
use OpenCCK\Kalman\Domain\Model\Finance\LocalLinearTrend;
use OpenCCK\Kalman\Infrastructure\Ingest\HistoryReader;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */

$args = \array_slice($argv, 1);
$synthetic = \in_array('--synthetic', $args, true);
$useEm = \in_array('--em', $args, true);
$files = \array_values(\array_filter($args, static fn (string $a): bool => !\str_starts_with($a, '--')));
$path = $synthetic ? \sys_get_temp_dir() . '/trend-history.jsonl' : ($files[0] ?? null);
if ($path === null) {
	\fwrite(\STDERR, "usage: php examples/backtest-trend.php --synthetic [--em] | history.jsonl [--em]\n");
	exit(1);
}

if ($synthetic) {
	\mt_srand(11);
	$gauss = static function (): float {
		$u = \mt_rand(1, \mt_getrandmax()) / \mt_getrandmax();
		$v = \mt_rand(1, \mt_getrandmax()) / \mt_getrandmax();
		return \sqrt(-2.0 * \log($u)) * \cos(2.0 * \M_PI * $v);
	};
	$p = 100.0;
	$vel = 0.0;
	$ts = 0;
	$lines = [];
	for ($k = 0; $k < 20_000; $k++) {
		$ts += 100_000_000;
		$vel += 0.5 * \sqrt(0.1) * $gauss();
		$p += 0.1 * $vel;
		$lines[] = \json_encode(['ts' => $ts, 'values' => ['0' => $p + 0.05 * $gauss()], 'truth' => [$p, $vel]], \JSON_THROW_ON_ERROR);
	}
	\Amp\File\write($path, \implode("\n", $lines) . "\n");
	\fwrite(\STDOUT, "synthetic history written to $path (σ_a = 0.5, σ_r = 0.05)\n");
}

// read ticks, keeping the optional truth column
$ticks = [];
$file = \Amp\File\openFile($path, 'r');
foreach (\Amp\ByteStream\splitLines($file) as $line) {
	if (\trim($line) === '') {
		continue;
	}
	/** @var array{ts: int, values: array<string, float>, truth?: array<int, float>} $row */
	$row = \json_decode($line, true, 8, \JSON_THROW_ON_ERROR);
	$values = [];
	foreach ($row['values'] as $ch => $v) {
		$values[(int) $ch] = (float) $v;
	}
	$tick = ['ts' => $row['ts'], 'values' => $values];
	if (isset($row['truth'])) {
		$tick['truth'] = \array_map('floatval', $row['truth']);
	}
	$ticks[] = $tick;
}
$file->close();
\fwrite(\STDOUT, \sprintf("%d ticks\n", \count($ticks)));

$llt = new LocalLinearTrend(sigmaA: 1.0, halfSpread: 0.1);   // deliberately mis-specified start
$config = [
	'motion' => $llt->motion()->toArray(),
	'observation' => $llt->observation()->toArray(),
	'x0' => [$ticks[0]['values'][0], 0.0],
	'P0' => [$llt->measurementVariance(), 0.0, 0.0, 1.0],
];

if ($useEm) {
	$t0 = \hrtime(true);
	$em = ExpectationMaximization::fit($config, \array_map(static fn (array $t): array => ['ts' => $t['ts'], 'values' => $t['values']], $ticks), iterations: 20);
	$config = $em['config'];
	\fwrite(\STDOUT, \sprintf(
		"EM (20 it, %.1fs): R = %.5f (truth 0.0025), Q_vv/step = %.5f (truth %.5f), logL %.1f → %.1f\n",
		(\hrtime(true) - $t0) / 1e9,
		$em['R'][0],
		$em['Q'][3],
		0.25 * 0.1,
		$em['logLikelihood'][0],
		$em['logLikelihood'][19],
	));
}

$filter = FilterFactory::fromConfig($config, FilterConfig::default()->withGating(GatingPolicy::chiSquare(0.001))->toArray());
$run = (new Engine($filter))->run($ticks);
\fwrite(\STDOUT, $run['report']->toJson() . "\n");
