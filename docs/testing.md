# Testing Guide

## Overview

Evolver uses PHPUnit 11 for testing. Tests are organized by layer under `tests/Unit/` mirroring the `src/` directory structure.

## Running Tests

```bash
# All tests (inside Docker)
make tests

# Specific directory
make e -- vendor/bin/phpunit tests/Unit/Storage

# Specific test class
make e -- vendor/bin/phpunit --filter=SignatureDifferTest
```

## Memory & Performance Profiling

The Docker image includes several profiling extensions for PHP 8.5:
- **meminfo**: Object-level heap analysis.
- **memprof**: Function-level allocation tracking.
- **spx**: Full-featured wall-time and memory profiler with UI.
- **xhprof**: Hierarchical call graph profiling.

### Memory Leak Profiling (memprof)

Profile parser-heavy indexing:
```bash
docker compose run --rm -e MEMPROF_PROFILE=1 --entrypoint php evolver -d extension=memprof.so scripts/leak_memprof.php
```
Reports are written to `.data/profiles/memprof_dump.json`.

### Unified Profiling Suite

Run all index profilers and produce one comparison report:
```bash
make profile EXTRA_HOST_PATH=../drupal
```
Outputs a consolidated summary in `.data/profiles/summary.md`.

### Index Leak Probe (meminfo)

Use meminfo in repeated in-process indexing mode:
```bash
make meminfo-leak-index MEMINFO_LEAK_ITERATIONS=30
```

### Memory Stability Tests

A specialized `MemoryLeakTest` checks repeated parser/query, indexing, scanning, and diffing paths for unexpected memory growth.
```bash
make e -- php -d extension=meminfo.so vendor/bin/phpunit tests/Unit/Memory/MemoryLeakTest.php
```

## Test Architecture

### Unit Tests (mostly no FFI required)

Most tests run without tree-sitter and exercise pure PHP logic:

| Test | What It Covers |
|------|---------------|
| `FileClassifierTest` | Extension → language mapping (PHP, YAML, JS, CSS, Libraries) |
| `DatabaseTest` | PDO wrapper: query, execute, lastInsertId, transaction, WAL mode |
| `SchemaTest` | Table creation, idempotency, unique constraints |
| `RepositoryTest` | CRUD operations for all 6 repositories |
| `SymbolDifferTest` | Removed/added/changed behavior |
| `SignatureDifferTest` | Parameter and return type changes |
| `RenameMatcherTest` | Fuzzy rename matching with parallel support |
| `MemoryLeakTest` | Memory growth monitoring across parser/query, indexing, scanning, and diffing |
| `NamespaceMovePipelineTest` | Integration-style `diff -> scan -> apply` flow |

### Test Dependencies

| Dependency | Purpose |
|-----------|---------|
| `phpunit/phpunit ^11.0` | Test framework |
| `mikey179/vfsstream ^1.6` | Virtual filesystem for testing file-based operations |

### Storage Tests

Storage tests use **in-memory SQLite** (`:memory:`) — no files are written to disk.
*Note: Multi-process features automatically fall back to sequential mode for in-memory databases.*

## Writing New Tests

### For a new Extractor

1. Create `tests/Unit/Indexer/FooExtractorTest.php`.
2. Mock `Node` objects to simulate the AST structure.
3. Verify that `extract()` returns the expected symbol metadata.

### Integration Tests

Integration tests that exercise the full tree-sitter FFI pipeline run inside the Docker container:
```bash
make e -- vendor/bin/phpunit tests/Unit/Integration
```

## Coverage Targets

Current baseline:
- `122` tests, `1948` assertions
