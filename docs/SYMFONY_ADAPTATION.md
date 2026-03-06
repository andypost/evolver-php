# Symfony Adaptation: POC Results

## Overview

DrupalEvolver can index, diff, and scan Symfony core with minimal changes. The core infrastructure (tree-sitter PHP parsing, SQLite storage, signature diffing, rename matching) is framework-agnostic. Only two methods in `PHPExtractor` needed Symfony-specific patterns, plus one line in `VersionDetector` and one in `Database`.

## Code Changes (4 lines total)

### `src/Indexer/Extractor/PHPExtractor.php`

**`checkDeprecation()`** — Added `trigger_deprecation` to the function allowlist and a Symfony-specific branch extracting the version from the 2nd string argument:
```php
trigger_deprecation('symfony/component', '7.1', 'The "%s" class is deprecated')
//                                        ^^^  extracted as deprecation_version
```

**`applyDeprecationFromDocblock()`** — Added `@deprecated since Symfony X.Y` pattern alongside the existing Drupal `@deprecated in drupal:X.Y.Z` pattern.

### `src/Scanner/VersionDetector.php`

Added `symfony/framework-bundle` fallback in the `composer.lock` package loop — detects the installed Symfony version for `scan` command auto-detection.

### `src/Storage/Database.php`

Bare filenames passed as `--db` (no `/` separator) now resolve into `.data/` so all databases land alongside each other (`evolver.sqlite`, `symfony.sqlite`, etc.).

### What Did NOT Change

Every other part of the codebase: TreeSitter FFI, Parser, Node, Query, Tree, Database schema, all Repositories, CoreIndexer, FileClassifier (`.php` already handled), VersionDiffer, SignatureDiffer, RenameMatcher, QueryGenerator, all seven Commands, Applier, FixTemplate, DiffGenerator.

## POC Results — Symfony 7.4.6 → 8.0.0

Ran via `compare` command (index + diff in one step, both versions mounted simultaneously):

```bash
docker compose run --rm --no-deps \
  --volume ../symfony-7.4:/mnt/sf74:ro \
  --volume ../symfony:/mnt/sf80:ro \
  evolver php85 bin/evolver compare \
  /mnt/sf74/src/Symfony /mnt/sf80/src/Symfony \
  --tag1=7.4.6 --tag2=8.0.0 --db=symfony-compare.sqlite
```

Or via separate index + diff with git checkout:

```bash
# Bare filenames resolve to .data/ automatically
cd ../symfony && git checkout v7.4.6 && cd -
make evr -- index /mnt/project/src/Symfony --tag=7.4.6 --db=symfony.sqlite EXTRA_HOST_PATH=../symfony

cd ../symfony && git checkout v8.0.0 && cd -
make evr -- index /mnt/project/src/Symfony --tag=8.0.0 --db=symfony.sqlite EXTRA_HOST_PATH=../symfony

make ev -- diff --from=7.4.6 --to=8.0.0 --db=symfony.sqlite
make ev -- status --db=symfony.sqlite
```

### Numbers

| Metric | 7.4.6 | 8.0.0 |
|--------|-------|-------|
| Files indexed | 5,435 | 4,278 |
| Symbols indexed | 31,149 | 30,215 |

| Stat | Value |
|------|-------|
| Total changes detected | **16,310** |
| Renames matched | 3,109 |
| Signature changes | 75 |
| Deprecation changes | 137 |
| Total time (index + diff) | ~98s |

### Change breakdown

| Type | Count |
|------|-------|
| `method_removed` | 5,953 |
| `method_renamed` | 1,528 |
| `class_removed` | 936 |
| `class_renamed` | 215 |
| `interface_removed` | 107 |
| `trait_removed` | 60 |
| `signature_changed` | 29 |
| `function_removed` | 17 |

### Verified against UPGRADE-8.0.md

| Change | Detected as |
|--------|-------------|
| `HttpFoundation\Request::get()` removed | `method_removed` ✓ |
| `DI\Compiler\ResolveTaggedIteratorArgumentPass` removed | `class_removed` ✓ |
| 93 deprecated symbols with Symfony version tags | `is_deprecated=1`, `deprecation_version=[7.1..7.4]` ✓ |

## What It Detects

| Symbol Type | Description |
|-------------|-------------|
| `class` | Class declarations with full FQN |
| `interface` | Interface declarations |
| `trait` | Trait declarations |
| `method` | Method signatures (params, return type, visibility) |
| `function` | Standalone functions |
| `constant` | Class constants and global constants |

| Change Type | Description |
|-------------|-------------|
| `method_removed` | Method present in old, absent in new |
| `class_removed` | Entire class deleted |
| `interface_removed` | Interface deleted |
| `method_renamed` | FQN changed, matched by RenameMatcher (Levenshtein) |
| `class_renamed` | Class moved/renamed |
| `signature_changed` | Parameter type/count or return type changed |
| `function_removed` | Standalone function deleted |

## What It Does NOT Detect (Yet)

| Gap | Why | Phase 2 Fix |
|-----|-----|-------------|
| YAML config changes | No `SymfonyYamlExtractor` yet | Add Symfony YAML extraction |
| Attribute migrations | YAML→`#[Route]` not tracked | `AttributeExtractor` |
| Behavioral changes | Logic changes without signature change | Out of scope for static analysis |
| Bundle config keys | `framework.yaml` key renames | `SymfonyYamlExtractor` |
| Twig syntax changes | Twig not parsed | Twig extractor |

## Phase 2 Roadmap

1. **SymfonyYamlExtractor** — Parse `services.yaml`, `framework.yaml`, `security.yaml` for service definitions and config keys
2. **Attribute detection** — Track `#[Route]`, `#[IsGranted]`, `#[Template]` and map YAML→attribute migrations
3. **Fix templates** — Symfony-specific templates for namespace moves, method renames, attribute conversions
4. **Multi-version indexing** — Index 7.0 → 7.1 → ... → 8.0 for granular incremental upgrade paths
