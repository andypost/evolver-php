# Auto Memory — DrupalEvolver

Last updated: 2026-03-07

## Project Type

PHP CLI tool using tree-sitter FFI for static analysis and automated code transformation.

## PHP Version

- **Local**: PHP 8.5+ (composer.json `^8.5`)
- **Docker**: PHP 8.5 only.

Docker runs as non-root user with UID/GID matching host to ensure file ownership compatibility.

## Key Technical Decisions

- **TSNode passed by value**: The tree-sitter C struct is 24 bytes and passed by value. PHP FFI handles this, but `TSNode` must be defined in the `FFI::cdef()` string. See `src/TreeSitter/FFIBinding.php`.

- **Grammar .so files**: Loaded via `LanguageRegistry` from `EVOLVER_GRAMMAR_PATH` (defaults to `/usr/lib`). In Docker, these are provided by Alpine packages.

- **Signature hashing**: SHA-256 of `type|fqn|params|return_type` for O(1) diffing via SQL.

- **Surgical text replacement**: Fixes applied at byte offset level, bottom-up to preserve positions. No AST-to-source round-trip.

- **SQLite WAL mode**: Enabled for concurrency. All repos use parameterized queries.

- **Multi-Process Concurrency**: Uses `pcntl_fork` for parallel indexing and scanning. Workers extract payloads, and the parent process performs the SQLite merge to avoid lock-heavy shared writes.
- **FFI Lifetime**: `FFIBinding` is fork-aware and refreshes automatically on PID change. `Parser` instances are process-local and must be recreated after fork; normal runtime paths no longer call `FFIBinding::reset()`.

- **Memory Management**: Uses PHP Generators (`yield`) for tree traversal and match collection to keep RAM usage low. Aggressive `gc_collect_cycles()` in worker loops.

## Docker Environment

- Base: `alpine:edge`
- PHP: `php85` with FFI, PDO SQLite, PCNTL
- Profiling: `php85-meminfo`, `php85-pecl-memprof`, `php85-spx`, `php85-pecl-xhprof`
- Grammar packages: `tree-sitter-php`, `tree-sitter-yaml`, `tree-sitter-javascript`, `tree-sitter-css`

## Common Patterns

### Adding a new extractor
1. `src/Indexer/Extractor/FooExtractor.php` implementing `ExtractorInterface`
2. Register in `CoreIndexer::indexFileWorker()`
3. Test in `tests/Unit/Indexer/`

### Adding a new change type
1. Add detection logic in `src/Differ/VersionDiffer::diff()`
2. Add query generation in `src/Pattern/QueryGenerator::generate()`
3. Add fix template in `src/Applier/FixTemplate::apply()`
4. Add test cases

### Running tests
```bash
docker compose run --rm --entrypoint vendor/bin/phpunit evolver
```

Tests use `:memory:` SQLite and vfsStream. No FFI required for unit tests.

## File Locations Reference

| Purpose | File |
|---------|------|
| FFI C definitions | `src/TreeSitter/FFIBinding.php` |
| SQLite schema | `src/Storage/Schema.php` |
| PHP extraction | `src/Indexer/Extractor/PHPExtractor.php` |
| Version diffing | `src/Differ/VersionDiffer.php` |
| Fix templates | `src/Applier/FixTemplate.php` |
| Docker build | `Dockerfile` |

## Known Issues

- **FFI scope**: Types defined in `FFI::cdef()` are only accessible via that FFI instance. Use `$ffi->new('TSQueryMatch')` not `FFI::new('TSQueryMatch')`.
- **Memory Databases in Workers**: `:memory:` databases cannot be shared between parent and child processes. Indexer/Scanner automatically fall back to 1 worker for in-memory DBs.

## CI/CD Considerations

- Index Drupal core versions once, reuse across scans
- Use `--format=json` for programmatic report consumption
- Docker image can be published to registry for faster CI startup
