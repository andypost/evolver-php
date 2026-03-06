# CLAUDE.md — Project Instructions for Claude Code

## Project Overview

Evolver is a PHP CLI tool (Symfony Console) that uses tree-sitter via PHP FFI to parse Drupal core across version tags, extract symbols and deprecation patterns into SQLite, diff versions to detect breaking changes, and apply transformation patterns to target codebases.

## Tech Stack

- **PHP 8.5+** with `ext-ffi`, `ext-igbinary`, and `ext-pdo_sqlite` (composer.json specifies ^8.5)
- **Docker** uses PHP 8.5 from `alpine:edge` packages
- **Symfony Console 7.x** for CLI
- **tree-sitter** via FFI for PHP and YAML parsing
- **SQLite 3** (WAL mode) for storage
- **PHPUnit 11** for testing
- **vfsStream** for filesystem mocking in tests
- **Docker** with `alpine:edge` as runtime (includes `tree-sitter-php` and `tree-sitter-yaml` grammar packages)

## Key Architecture Decisions

- **DatabaseApi facade** — All database operations go through `src/Storage/DatabaseApi`. Commands construct `DatabaseApi` instead of manually wiring `Database` + `Schema` + repositories.
- **TSNode is a 24-byte struct passed by value** in C. The FFI binding handles this carefully — see `src/TreeSitter/FFIBinding.php` for the struct layout.
- **Grammar .so files** are loaded via `LanguageRegistry`. Path is configurable via `EVOLVER_GRAMMAR_PATH` env var. In Docker, grammars are symlinked from Alpine packages at `/usr/lib/tree-sitter/`.
- **Fixes operate at source text level** using byte offsets from tree-sitter matches. No AST-to-source round-trip. Apply bottom-up (highest offset first) to preserve positions.
- **Signature hashing** uses SHA-256 of `type|fqn|params|return_type` for fast O(1) diffing.
- **SQLite uses WAL mode** with foreign keys enabled. All repos use parameterized queries (no SQL injection).
- **#[NoDiscard] attribute** — Marked on methods returning values that should be used (query results, IDs, etc.). Use `(void)` cast when intentionally discarding.

## Performance Optimizations

- **igbinary serialization** — Required extension, 2-6x faster than native `serialize()` for worker IPC
- **SQLite json_extract()** — Server-side JSON filtering 2-6x faster than PHP-side decode
- **EXCEPT queries** — 35% faster than NOT EXISTS for set-difference operations
- **Covering index** — `idx_sym_version_fqn_type_hash` enables index-only scans for signature changes
- **Version weight column** — Precomputed `major*1000000 + minor*1000 + patch` for efficient upgrade path queries
- **Prepared statement caching** — `DatabaseApi::prepare()` reduces parse overhead in tight loops
- **Batch inserts** — `MatchRepo::createBatch()` and `ChangeRepo::createBatch()` for bulk writes

Benchmark details: See `docs/BENCHMARKS.md`

## Project Structure

```
src/
  TreeSitter/     — FFI binding, parser, node, query, tree wrappers
  Storage/        — Database, DatabaseApi, Schema, Repository/* (6 repos)
  Indexer/        — CoreIndexer, FileClassifier, Extractor/{PHP,YAML}Extractor
  Differ/         — VersionDiffer, RenameMatcher
  Scanner/        — ProjectScanner, VersionDetector, MatchCollector
  Applier/        — FixTemplate, TemplateApplier, DiffGenerator
  Pattern/        — QueryGenerator
  Command/        — 7 Symfony Console commands
tests/
  Unit/           — PHPUnit tests (mirrors src/ structure)
```

## Running the Project

```bash
# Docker (preferred — has all tree-sitter dependencies)
make build
make up
make ev -- status          # Run evolver commands
make shell                # Interactive shell

# Tests
make tests                # Run PHPUnit with warnings/deprecations displayed
```

## Running Tests

```bash
make tests                           # All tests with warnings shown
vendor/bin/phpunit tests/Unit/Storage # Specific directory
vendor/bin/phpunit --filter=SignatureDifferTest  # Specific test
```

