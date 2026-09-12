<?php declare(strict_types=1);

/**
 * Maximum-likelihood calibration of a pairs-trading model on a history file
 * (§8 Phase 5 example): q_α, q_β, σ_ε via Nelder–Mead with the simplex
 * evaluated on a worker pool.
 *
 *   php examples/calibrate-pair.php --synthetic [workers]     # generate 20k synthetic ticks, then calibrate
 *   php examples/calibrate-pair.php history.jsonl [workers]   # your own history
 *
 * History line format (JSON lines): {"ts": <ns>, "values": {"0": y_t}, "rows": {"0": {"0": 1, "1": x_t}}}
 * — the regressor x_t travels as the per-tick observation row H_t = [1, x_t], so
 * the likelihood is exact and fully serialisable across worker processes
 * (PairsHedge::tick() builds such ticks).
 */

use Amp\Parallel\Worker\ContextWorkerPool;
use OpenCCK\Kalman\App\Calibration\NelderMead;
use OpenCCK\Kalman\App\Calibration\Parametrization;
use OpenCCK\Kalman\Domain\Model\Finance\PairsHedge;
use OpenCCK\Kalman\Infrastructure\Ingest\HistoryReader;
use OpenCCK\Kalman\Infrastructure\Task\ParallelCalibrator;

require __DIR__ . '/bootstrap.php';

/** @var list<string> $argv */

$args = \array_slice($argv, 1);
$synthetic = \in_array('--synthetic', $args, true);
$positional = \array_values(\array_filter($args, static fn (string $a): bool => !\str_starts_with($a, '--')));
$path = $synthetic ? \sys_get_temp_dir() . '/pair-history.jsonl' : ($positional[0] ?? null);
$workers = (int) ($positional[$synthetic ? 0 : 1] ?? 8);
if ($path === null) {
	\fwrite(\STDERR, "usage: php examples/calibrate-pair.php --synthetic [workers] | history.jsonl [workers]\n");
	exit(1);
}

$pairs = new PairsHedge(qAlpha: 1e-5, qBeta: 1e-7, sigmaEps: 0.2);   // starting guess, 10× off

if ($synthetic) {
	\mt_srand(7);
	$gauss = static function (): float {
		$u = \mt_rand(1, \mt_getrandmax()) / \mt_getrandmax();
		$v = \mt_rand(1, \mt_getrandmax()) / \mt_getrandmax();
		return \sqrt(-2.0 * \log($u)) * \cos(2.0 * \M_PI * $v);
	};
	$alpha = 0.5;
	$beta = 1.2;
	$x = 100.0;
	$ts = 0;
	$ticks = [];
	for ($k = 0; $k < 20_000; $k++) {
		$ts += 1_000_000_000;
		$alpha += 1e-3 * $gauss();          // √(1e-6 · 1 s)
		$beta += 1e-4 * $gauss();           // √(1e-8 · 1 s)
		$x += 0.2 * $gauss();
		$y = $alpha + $beta * $x + 0.05 * $gauss();
		$ticks[] = $pairs->tick($ts, $y, $x);
	}
	HistoryReader::write($path, $ticks);
	\fwrite(\STDOUT, "synthetic history written to $path (truth: q_α=1e-6, q_β=1e-8, σ_ε=0.05)\n");
}

$config = $pairs->config(alpha0: 0.0, beta0: 1.0, alphaPriorStd: 1.0, betaPriorStd: 0.5);
$param = new Parametrization(['motion.qAlpha' => 'log', 'motion.qBeta' => 'log', 'observation.variances.0' => 'log']);

\fwrite(\STDOUT, "reading {$path} … ");
$history = HistoryReader::readAll($path);
\fwrite(\STDOUT, \sprintf("%d ticks, %d workers\n", \count($history), $workers));

$pool = new ContextWorkerPool(limit: \max(1, $workers));
$t0 = \hrtime(true);
$result = (new ParallelCalibrator($param, $pool, new NelderMead(tolerance: 1e-5, maxIterations: 80)))->calibrate($config, null, $path);
$elapsed = (\hrtime(true) - $t0) / 1e9;
$pool->shutdown();

$num = static fn (mixed $v): float => \is_int($v) || \is_float($v) ? (float) $v : throw new \RuntimeException('numeric expected');
/** @var array<string, mixed> $config */
$config = $result['config'];
$motion = [];
$obs = [];
$variances = [];
if (isset($config['motion']) && \is_array($config['motion'])) {
	$motion = $config['motion'];
}
if (isset($config['observation']) && \is_array($config['observation'])) {
	$obs = $config['observation'];
}
if (isset($obs['variances']) && \is_array($obs['variances'])) {
	$variances = $obs['variances'];
}
\fwrite(\STDOUT, \sprintf(
	"q_α = %.3e   q_β = %.3e   σ_ε = %.4f   logL = %.2f   (%d evaluations, %d iterations, %.1fs, converged=%s)\n",
	$num($motion['qAlpha'] ?? null),
	$num($motion['qBeta'] ?? null),
	\sqrt($num($variances[0] ?? null)),
	$result['logLikelihood'],
	$result['evaluations'],
	$result['iterations'],
	$elapsed,
	$result['converged'] ? 'yes' : 'no',
));
