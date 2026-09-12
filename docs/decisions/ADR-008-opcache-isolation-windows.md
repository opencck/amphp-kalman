# ADR-008 — One OPcache segment per process family (Windows)

**Status:** accepted (Phase 8)

## Context

While recording the Phase 8 baseline the same benchmark gave 1.3 µs when run
alone and 4.9 µs when run after other benchmarks — in a *separate child
process* each time. Neither the tracing-JIT trace budget, ext-ffi, background
execution nor CPU thermals explained it. A fresh `php` started while a
benchmark batch was running reported 255 cached scripts and a partly used JIT
buffer in `opcache_get_status()`: **on Windows, CLI processes started with the
same binary and ini attach to one OPcache shared-memory segment** (file
mapping named after the binary/ini/user), and that segment holds the tracing
JIT's state — root and side traces, exit counters, blacklists — as well as the
compiled code.

Consequences of sharing:

- traces specialised for the first process that made a loop hot are reused by
  every later process; when its types or call targets differ (another
  covariance form, another observation class) the compiled trace exits to the
  interpreter on every iteration — 3–8× slower on small kernels, invisible in
  `opcache_get_status()`;
- the per-segment trace budget (`opcache.jit_max_root_traces`, default 1024)
  is consumed by all processes together; raising it did not help here because
  the problem is specialisation, not exhaustion.

Linux/macOS CLI processes each map their own segment, so this is
Windows-specific, but Windows is where the library is developed and where
worker pools are most likely to be launched from an IDE.

## Decision

1. `Infrastructure\Task\WorkerPools::binary(bool $isolateOpcache = true, ?string $cacheId = null)`
   appends `-dopcache.cache_id=<id>` on Windows (default `kalman-<parent pid>`),
   so a worker pool gets its own segment, separate from the parent and from
   other pools. Workers of one pool still share a segment with each other
   (same code paths, wanted).
2. `bench/run.php` runs every benchmark in a child with
   `opcache.cache_id=bench-<name>-<pid>`; `--no-isolate` keeps the old
   in-process mode for debugging.
3. The JIT budget flags (`jit_max_root_traces`, `jit_max_side_traces`,
   `jit_max_exit_counters`) are propagated to children and set high in the
   `composer bench*` scripts; they are a separate, real concern for long-lived
   processes with many code paths and are documented in the README.

## Consequences

- Benchmarks are reproducible run-to-run and independent of what ran before
  (`form.sequential_n4_m2`: 1.30 µs alone, 1.30 µs in the full batch; before
  the change 4.9 µs in the batch).
- Production deployments on Windows that start several PHP processes from one
  binary should give each *kind* of process its own `opcache.cache_id`;
  `WorkerPools::likeParent()` does it for worker pools.
- The extra segments cost `opcache.memory_consumption` (+ JIT buffer) each.
