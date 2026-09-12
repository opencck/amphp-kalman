# ADR-006 — Optional OpenBLAS backend through ext-ffi

**Status:** accepted (Phase 8)

## Context

§1.3 caps the pure-PHP dense step at n = 32 (≤ 300 µs) and delegates larger
states to an optional FFI backend (§6.3). The measured pure-PHP numbers
(`FormBench`, JIT 1255, PHP 8.3, one core) are

| n | Sequential step | flat F·P·Fᵀ only (`LayoutBench`) |
|---|---|---|
| 16 | 53 µs | 29 µs |
| 32 | 363 µs | 219 µs |
| 64 | 2 662 µs | 1 751 µs |

i.e. the O(n³) predict dominates from n ≈ 16 and grows ~8× per doubling.
An FFI call costs ~0.5 µs, so BLAS-1 calls per element are pointless, but a
single `dgemm` replacing a 64³ PHP loop is not.

## Decision

1. `Domain\Linalg\Ffi\BlasBackend` binds eight CBLAS symbols (`dgemm`, `dsymm`,
   `dsymv`, `dsyr`, `dger`, `daxpy`, `dcopy`, `ddot`) with `FFI::cdef`. No compilation, no header files;
   the library is resolved from `KALMAN_BLAS_LIB`, then platform defaults
   (`libopenblas.so.0`, `.so`, `.dylib`, `.dll`). `isAvailable()` never throws.
2. `Domain\Covariance\BlasDense` implements `CovarianceRepresentation` with P
   in an FFI `double[]` buffer whose upper triangle is authoritative: predict =
   `dsymm` + `dgemm` + `daxpy`, φ = `dsymv`, downdate = `dsyr` (see the
   implementation notes below). The two-phase scalar protocol (ADR-003) is
   unchanged, so `KalmanFilter` does not know which backend runs.
3. Opt-in only: `FilterConfig::withBackend(Backend::Blas)`; Sequential form
   only (UD / square-root are triangular algorithms with no BLAS-3 shape).
   Without ext-ffi or a library the factory throws `UnsupportedOperation`
   with the probe log — never a silent fallback, because the two backends
   are not bit-identical.
4. `composer.json` lists `ext-ffi` under `suggest`, never `require`.
5. Domain purity: FFI is a language feature, not I/O; the class lives in
   `Domain\Linalg\Ffi` and the architecture test allows `\FFI` there only.

## Implementation notes (after testing against OpenBLAS 0.3.34)

- `dgemm` writes a full matrix whose two halves may differ in the last bit and
  `dger` would preserve that, so only the **upper triangle of P is
  authoritative**: `dsymm` (T = F·P), `dsymv` (φ = P·h), `dsyr` (rank-1
  downdate) read and write the upper half only, `toDense()` mirrors it. Exact
  symmetry with no PHP O(n²) loop per channel.
- The PHP → FFI copies of `F` and `Q` are the only O(n²) PHP work per step;
  they are skipped while the model hands the same arrays (a stationary model
  with the dt cache — the common case).
- `tools/fetch-openblas.php` downloads the official Windows x64 build (the
  plain `-x64` archive with the 32-bit integer interface, which matches the
  declared prototypes — not `-x64-64`); `composer test:blas` runs the tests
  with `KALMAN_BLAS_LIB` pointing at it. Linux/macOS use the distribution
  package; the CI job `blas` (Ubuntu, `libopenblas0`, `extensions: ffi`) runs
  `BlasDenseTest` and the benchmarks.

## Crossover (measured)

`LayoutBench` — F·P·Fᵀ only, µs per product (PHP 8.3, JIT 1255, one core):

| n | flat PHP | FFI buffers + PHP loops | OpenBLAS `dgemm` ×2 |
|---|---|---|---|
| 2 | 0.10 | 0.41 | 2.1 |
| 4 | 0.59 | 2.7 | 2.2 |
| 8 | 4.0 | 17.5 | 2.4 |
| 16 | 29 | 128 | 3.8 |
| 32 | 229 | 985 | 15 |
| 64 | 1 747 | 7 711 | 102 |
| 128 | 14 905 | — | 691 |
| 256 | 151 141 | — | 1 834 |

`FormBench` — full step (predict + m = n/2 channels), Sequential form:

| n | pure PHP | `Backend::Blas` |
|---|---|---|
| 8 | 9.2 µs | 13.7 µs |
| 16 | 53 µs | 28 µs |
| 32 | 363 µs | 76 µs |
| 64 | 2 662 µs | 832 µs |
| 128 | 21 815 µs | 6 077 µs |

Break-even for the whole step is n ≈ 12; at n = 32 the backend is 4.8× faster
and the §1.3 target (≤ 300 µs) is met with room to spare. Above n = 64 the
gain settles at ~3× while `dgemm` alone is 17× faster — the remaining cost is
the per-channel correction (m = n/2 channels × two BLAS-2 calls with FFI call
overhead, plus the `gain()` copy) and the model's own F/Q construction; for
those sizes reduce m through channel aggregation or use the information form.
PHP loops over FFI buffers are 4× *slower* than flat arrays at every n:
element access goes through the CData handler.

## Consequences

- Users with n > 32 get an order-of-magnitude path without a C toolchain.
- `toDense()` / `gain()` copy out of FFI memory (O(n²) / O(n)); snapshot
  frequency matters more with this backend.
- `predictSparse` round-trips through PHP arrays — sparse models (ETF basket)
  gain nothing from BLAS; use it for dense F only.
