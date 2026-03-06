# Benchmarks

This document describes how to run performance benchmarks and summarizes key findings from optimizing Evolver.

## Running Benchmarks

### Using Docker (Recommended)

```bash
# Build and start the container
make build
make up

# Run storage benchmark
make e -- php /app/benchmarks/storage-bench.php

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
```

## Key Findings

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

2. **Parallel index** - Already implemented, but could tune chunk size
   - Current: 500 symbols per chunk in rename matching
   - Could make adaptive based on file size

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

# To enable a profiler, uncomment its ini in Dockerfile
# Then use make profile to run the suite
```
