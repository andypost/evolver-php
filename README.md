# Evolver

Evolver is a PHP CLI tool that uses [tree-sitter](https://tree-sitter.github.io/) via FFI to analyze Drupal core across version tags, detect breaking changes and deprecations, scan target projects for affected code, and apply mechanical fixes.

## What It Does

1. **Index** Drupal core versions — parses all PHP and YAML files with tree-sitter, extracts symbols (functions, classes, methods, services, routes, permissions) into SQLite
2. **Diff** two indexed versions — detects removals, renames, signature changes, new deprecations, and generates tree-sitter query patterns for each change
3. **Scan** a Drupal module/theme/site — runs the generated patterns against your code and reports every match with location and severity
4. **Apply** mechanical fixes — uses stored fix templates to do surgical source-level replacements (function renames, parameter inserts, service renames, namespace moves)

## Requirements

- PHP 8.4+ with `ext-ffi` and `ext-pdo_sqlite`
- tree-sitter C library (`libtree-sitter.so`)
- tree-sitter-php and tree-sitter-yaml grammar shared libraries
- Symfony Console 7.x (installed via Composer)

**Or just use Docker** (recommended):

```bash
make build
make up
make ev -- status
```

## Quick Start

### With Docker (easiest)

```bash
# Build the container
make build

# Start the long-running engine container
make up

# Check status
make ev -- status

# Index Drupal core versions by mounting the checkout at /mnt/project
make evr -- index /mnt/project --tag=10.2.0 EXTRA_HOST_PATH=../drupal
make evr -- index /mnt/project --tag=10.3.0 EXTRA_HOST_PATH=../drupal

# Diff two versions
make ev -- diff --from=10.2.0 --to=10.3.0

# Scan an external project mounted at /mnt/project
make evr -- scan /mnt/project --target=10.3.0 EXTRA_HOST_PATH=../my-custom-module

# View report
make ev -- report --project=my-custom-module

# Apply fixes (dry run first)
make ev -- apply --project=my-custom-module --dry-run

# Interactive shell for development
make shell
```

External `EXTRA_HOST_PATH` mounts are always read-only and intended for indexing, scanning, and other analysis commands.

### Without Docker

```bash
# Install dependencies
composer install

# Install tree-sitter shared libs from your OS packages and point to them
export EVOLVER_GRAMMAR_PATH=/usr/lib

# Run
bin/evolver status
bin/evolver index /path/to/drupal --tag=10.3.0
```

## Commands

| Command | Description |
|---------|-------------|
| `evolver index <path> --tag=<v> [-w 4]` | Parse Drupal core and store symbols. Supports parallel workers. |
| `evolver diff --from=<v1> --to=<v2>` | Compare two indexed versions and detect all changes. |
| `evolver scan <path> --target=<v> [-w 4]` | Scan a project against stored changes. Supports parallel workers. |
| `evolver apply --project=<name>` | Apply template-based fixes to scanned matches. |
| `evolver report --project=<name>` | Show scan results as table or JSON. |
| `evolver status` | Database stats: versions, symbols, changes, matches. |
| `evolver query <pattern> <file>` | Debug: run a raw tree-sitter S-expression query. |

See [docs/commands.md](docs/commands.md) for full command reference and [docs/usage.md](docs/usage.md) for complete workflow examples.

## Architecture

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

See [docs/architecture.md](docs/architecture.md) for the full design.

## Project Structure

```
src/
├── TreeSitter/          # FFI binding to libtree-sitter
│   ├── FFIBinding.php   # Raw C function definitions
│   ├── LanguageRegistry.php  # Grammar .so loader
│   ├── Parser.php       # High-level parse API
│   ├── Tree.php         # Parsed tree wrapper
│   ├── Node.php         # AST node wrapper
│   └── Query.php        # S-expression query runner
├── Storage/             # SQLite persistence
│   ├── Database.php     # PDO wrapper (WAL, pragmas)
│   ├── Schema.php       # Table creation
│   └── Repository/      # Data access objects
├── Indexer/             # Drupal core indexing
│   ├── CoreIndexer.php  # Orchestrates full index
│   ├── FileClassifier.php  # Extension → language mapping
│   └── Extractor/       # Symbol extraction from ASTs
├── Differ/              # Version comparison
│   ├── VersionDiffer.php     # Orchestrates all strategies
│   ├── SymbolDiffer.php      # Hash-based diff
│   ├── RenameMatcher.php     # Fuzzy removed→added rename detection
│   ├── SignatureDiffer.php   # Parameter-level diff
│   ├── YAMLDiffer.php        # YAML-specific service/route/config diffs
│   └── DeprecationTracker.php
├── Scanner/             # Target project scanning
│   ├── ProjectScanner.php
│   ├── VersionDetector.php
│   └── MatchCollector.php
├── Applier/             # Code transformation
│   ├── FixTemplate.php
│   ├── TemplateApplier.php
│   └── DiffGenerator.php
├── Pattern/
│   └── QueryGenerator.php  # Change → tree-sitter query
└── Command/             # Symfony Console commands
```

## Testing

```bash
# Run all tests in the container
make tests

# Run a specific test suite
make e -- vendor/bin/phpunit tests/Unit/Storage
make e -- vendor/bin/phpunit tests/Unit/Command
```

Tests use in-memory SQLite and [vfsStream](https://github.com/bovigo/vfsStream) for filesystem mocking. Tree-sitter FFI integration tests require the Docker container.

## How It Works

### Symbol Extraction

The tool uses tree-sitter grammars to parse PHP and YAML files into concrete syntax trees, then walks those trees to extract symbols:

**PHP symbols:** functions, classes, methods, interfaces, traits, constants, `@trigger_error` deprecations, `@deprecated` docblocks

**YAML symbols:** service definitions (`*.services.yml`), routes (`*.routing.yml`), permissions (`*.permissions.yml`)

Each symbol gets a `signature_hash` — a SHA-256 of its type, name, parameters, and return type. This enables fast hash-based diffing between versions.

Re-indexing the same tag is path-aware and idempotent: unchanged files at the same relative path are skipped, while changed files replace their prior `parsed_files` row and symbol set.

### Change Detection

When diffing two versions, the tool runs these strategies in order:

1. **Hash diff** — signatures in old but not new = removed; new but not old = added
2. **FQN match** — same fully-qualified name but different hash = signature changed
3. **Rename detection** — removed PHP/YAML symbols matched to likely renamed additions
4. **YAML differ** — service class changes, route changes, config/service key removals and renames
5. **Deprecation tracking** — newly deprecated, deprecated-then-removed lifecycle

Each detected change gets a tree-sitter S-expression query (`ts_query`) that can find affected code in target projects.

### Fix Templates

Mechanical fixes are stored as JSON templates:

```json
{"type": "function_rename", "old": "drupal_render", "new": "\\Drupal::service('renderer')->render"}
{"type": "parameter_insert", "function": "some_func", "position": 2, "value": "NULL"}
{"type": "string_replace", "old": "old.service", "new": "new.service"}
{"type": "namespace_move", "old_namespace": "Drupal\\Core\\Old", "new_namespace": "Drupal\\Core\\New"}
```

Fixes are applied at the source text level using byte offsets from tree-sitter matches — no AST-to-source round-trip.

## License

GPL-2.0-or-later
