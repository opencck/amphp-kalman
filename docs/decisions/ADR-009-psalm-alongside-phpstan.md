# ADR-009 — Psalm at errorLevel 1 alongside PHPStan level 9

**Status:** accepted

## Context

ADR-004 chose PHPStan over Psalm when the toolchain was set up, because Psalm 5
did not support the PHP version matrix the project needed and one analyser was
enough to get started. The code has since been written against PHPStan level 9
throughout.

Running Psalm 6 at `errorLevel="1"` (its strictest) over the finished tree
found **821 issues that PHPStan level 9 does not report**. They fell into three
groups:

1. **Categorically inapplicable to this project (542).** `#[\Override]`
   attributes (PHP 8.3, the package supports 8.2), strict binary operands
   (`3.0 - $n` in numerical code), and property references (`$P = &$this->P`,
   the documented hot-loop idiom of BRIEF §2 rule 12, which Psalm states it
   "cannot analyze" rather than claiming is wrong).
2. **Environment (46).** ext-ffi and ext-pcntl were not loaded in the analysis
   process, so `FFI\CData` and `SIGINT` looked undefined.
3. **Real findings (233).** Unused variables and parameters, dead
   initialisers, `array_key_first()` used as an offset without a null check,
   `getmypid()`/`preg_match_all()`/`glob()` false returns fed into string
   operations, array shapes that had lost a key (`rows?`) as they were passed
   along, an `array_map` callable declared with no parameter, and the whole
   "mixed" family where untyped input was carried further than it should be.

Psalm and PHPStan disagree productively: PHPStan is stronger on generics and
conditional types, Psalm on unused code, nullability of built-in returns and
exact array shapes. Neither is a superset.

## Decision

1. **Both analysers run, and `composer analyse` runs both** (`analyse:phpstan`
   then `analyse:psalm`). CI's static-analysis job calls `composer analyse`, so
   a regression in either one fails the build.
2. **Psalm runs at `errorLevel="1"`** with `findUnusedVariablesAndParams`,
   `findUnusedPsalmSuppress` and `findUnusedBaselineEntry` on, and
   `findUnusedCode` off (a library's public API is unused from inside the
   package by definition). `phpVersion` is pinned to 8.2 — the oldest engine
   the package supports.
3. **No baseline file.** Everything was fixed or explicitly suppressed with a
   written reason; a baseline would let new findings hide behind old ones.
4. **Four project-wide suppressions, each documented in `psalm.xml`:**
   `MissingOverrideAttribute` (needs PHP 8.3), `UnsupportedPropertyReferenceUsage`
   (the hot-loop reference idiom), `UnnecessaryVarAnnotation` (those annotations
   exist because PHPStan needs them), and `RedundantCastGivenDocblockType` (the
   casts sit on data-entry boundaries where the docblock is a contract, not a
   fact — one stray int in a float matrix breaks the bit-identity assertions
   the golden and worker tests rely on). `strictBinaryOperands` stays off for
   the same "numeric code, no defect caught" reason.
5. **`src/`, `bench/`, `examples/` and `tools/` carry no other suppression.**
   Five inline `@psalm-suppress` remain, each with a one-line reason: the FFI
   `addr()` dim-fetch that must stay inline, the raw-CData benchmark kernel,
   the two by-reference drain fibers Psalm cannot model, and the batch-evaluator
   closures.
6. **`tests/` relaxes five "untyped input" issue types** (`MixedAssignment`,
   `MixedOperand`, `MixedArrayAccess`, `MixedArgumentTypeCoercion`,
   `PossiblyUndefinedStringArrayOffset`). Tests read keys out of serialised
   configs and snapshots and assert on what they find; wrapping every read in
   `isset()` would make them *weaker*, because a missing key must fail the
   assertion rather than skip it.
7. **`tools/psalm/pcntl.phpstub`** declares the signal constants: Psalm's
   `enableExtensions` list has no entry for pcntl, and the examples use the
   constants behind an `extension_loaded()` guard.

## Consequences

- Type inference coverage is **99.79 %**; both analysers report zero issues.
- Fixing group 3 changed behaviour in a few places for the better: the LRU
  eviction in `KalmanFilter`/`DenseLinear` no longer risks a null offset, the
  IMM no longer indexes `$logLik[0]`, `NelderMead` validates the four-point
  batch its coroutine receives, `ScalingBench`'s warm-up is an explicit loop,
  and the `LayoutBench` kernels now return a checksum so the measured work
  cannot be optimised away.
- ADR-004's "PHPStan instead of Psalm" is superseded: the answer is both.
- Contributors need `ext-ffi` available (or accept that the BLAS backend is
  analysed through Psalm's bundled stubs) and must keep `composer analyse`
  green, not just PHPStan.
