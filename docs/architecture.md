# Architecture

## System Diagram

```
┌─────────────────────────────────────────────────────────┐
│                    Symfony Console CLI                    │
│  index │ diff │ scan │ apply │ report │ status │ query   │
└────┬──────┬──────┬──────┬──────┬──────┬──────┬──────────┘
     │      │      │      │      │      │      │
┌────▼──────▼──────▼──────▼──────▼──────▼──────▼──────────┐
│                     Service Layer                        │
│  CoreIndexer  VersionDiffer  ProjectScanner  Applier     │
└────┬──────────────┬──────────────────────────┬──────────┘
     │              │                          │
┌────▼────┐   ┌─────▼─────┐            ┌──────▼──────┐
│ Parser  │   │ Extractor │            │  Pattern    │
│ (FFI)   │   │ PHP/YAML  │            │  Matcher    │
└────┬────┘   └─────┬─────┘            └──────┬──────┘
     │              │                          │
┌────▼──────────────▼──────────────────────────▼──────────┐
│                  SQLite Storage                           │
│  versions │ files │ symbols │ changes │ matches │ snaps  │
└─────────────────────────────────────────────────────────┘
```

## Performance & Concurrency

Evolver is optimized for large-scale codebases like Drupal Core (~4,500 files, 30k+ symbols).

### Multi-Process Parallelism
Both the **Indexer** and **Scanner** utilize a `pcntl_fork` based worker pool to scale across CPU cores.
- **Linear Scaling:** Large tasks scale linearly with available CPUs. Indexing 1,900+ files dropped from ~7s to 1.54s with 4 workers.
- **Worker Isolation:** Each worker process creates its own `Parser` and Tree-sitter state after fork. Inherited parent parser reuse is explicitly rejected.
- **Configurable Load:** Both `index` and `scan` commands support a `-w|--workers` option, defaulting to 4 workers.
- **Fallback Behavior:** `pcntl` is not a hard install requirement, but it is the only parallel runtime used in production. Without it, indexing and scanning remain functional and fall back to sequential work.
- **AMPHP Research:** AMPHP was measured as a potential fallback and rejected because its `amphp/process` worker path depends on `proc_open()` and added enough startup overhead to underperform the sequential no-`pcntl` path on the measured indexing workload.

### Memory Management
To handle millions of potential matches without exhausting RAM:
- **Streaming Generators:** The `Query` and `MatchCollector` classes use PHP Generators (`yield`) to stream match results one by one rather than building massive in-memory arrays.
- **Aggressive Garbage Collection:** Worker loops explicitly trigger `gc_collect_cycles()` and `unset()` heavy Tree-sitter objects to keep the footprint below 512MB even during full-core scans.
- **Lightweight Counting:** SQL-level `COUNT(*)` queries are used for progress reporting instead of fetching record sets.

### Storage Optimization
- **WAL Mode (Write-Ahead Logging):** SQLite remains in WAL mode for fast reads and safe merge commits.
- **Parent-Owned Batched Writes:** Parallel indexing no longer relies on workers writing symbol rows into one shared database. Workers extract payloads and the parent merges them in batches, which avoids the old lock-contention path.
- **Composite Indexing:** A specialized composite index `idx_sym_lookup(version_id, fqn, symbol_type)` provides a 73% speedup for diffing operations.
- **Prepared Statement Cache:** `DatabaseApi` reuses prepared statements for the hottest repository paths to reduce parse overhead on repeated writes and diff queries.
- **Batched Writes:** `MatchRepo` and `ChangeRepo` chunk large inserts so scans and diffs avoid SQLite variable limits.
- **Deduplication:** `code_matches` uses a unique identity on `(scope_key, change_id, file_path, byte_start, byte_end)` with normalized `-1` byte offsets for offsetless matches, so both legacy project scans and persisted `scan_run` history remain idempotent.

## Layer Responsibilities

### TreeSitter Layer (`src/TreeSitter/`)

Provides PHP wrappers around the tree-sitter C library via FFI.

