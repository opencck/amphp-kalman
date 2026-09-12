# CLAUDE.md

Guidance for coding agents working in this repository.

**Read [BRIEF.md](BRIEF.md) first — it is the project context.** Scope,
architecture, performance targets, testing strategy, status, repository map,
glossary and the working agreements all live there, and it is kept current.
This file is only the short entry point: what to load, what never to break,
and which commands to run.

## Where to look

| Question | File |
|---|---|
| What is this project, how is it laid out, what is done | [BRIEF.md](BRIEF.md) |
| The mathematics, and which class implements it | [docs/theory.md](docs/theory.md) |
| The AMPHP layer: topology, back-pressure, workers, shutdown | [docs/async.md](docs/async.md) |
| A specific market model | [docs/models](docs/models/README.md) |
| What the library can measure, and which example shows it | [examples/README.md](examples/README.md) |
| The metric/indicator layer: theory, formulas, phases | [ROADMAP.md](ROADMAP.md) (Russian) |
| Why something is the way it is | [docs/decisions](docs/decisions) (ADR-001…012) |
| Installation, quick starts, measured performance | [README.md](README.md) |

For anything about the **AMPHP v3 API** — constructor signatures, namespaces,
common mistakes — use the `opencck/skill-amphp` skill. It is the authority
there; never guess a signature. BRIEF.md is the authority on architecture,
docs/theory.md on the mathematics.

## Rules that must never be broken

The full list with rationale is [BRIEF.md §2](BRIEF.md#2-non-negotiable-rules).
The ones violated most easily:

1. `declare(strict_types=1)` in every file; `JSON_THROW_ON_ERROR` in every
   `json_encode` / `json_decode`.
2. No blocking I/O in the event loop; no AMPHP v2 patterns (`yield $promise`,
   `Promise`, `Amp\Loop::run()`).
3. **A filter step is atomic and synchronous** — no `await()` anywhere inside
   `predict()` / `correct()`. Asynchrony lives around the filter, never inside.
4. **No `Amp\` import in `src/Domain` or `src/App`.** Only
   `src/Infrastructure` may touch AMPHP.
5. Matrices are flat row-major `array<int, float>`; nested arrays are forbidden
   in the hot core. Symmetry of P is maintained exactly (upper triangle +
   mirror). No matrix inversion in production code.
6. Zero allocations in the hot loop; `dt` comes from exchange timestamps, never
   from a clock.
7. Numeric kernels: in a reused product put the constant **last**
   (`$q * $dt2 * 0.5`), and never accumulate into an array element with a
   computed index inside a loop nest — accumulate into a local and store once.
   PHP 8.2/8.3 JITs miscompile both shapes (ADR-007).
8. Every numerical algorithm gets a reference test against a naive textbook
   implementation, tolerance ≤ 1e-9. A flaky numerical test is a real bug: find
   the loss of symmetry or positive definiteness, do not widen the tolerance.

## Commands

```bash
composer test            # full suite (752 tests), zend.assertions=1
composer test:fast       # without @group slow
composer test:blas       # BLAS backend; needs ext-ffi + KALMAN_BLAS_LIB (tools/fetch-openblas.php)
composer analyse         # PHPStan level 9 + Psalm errorLevel 1 — both must stay clean
composer bench           # each benchmark in its own process → bench/results/latest.json
composer bench:baseline  # save/merge bench/results/baseline.json
```

Benchmarks need the JIT (`opcache.jit=1255`) — the composer scripts set it,
together with the raised trace limits and, on Windows, a per-process
`opcache.cache_id` (ADR-008).

## Before finishing a change

- `composer test` and `composer analyse` are green (the latter runs PHPStan
  *and* Psalm; there is no baseline file, so fix findings rather than hide them,
  and give every `@psalm-suppress` a one-line reason — ADR-009).
- A `CHANGELOG.md` entry exists.
- An architectural decision has an ADR in `docs/decisions/`.
- Documentation is bilingual: every user-facing page has an English file and a
  `.ru.md` twin, cross-linked in the second line — change both, or neither.
  BRIEF.md, CLAUDE.md and the ADRs are not translated.
