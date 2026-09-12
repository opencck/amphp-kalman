# ADR-007 — PHP JIT miscompilations, safe kernel shapes and cross-process determinism

**Status:** accepted (Phase 8)

## Context

The library promises bit-identical results between an in-process filter and
the same filter running in a worker (`FilterBatch`, `FilterWorkerTask`,
`LikelihoodTask`), and its benchmarks require the JIT. While completing the
calibration benchmark on PHP 8.3 the results of the same code differed
between JIT modes. Bisection with a *first call vs hot call* detector (the
same kernel with the same input, compared before and after the JIT compiled
it — one scenario per process) found two miscompilations on PHP 8.2.33 and
8.3.33 (Windows x64; correct on PHP 8.4 / 8.5 and with `opcache.jit=0`):

1. **Reused `a * const * b` temporary.**

   ```php
   $q12 = $q * 0.5 * $dt2;            // also 0.5 * $q * $dt2
   return [$q * $dt2 * $dt / 3.0, $q12, $q12, $q * $dt];
   ```

   `$q12` comes out as `$dt2 * $dt2` (0.0001 instead of 0.01125 for
   `q = 2.25, dt = 0.1`). Function JIT (`1205`): always. Tracing JIT (`1255`):
   once the function is hot. `ConstantVelocity::processNoise`,
   `ClosedForm::constantVelocity`, `BidAskBounce`, `ConstantAcceleration` had
   this shape, so every CV/LLT filter ran with a wrong `Q` under the JIT while
   all invariants (symmetry, positive definiteness, NIS on its own simulation)
   still held. Writing the constant **last** (`$q * $dt2 * 0.5`) is compiled
   correctly.

2. **Accumulating a product into an array element in a loop nest.**

   ```php
   for ($p …) { $a = $A[$ik + $p]; for ($j …) { $C[$im + $j] += $a * $B[$pm + $j]; } }
   ```

   Tracing JIT (`1255`): the compiled trace returns a different matrix than the
   interpreter (data dependent — it showed on `DenseModel` inputs, not on a
   hand-written 4×4 with zeros). `Flat::multiply`, `Flat::transposedMultiply`,
   `Flat::add/subtract`, `MatrixExponential` and `Retrodiction` had this shape;
   through them the information filter, Van Loan, DARE and retrodiction were
   affected. The dot-product shape with a **local accumulator**
   (`$sum += …; $C[$im + $j] = $sum;`) is compiled correctly — and is the shape
   `DenseSequential`, UD and the square-root form already used, which is why the
   core filter never showed the problem.

3. **`amphp/parallel`'s default `workerPool()` starts children with the plain
   `php` binary and its php.ini** — a different JIT mode (or none) than the
   parent, so parent and worker disagreed exactly when the engine was buggy.

## Decision

1. **Kernel shape rules** (in addition to the project's "no calls in the hot
   loop"): in reused products put the constant last; never accumulate into an
   array element with a computed index inside a loop nest — accumulate into a
   local and store once. Applied to `Flat`, `MatrixExponential`,
   `Retrodiction`, `ConstantVelocity`, `ClosedForm`, `BidAskBounce`,
   `ConstantAcceleration`, `StochasticVolatilityLeverage`.
2. **`Domain\Diagnostics\JitSanity`.** `failures()` evaluates the shapes the
   library relies on (constant-last product, dot-product, the sequential-form
   rank-1 downdate) 96 times — past the JIT's hot thresholds — against exact
   literal results built from dyadic rationals; `verify()` throws
   `NumericalFailure` when they disagree. `engineBugs()` evaluates the two
   known-bad shapes the same way and *reports* them (the library does not use
   them). Every filter constructor and `FilterFactory::fromConfig()` call
   `verify()` once per process; `bench/run.php` prints `engineBugs()`;
   `JitSanityTest` fails on an engine that miscompiles the library's shapes.
   The check must run from a compiled file — `php -r` snippets are not
   JIT-compiled the same way.
3. **Supported configurations:** PHP ≥ 8.4 with `opcache.jit=1255`; PHP 8.2/8.3
   with `opcache.jit=1255` or `1205` *for this library's kernels* (both report
   `engineBugs()`; user code with the bad shapes is on its own); or no JIT.
   Recommended: `opcache.enable_cli=1`, `opcache.jit=1255`,
   `opcache.jit_buffer_size=128M`.
4. **Verification.** The fast test suites (unit, golden, reference, property,
   architecture, async) run under PHP 8.3 with `1255` and `1205` in addition
   to the interpreter; the golden files were produced by the interpreter, so
   they are the interpreter-vs-JIT oracle. After the rewrite the drift
   detector reports every scenario (all forms, EKF, UKF, IMM, information
   filter, RTS, fixed-lag, retrodiction, likelihood, EM, DARE, Van Loan,
   matrix exponential, LU, Householder, Cholesky) as stable, and the CV
   likelihood, filter state and Nelder–Mead result are bit-identical across
   `0`, `1205`, `1255`.
5. **Workers inherit the parent's engine.**
   `Infrastructure\Task\WorkerPools::likeParent($limit)` builds a
   `ContextWorkerPool` whose children run `php -n` with the parent's
   `extension_dir`, loaded extensions, `opcache.*` JIT flags,
   `zend.assertions` and `memory_limit`. All benchmarks and the documentation
   use it.

## Consequences

- On PHP 8.2/8.3 the library's numbers are now the same with and without the
  JIT; user-defined models must follow the two shape rules or they may not be.
- A future engine that miscompiles a library shape stops the process at the
  first filter construction with an explicit message instead of producing
  silently wrong estimates.
- Bit-identity claims in tests (`WorkerTest`, `FilterBatch` split/whole) rest
  on a same-engine guarantee rather than on luck.
- The earlier observation that `1205` gives a better UKF/KF cost ratio than
  `1255` stands, but `1255` remains the recommendation (it is the mode PHP
  itself recommends and the one CI runs).