| Class | Responsibility |
|-------|---------------|
| `FFIBinding` | Singleton. Loads `libtree-sitter.so`, defines C types and function signatures via `FFI::cdef()`. Proxies all `ts_*` calls through `__call()`. |
| `LanguageRegistry` | Loads grammar `.so` files (e.g. `tree-sitter-php.so`) and returns `TSLanguage*` pointers. Caches loaded languages. Path configurable via `EVOLVER_GRAMMAR_PATH`. |
| `Parser` | High-level API: `parse(string $source, string $language): Tree`. Creates a ts_parser, sets the language, parses the string, returns a Tree wrapper. |
| `Tree` | Wraps `TSTree*`. Provides `rootNode(): Node` and `source(): string`. Calls `ts_tree_delete()` in destructor. |
| `Node` | Wraps `TSNode` (24-byte value type). Full API: type, text, children, named children, field access, parent/sibling navigation, s-expression output, recursive walk. |
| `Query` | Wraps `TSQuery` and `TSQueryCursor`. Compiles an S-expression pattern, executes against a node, returns capture arrays. |

**Key design decision: TSNode pass-by-value.** The C struct `TSNode` is 24 bytes (4×uint32 context, pointer id, pointer tree) and is passed by value in C function signatures. PHP FFI handles this but it requires the struct to be defined in the cdef string. All node functions accept `TSNode` not `TSNode*`.

### Storage Layer (`src/Storage/`)

SQLite persistence with WAL mode, foreign keys, and parameterized queries.

| Class | Responsibility |
|-------|---------------|
| `Database` | PDO wrapper. Sets WAL mode, foreign keys, busy timeout. Provides `query()`, `execute()`, `transaction()`, `lastInsertId()`. |
| `Schema` | Creates and forward-migrates all current tables and indexes with `IF NOT EXISTS`. Idempotent. Stores schema version in `schema_meta`. |
| `Repository/*` | Data access objects. One per table. Accept/return arrays (no DTOs in MVP). Hot-path tables expose `save()` for UPSERT semantics and `create()` as a BC alias. |

**Schema overview (selected tables):**

```
versions ──< parsed_files ──< symbols
                                 │
changes ──────────────────────── │ (old_symbol_id, new_symbol_id)
   │                             │
   └── from_version_id, to_version_id → versions

projects ──< project_branches
   │
   ├──< scan_runs ──< code_matches ──── changes
   │          │
   │          └────── job_id → jobs ──< job_logs
   │
   └──< code_matches (legacy project-scoped CLI scans)
```

### Indexer Layer (`src/Indexer/`)

Parses Drupal core files and extracts symbols.

| Class | Responsibility |
|-------|---------------|
| `FileClassifier` | Maps file extensions to languages. `.php/.module/.inc/.install/.profile/.theme/.engine` → php, `.yml/.yaml` → yaml. |
| `CoreIndexer` | Orchestrates indexing. Walks directory, classifies files, parses each with tree-sitter, extracts symbols, stores in DB. Shows progress bar. Re-indexing is path-aware: unchanged files are skipped, changed files replace prior symbol rows for that path. |
| `PHPExtractor` | Walks PHP AST. Extracts: function_definition, class_declaration, interface_declaration, trait_declaration, method_declaration, const_declaration. Parses `@trigger_error` calls and `@deprecated` docblocks for deprecation metadata. Computes signature hashes. |
| `YAMLExtractor` | Walks YAML AST. Extracts: module/theme metadata from `*.info.yml`, services from `*.services.yml`, routes from `*.routing.yml`, permissions from `*.permissions.yml`, config schema from `*.schema.yml`, breakpoints from `*.breakpoints.yml`, and UI links. |
| `DrupalLibrariesExtractor` | Uses tree-sitter ranges plus normalized YAML semantics for `*.libraries.yml`. Stores library dependencies, resolved JS/CSS asset paths, and deprecation metadata so library symbols can link to indexed asset files. |

**Indexing flow:**

```
evolver index /path/to/drupal --tag=10.3.0
  ├── Parse version tag → major.minor.patch
  ├── Upsert version record in DB
  ├── Walk directory, classify files by extension
  ├── For each file:
  │   ├── Look up prior row by (version_id, file_path)
  │   ├── Same SHA-256 hash → skip file
  │   ├── tree-sitter parse → AST
  │   ├── Upsert parsed_files row
  │   ├── Replace symbols for that file in symbols table
  │   └── Detect deprecations from trigger_error + docblocks
  └── Update version record with counts
```

## Relational Analysis

The indexing process creates a "Knowledge Graph" of the Drupal codebase by linking symbols across different file types via their Fully Qualified Names (FQN) and metadata.

### Cross-File Relationships

