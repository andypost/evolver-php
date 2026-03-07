# Benchmarks

This document describes how to run performance benchmarks and summarizes key findings from optimizing Evolver.

## Running Benchmarks

Maintained benchmark scripts:

- `benchmarks/storage-bench.php` for serialization and SQLite micro-benchmarks
- `benchmarks/baseline-index-bench.php` for the current end-to-end indexing path

Generated benchmark outputs are not tracked in git. Save JSON results to a temp path explicitly when needed.

### Using Docker (Recommended)

```bash
# Build and start the container
make build
make up

# Run storage benchmark
make e -- php /app/benchmarks/storage-bench.php

# Run indexing benchmark
make e -- php /app/benchmarks/baseline-index-bench.php /app/src 1,4 /tmp/baseline-results.json

# Run profiling suite
make profile EXTRA_HOST_PATH=../drupal
make profile-report
```

### Local (requires tree-sitter libraries)

```bash
# Install dependencies
composer install

# Compile grammars
make

# Run benchmark
php benchmarks/storage-bench.php

# Run indexing benchmark
php benchmarks/baseline-index-bench.php src 1,4 /tmp/baseline-results.json
```

## Key Findings

Ad hoc prototype benchmark scripts and checked-in result snapshots were removed after the architecture work stabilized. The benchmark record now lives in this document instead of repo-local output files.

### Current Runtime Findings (2026-03-07)

- **Current production path:** `pcntl_fork` workers extract payloads and the parent process performs batched SQLite merges. This removed the old shared-write locking bottleneck.
- **Best indexing runtime on `/app/src`:** `pcntl_fork`, 4 workers, `0.079s` avg, `611.0 files/sec`, `362` symbols.
- **Without `pcntl`:** the `index --workers 4` command remains functional but falls back to sequential indexing.
- **AMPHP research outcome:** AMPHP was evaluated as a `pcntl` fallback and rejected. On `/app/src`, the real command averaged about `0.30s` with an AMPHP 4-worker fallback, versus about `0.20s` for true sequential no-`pcntl` indexing and about `0.14s` with `pcntl` at 4 workers.
- **Reason for rejection:** the worker startup path goes through `proc_open()` via `amphp/process`, and that overhead dominated this workload enough that AMPHP lost even to the sequential fallback.
- **Swoole:** The coroutine benchmark now runs correctly with the Docker `io_uring` fix and without manual Tree-sitter resets, but it remains much slower for indexing on this workload, about `46.8 files/sec` at 4 workers.
- **Tree-sitter FFI:** `FFIBinding` is now fork-aware. Fresh child parsers work without manual `FFIBinding::reset()`, while inherited parent parsers are intentionally rejected after fork.
- **Leak signal:** The standalone meminfo leak probe on `/app/src` showed `0` post-warmup real allocator growth and `728` bytes post-warmup logical heap growth across 3 iterations.

### 1. JSON vs igbinary Serialization

| Approach | Time/op | Notes |
|----------|---------|-------|
| **igbinary** | **0.85 ms** | ~3x faster than serialize |
| serialize | 2.86 ms | PHP native |

**Decision:** Use `ext-igbinary` (required in composer.json) for worker IPC in `VersionDiffer::matchRenamesParallel()`.

### 2. SQLite JSON Functions vs PHP Decode

| Approach | Time/op |
|----------|---------|
| **SQLite json_extract()** | **1.44 ms** |
| PHP SELECT * + json_decode | 2.86 ms |
| **SQLite WHERE json_array_length()** | **0.93 ms** |
| PHP fetchAll + decode + filter | 5.58 ms |

**Decision:** Use SQLite's `json_extract()` and `json_array_length()` for filtering and partial field reads.

### 3. Set-Difference Query Strategies

| Strategy | Time/op |
|----------|---------|
| **SQL EXCEPT** | **35% faster** |
| SQL NOT EXISTS | baseline |
| PHP hash-map | slower at scale (data transfer overhead) |

**Decision:** Use `EXCEPT` queries in `findRemovedSymbols()` and `findAddedSymbols()`.

### 4. Database Index Impact

Added covering index `idx_sym_version_fqn_type_hash` on `symbols(version_id, fqn, symbol_type, signature_hash)`:
- Enables index-only scans for signature change detection
- Reduces I/O for common diff queries

## Real-World Performance

### Full Core Index (11.0.0)

| Metric | Value |
|--------|-------|
| Files scanned | 10,168 |
| Files indexed (PHP/YAML) | ~6,000 |
| Symbols extracted | ~30,000 |
| Index time | ~8 seconds |
| Database size | ~85 MB |

### Version Diff (10.0.0 → 11.0.0)

| Metric | Value |
|--------|-------|
| Changes detected | 9,146 |
| Rename matches | 2,341 |
| Processing time | ~73 seconds |
| Max RSS | 74 MB |
| Workers | 4 (parallel) |

## Optimization Opportunities

### Completed

- ✅ Version weight column (precomputed, avoids repeated expressions)
- ✅ Prepared statement caching (reduces parse overhead)
- ✅ SQLite json_extract() for partial reads
- ✅ igbinary for worker IPC
- ✅ EXCEPT for set-difference queries
- ✅ Covering index on symbols table
- ✅ Batch inserts for matches
- ✅ RenameMatcher optimizations:
  - Pre-grouping by language/type/signature/name
  - Early exit with high thresholds (0.95, 0.9)
  - Candidate limits for heuristic matching
  - Pre-computed string lengths (avoid repeated strlen())
  - Skipped source similarity for low-confidence matches

### Potential Future Optimizations

1. **RenameMatcher further optimization** - Current bottleneck is O(n*m) string comparison
   - Consider symmetric delete distance for faster approximate matching
   - Could use phonetic algorithms (metaphone, soundex) for name similarity
   - Cache similarity results for duplicate comparisons

2. **Parallel index tuning** - Current architecture is stable, but worker count and payload sizing could still be tuned per workload
   - Current best small-workload result is 4 workers on `/app/src`
   - Large tree measurements should continue to drive defaults, not the small benchmark alone

3. **Incremental indexing** - Index only changed files
   - Requires git integration or file hash tracking
   - Would speed up re-indexing of same version

4. **Worker pool reuse** - Avoid forking workers on every diff
   - Could use persistent worker processes
   - Reduces process creation overhead

## Profiling

Enable profiling extensions in Docker:

```bash
# Available profilers:
# - meminfo: memory usage per function
# - memprof: memory profiling with graphs
# - xhprof: CPU and memory profiling
# - spx: low-overhead profiler

# SPX is available on demand and writes raw reports into /tmp/spx
# meminfo is loaded by default and is the better first tool for leak checks
```
