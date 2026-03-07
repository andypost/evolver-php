# Storage Benchmark Results

**Environment:** PHP 8.5.3, SQLite 3.51.2, Alpine Edge (Docker)
**Date:** 2026-03-06

## Serialization Speed (50K iterations)

### Encode

| Format | Small (132B) | Medium (298B) | Large (561B) | Diff (723B) |
|--------|-------------|---------------|--------------|-------------|
| json_encode | 0.0004 ms | 0.0009 ms | 0.0015 ms | 0.0020 ms |
| igbinary_serialize | 0.0004 ms | 0.0008 ms | 0.0012 ms | 0.0017 ms |
| msgpack_pack | 0.0009 ms | 0.0016 ms | 0.0030 ms | 0.0041 ms |
| serialize | 0.0004 ms | 0.0008 ms | 0.0015 ms | — |

### Decode

| Format | Small | Medium | Large | Diff |
|--------|-------|--------|-------|------|
| **json_decode** | 0.0010 ms | **0.0021 ms** | 0.0040 ms | 0.0054 ms |
| **igbinary_unserialize** | 0.0005 ms | **0.0008 ms** | 0.0014 ms | 0.0019 ms |
| msgpack_unpack | 0.0007 ms | 0.0014 ms | 0.0025 ms | 0.0032 ms |
| unserialize | 0.0006 ms | 0.0012 ms | 0.0021 ms | — |

**Verdict:** igbinary decode is **2.6x faster** than json_decode. But json_encode is fast enough and keeps data human-readable in SQLite.

## Storage Size

| Payload | JSON | igbinary | msgpack | serialize |
|---------|------|----------|---------|-----------|
| signature (2p) | 132 B | 99 B | 95 B | 204 B |
| signature (5p) | 298 B | 201 B | 220 B | 460 B |
| signature (10p) | 561 B | 313 B | 412 B | 864 B |
| diff_json | 723 B | 418 B | 531 B | 1.1 KB |

**Verdict:** igbinary is ~33-44% smaller. Meaningful for 15K+ rows but not critical at these sizes.

## SQLite JSON Functions vs PHP Decode (100 iterations, 1K rows each)

| Approach | Time/op |
|----------|---------|
| SQLite json_extract() | **1.44 ms** |
| PHP SELECT * + json_decode | 2.86 ms |
| SQLite WHERE json_array_length() | **0.93 ms** |
| PHP fetchAll + decode + filter | 5.58 ms |

**Verdict:** SQLite 3.51.2 JSON functions are **2-6x faster** than round-tripping to PHP. Use `json_extract()` and `json_array_length()` for filtering; keep JSON as storage format.

## Diff Query Strategies (20 iterations, ~15K symbols per version)

| Strategy | Time/op | Notes |
|----------|---------|-------|
| **SQL EXCEPT** | **14.0 ms** | Fastest SQL approach |
| SQL LEFT JOIN IS NULL | 20.9 ms | |
| SQL NOT EXISTS (current) | 21.5 ms | Current implementation |
| SQL NOT IN | 25.3 ms | Slowest SQL |
| SQL JOIN hash != (changed) | 28.7 ms | |
| PHP hash-map (removed) | 33.1 ms | Includes fetch overhead |
| PHP hash-map (changed) | 42.2 ms | |
| SQL JOIN + json_decode | 34.2 ms | Changed sigs decode |

**Verdict:**
- Switch `NOT EXISTS` to `EXCEPT` for findRemoved/findAdded — **35% faster**
- SQL beats PHP hash-maps because data transfer overhead dominates at 15K rows
- The diff pipeline bottleneck is rename matching (O(n*m) Levenshtein), not SQL queries

## Indexing Concurrency Models (9,002 files, Drupal Core Modules)

**Hardware:** 8-core CPU
**Date:** 2026-03-07

| Method | 4 Workers | 8 Workers | Peak RSS | Scaling |
| :--- | :--- | :--- | :--- | :--- |
| **pcntl_fork** (Baseline) | **1200 files/s** | **1370 files/s** | **~110 MB** | **Linear** |
| **AMPHP Parallel** | 1170 files/s | 1272 files/s | ~200 MB | Positive |
| **Swoole Coroutines** | 526 files/s | CRASH (OOM) | 6.7 GB | Negative |

**Verdict:**
- **pcntl_fork** is the clear winner for CPU-bound FFI parsing. It leverages all CPU cores and ensures instant memory reclamation by the OS after each chunk.
- **AMPHP** is a very strong secondary option (93-98% efficiency) with a cleaner API, but higher initial memory overhead.
- **Swoole** is unsuitable for this workload. Cooperative single-threading creates a CPU bottleneck, and the shared-memory model leads to massive RSS growth when handling thousands of FFI-heavy tasks.

## Decisions

1. **Keep JSON in SQLite** — human-readable, queryable via json_extract(), fast enough
2. **Use igbinary for worker IPC** — serialize data passed between forked workers via temp files
3. **Switch to EXCEPT** in SymbolDiffer — easy 35% win
4. **Use json_extract() in SQLite** — for filtering and partial field reads
5. **Skip msgpack** — slower than both json and igbinary in PHP 8.5
6. **Default to pcntl_fork** — for parallel indexing on CLI.
7. **Remove Swoole Indexer** — the cooperative model is not a fit for CPU-bound code analysis.