| Source Symbol | Target Symbol | Relationship |
|---------------|---------------|--------------|
| `service` (YAML) | `class` (PHP) | Points to the implementation class |
| `route` (YAML) | `method` (PHP) | Points to the controller or form handler |
| `module_info` (YAML) | `module_info` (YAML) | Tracks dependencies between modules |
| `theme_info` (YAML) | `theme_info` (YAML) | Tracks `base theme` inheritance |
| `theme_info` (YAML) | `drupal_library` (YAML) | Tracks `libraries-override` and `libraries-extend` |
| `drupal_library` (YAML) | `javascript` / `css_selector` symbols | Links UI assets to module/theme definitions via resolved asset paths |
| `hook` (PHP Attribute) | Hook Name | Links implementation to Drupal's hook system |
| `link_menu` (YAML) | `route` (YAML) | Links navigation items to routes |
| `breakpoint` (YAML) | — | Defines responsive design points |

### Hook Lifecycle: Procedural to Attributes
Evolver tracks the migration of hooks from procedural functions (`hook_foo`) to attributed class methods (`#[Hook('foo')]`).
- **Procedural Detection:** Functions starting with `module_name_` in global namespace are automatically indexed as secondary `hook` symbols.
- **Attribute Detection:** Methods decorated with `#[Hook('name')]` are extracted as `hook` symbols with the corresponding name.
- **Diff Logic:** Because both implementations share the same `symbol_type=hook` and FQN, the Differ can identify implementation shifts (signature changes) rather than treating them as disconnected removals/additions.

### Asset Library Deprecation
The indexer specifically monitors `*.libraries.yml` for architectural changes:
- **Direct Deprecation:** Extracts messages from the `deprecated` key within library definitions.
- **File Moves:** Detects and flags libraries using the `moved_files` key as deprecated, extracting the target migration links.
- **Metadata Extraction:** Automatically parses `deprecation_version` and `removal_version` from the YAML values to provide precise upgrade timing.
- **Asset Relations:** Resolves internal JS/CSS paths relative to the `*.libraries.yml` file, stores them in library metadata, and links them back to indexed `javascript` and `css` symbols in the UI and semantic search flow.

### Analyzing Breaking Changes

By establishing these relations, Evolver can detect cascading breaking changes:

1.  **Direct Change:** A PHP class used by a service is renamed.
2.  **Relational Detection:** The indexer identifies the `service` YAML symbol that references this class.
3.  **Pattern Generation:** A change record is created not just for the PHP class, but also for any service definitions or route controllers affected by the change.
4.  **Surgical Fix:** The applier can then update both the PHP `use` statements and the YAML `class:` keys simultaneously.

### Schema Discovery

The `config_schema` symbols allow the tool to validate `config/install/*.yml` files against their definitions, ensuring that configuration structure changes are detected even when they don't involve PHP code.

### Differ Layer (`src/Differ/`)

Compares two indexed versions to detect changes.

| Class | Responsibility |
|-------|---------------|
| `VersionDiffer` | Orchestrates all diff strategies. Runs them in sequence, replaces any prior diff rows for the version pair, and stores results in batches. |
| `SymbolDiffer` | Hash-based diff via SQL. `findRemoved()`: signature_hash in old not in new. `findAdded()`: vice versa. `findChanged()`: same FQN, different hash. |
| `RenameMatcher` | Fuzzy removed→added matcher for PHP symbols (name/body/signature similarity). Emits `*_renamed` changes with confidence score. |
| `SignatureDiffer` | Parameter-level diff. Compares two `signature_json` blobs. Detects: parameter added/removed/type changed/renamed, return type changed. |
| `YAMLDiffer` | YAML-aware differ for service/route/config symbols. Detects removals, key renames, and semantic changes (for example `service_class_changed`). |
| `FixTemplateGenerator` | Creates mechanical fix templates (`function_rename`, `string_replace`, `parameter_insert`) for auto-fixable change records. |
| `DeprecationTracker` | Tracks deprecation lifecycle. Newly deprecated (was 0, now 1). Deprecated-then-removed (was deprecated, now gone from new version). |

**Diff strategies (priority order):**

1. **Hash diff** — O(n) via SQL set difference on `signature_hash`. Covers ~80% of changes.
2. **FQN match** — JOIN on `fqn` + `symbol_type` where hash differs. Feeds into SignatureDiffer for param-level detail.
3. **Rename matching** — Compares removed and added symbols to detect probable renames.
4. **YAML semantic diff** — Service class, route, and config-key level changes.
5. **Deprecation lifecycle** — Cross-version `is_deprecated` tracking.

### Pattern Layer (`src/Pattern/`)

Generates tree-sitter S-expression queries from detected changes.