Tests use `:memory:` SQLite and vfsStream — no tree-sitter FFI required for unit tests. Integration tests that exercise the full FFI pipeline need the Docker container.

## Coding Conventions

- **PSR-4 autoloading** under `DrupalEvolver\` namespace → `src/`
- **PHP 8.5+ features**: `#[NoDiscard]`, `#[\NoDiscard]`, readonly properties, named arguments, match expressions, union types, constructor promotion
- **Repositories** accept/return arrays, not DTOs (MVP simplicity)
- **Commands** create `DatabaseApi` instances with `--db` option (defaults to `evolver.sqlite`)
- **#[NoDiscard]** on methods that return important values (query results, IDs). Use `(void)` cast to intentionally discard.
- Follow existing patterns when adding new extractors, repositories, or commands

## Important Files

- `docs/BENCHMARKS.md` — Performance benchmarks and optimization decisions
- `TODO.md` — Completed DatabaseApi refactor and query optimizations
- `Dockerfile` — Alpine Edge with PHP 8.5, tree-sitter, grammars (no manual compilation)
- `Makefile` — Local development commands (build, up, tests, profile)
- `src/TreeSitter/FFIBinding.php` — Core FFI C definitions (TSNode struct, all ts_* functions)
- `src/Storage/DatabaseApi.php` — Centralized database facade with cross-table queries
- `src/Storage/Schema.php` — Full SQLite schema (8 tables, indexes) with migrations
- `src/Indexer/Extractor/PHPExtractor.php` — PHP symbol extraction (most complex extractor)

## Common Tasks

### Adding a new extractor
1. Create `src/Indexer/Extractor/FooExtractor.php` implementing `ExtractorInterface`
2. Register in `CoreIndexer::__construct()` and call in the index loop
3. Add corresponding tests

### Adding a new diff strategy
1. Create class in `src/Differ/`
2. Wire into `VersionDiffer::diff()` method
3. If it generates new change types, update `QueryGenerator::generate()`

### Adding a new command
1. Create `src/Command/FooCommand.php` with `#[AsCommand]` attribute
2. Register in `bin/evolver`
3. Add test in `tests/Unit/Command/CommandRegistrationTest.php`

### Adding a cross-table query
1. Add method to `DatabaseApi` with `#[\NoDiscard]`
2. Use SQLite JSON functions (`json_extract`, `json_array_length`) for partial reads
3. Consider EXCEPT for set-difference operations
4. Return `Generator` for large result sets

## Database Schema (SQLite)

8 tables: `versions`, `parsed_files`, `symbols`, `changes`, `ast_snapshots`, `projects`, `code_matches`, `schema_meta`

Key relationships:
- `versions` → `parsed_files` → `symbols` (one version has many files, each file has many symbols)
- `changes` references `from_version_id`/`to_version_id` and optionally `old_symbol_id`/`new_symbol_id`
- `projects` → `code_matches` → `changes` (scan results link projects to detected changes)
- `versions.weight` column for efficient upgrade path queries (precomputed semantic version)
- `code_matches.byte_start`/`byte_end` defaults to `-1` for legacy data

## Warnings / Gotchas

- **FFI types are instance-scoped**: Use `$ffi->new('TSQueryMatch')` not `FFI::new('TSQueryMatch')` — types defined in `FFI::cdef()` are only accessible via that FFI instance.
- **tree-sitter-php grammar path**: The PHP grammar lives in `php/src/` subdirectory (not just `src/`). The Makefile and Dockerfile handle this.
- **intelephense false positives**: The IDE reports undefined types for `FFI`, `FFI\CData`, Symfony Console, and PHPUnit. These are extension/dev stubs not indexed by intelephense — ignore these diagnostics.
- **Node::walk() uses named children only** to avoid infinite recursion on unnamed/syntax nodes.
- **TemplateApplier sorts matches bottom-up** by line number before applying to preserve byte offsets.
- **#[NoDiscard] requires PHP 8.5+** — Local PHP 8.4 will show parse errors on `(void)` cast syntax; use Docker for development.
- **igbinary is required** — No fallback to native `serialize()`; ensure extension is loaded.