| Change Type | Generated Query |
|------------|----------------|
| `function_removed` | `(function_call_expression function: (name) @fn (#eq? @fn "name"))` |
| `method_removed` | `(member_call_expression name: (name) @method (#eq? @method "name"))` |
| `class_removed` | `qualified_name` FQN match + short-name fallback capture |
| `service_removed` | `(string_content) @svc (#eq? @svc "service.name")` |
| `signature_changed` | Function/method call with `arguments` capture for post-processing |

### Scanner Layer (`src/Scanner/`)

Scans target projects against stored changes.

| Class | Responsibility |
|-------|---------------|
| `ProjectScanner` | Walks project files, parses with tree-sitter, runs change queries, and stores matches. Skips `vendor/` and `node_modules/`. CLI scans create `scan_runs`, and matches are now recorded per run so history is preserved. |
| `VersionDetector` | Reads `composer.lock` to find `drupal/core` version. |
| `MatchCollector` | Runs each change's `ts_query` against a parsed tree. Returns match locations and source text. Includes signature-change post-processing (argument-count heuristic) to reduce noise. |

### Web and Queue Layer (`src/Web/`, `src/Project/`, `src/Queue/`)

Provides the local control plane for managed branch scans.

| Class | Responsibility |
|-------|---------------|
| `WebServer` | Amp HTTP server with Twig-rendered pages and SSE endpoints for live job updates. |
| `ManagedProjectService` | Registers managed Git projects and persists tracked branches. |
| `GitProjectManager` | For managed `git_remote` projects, keeps a shared cached repo under `.cache/projects/<slug>/repo` by default and materializes ephemeral run sources under `.cache/projects/<slug>/runs/<run-id>/source`. Branch detection uses a temporary `/tmp/evolver_remote_detect_*` probe, and existing stored project paths remain valid. |
| `JobQueue` | Persists queue jobs, progress, and log events in SQLite. |
| `ScanRunService` | Queues branch scans, resolves source trees, executes scans, and cleans per-run remote materializations after completion. |
| `RunComparisonService` | Compares two completed runs for the same project and upgrade path. |

### Applier Layer (`src/Applier/`)

Applies mechanical fixes from stored templates.

| Class | Responsibility |
|-------|---------------|
| `FixTemplate` | Template engine. Supports: `function_rename`, `parameter_insert`, `string_replace`, `namespace_move`. |
| `TemplateApplier` | Loads pending matches with fix_templates, applies them to source files. Sorts matches bottom-up by line to preserve byte offsets. Supports `--dry-run` and `--interactive` modes. |
| `DiffGenerator` | Generates unified diff format for review output. |

**Fix application strategy:** Surgical string replacement at byte offsets. No AST-to-source round-trip. Matches within a file are applied bottom-up (highest byte offset first) so earlier replacements don't shift later offsets.

## Data Flow

```
                    ┌──────────┐
                    │  Drupal   │
                    │  Core     │
                    │  10.2.0   │
                    └─────┬────┘
                          │ index
                          ▼
                    ┌──────────┐     ┌──────────┐
                    │ symbols  │     │ symbols  │
                    │ v10.2.0  │     │ v10.3.0  │
                    └─────┬────┘     └─────┬────┘
                          │                │
                          └───────┬────────┘
                                  │ diff
                                  ▼
                          ┌──────────────┐
                          │   changes    │
                          │ + ts_query   │
                          │ + fix_templ  │
                          └───────┬──────┘
                                  │ scan
                    ┌─────────────┤
                    │             ▼
              ┌─────┴────┐ ┌──────────────┐
              │  Your     │ │ code_matches │
              │  Module   │ │ + locations  │
              └──────────┘ │ + fixes      │
                           └───────┬──────┘
                                   │ apply
                                   ▼
                           ┌──────────────┐
                           │ Fixed code   │
                           │ (dry-run or  │
                           │  applied)    │
                           └──────────────┘
```

## Docker Environment

The Dockerfile uses `alpine:edge` which provides pre-built packages:

- `php85` + matching `-ffi` + `-pdo_sqlite`
- `tree-sitter` (libtree-sitter.so)
- `tree-sitter-php` (PHP grammar `.so`)
- `tree-sitter-yaml` (YAML grammar `.so`)
- `tree-sitter-twig` (Twig grammar `.so`, built from source)

Grammar `.so` files are loaded directly from system library paths (for example `/usr/lib`). No repo-local grammar checkout is required.
