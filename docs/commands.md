# CLI Commands Reference

All commands accept `--db=<path>` to specify the SQLite database file (defaults to `.data/evolver.sqlite`).
Use `make ev -- ...` for commands in the running dev service.
Use `make evr -- ... EXTRA_HOST_PATH=...` when you need a one-off container with an external directory mounted read-only at `/mnt/project`.

---

## `evolver index`

Parse a Drupal core checkout and store symbols in SQLite.

```
evolver index <path> --tag=<version> [--workers=<n>] [--db=<path>]
```

**Arguments:**
- `path` — Path to a Drupal core checkout directory

**Options:**
- `--tag`, `-t` — Version tag (e.g. `10.3.0`). Required.
- `--workers`, `-w` — Worker count. Defaults to auto-detected CPU count in file-backed databases.
- `--db` — Database file path. Default: `.data/evolver.sqlite`

**What it does:**
1. Parses the version tag into major.minor.patch
2. Creates or updates the version record for that tag
3. Walks the directory tree, classifying files by extension
4. Parses each PHP and YAML file with tree-sitter
5. Extracts symbols: functions, classes, methods, interfaces, traits, constants, services, routes, permissions
6. Detects deprecations from `@trigger_error` calls and `@deprecated` docblocks
7. Stores everything in SQLite with progress bar output
8. Skips unchanged files when the same version already has the same file path and SHA-256 hash
9. Replaces the previous symbol set for a changed file when re-indexing the same tag

**Example:**
```bash
# Index two versions for later diffing
make evr -- index /mnt/project --tag=10.2.0 EXTRA_HOST_PATH=../drupal
make evr -- index /mnt/project --tag=10.3.0 EXTRA_HOST_PATH=../drupal
```

**Output:**
```
Found 4532 files to index
 4532/4532 [============================] 100% Done!
Indexed 4532 files, 28847 symbols for version 10.3.0
```

---

## `evolver diff`

Compare two indexed versions and detect all changes.

```
evolver diff --from=<version> --to=<version> [--workers=<n>] [--db=<path>]
```

**Options:**
- `--from` — Source version tag. Required.
- `--to` — Target version tag. Required.
- `--workers`, `-w` — Worker count for rename matching.
- `--db` — Database file path.

**What it does:**
1. Loads both versions from the database
2. Runs hash-based diff to find removed/added symbols
3. Matches same-FQN symbols with different signatures for detailed param-level diff
4. Tracks deprecation lifecycle (newly deprecated, deprecated-then-removed)
5. Generates tree-sitter S-expression queries for each change
6. Generates fix templates for auto-fixable changes (rename/string replace/simple parameter insert)
   including `namespace_move` when a class/interface/trait moves namespace without changing short name
7. Replaces any existing change set for that version pair and stores the new rows in batches

**Change types detected:**
- `function_removed`, `class_removed`, `method_removed`, `interface_removed`, `trait_removed`
- `function_renamed`, `class_renamed`, `method_renamed`, `service_renamed`, `config_key_renamed`
- `service_removed`, `route_removed`, `permission_removed`, `config_key_removed`
- `signature_changed` (with param-level diff details in `diff_json`)
- `deprecated_added` (newly deprecated symbols)

**Severity levels:**
- `breaking` — removed or incompatibly changed
- `removal` — previously deprecated, now removed
- `deprecation` — newly deprecated (still works, but will be removed)

**Example:**
```bash
make ev -- diff --from=10.2.0 --to=10.3.0
```

**Output:**
```
Found 142 changes between 10.2.0 and 10.3.0:
  breaking: 23
  deprecation: 89
  removal: 30
```

---

## `evolver scan`

Scan a Drupal module, theme, or site against stored changes.

```
evolver scan <path> --target=<version> [--from=<version>] [--workers=<n>] [--db=<path>]
```

**Arguments:**
- `path` — Path to the project to scan

**Options:**
- `--target` — Target Drupal version to upgrade to. Required.
- `--from` — Current Drupal version. Auto-detected from `composer.lock` if omitted.
- `--workers`, `-w` — Worker count. Defaults to auto-detected CPU count in file-backed databases.
- `--db` — Database file path.

**What it does:**
1. Detects current core version from `composer.lock` (or uses `--from`)
2. Loads all changes between current and target versions
3. Validates that loaded changes use the current `query_version`
4. Reuses or updates the project row keyed by filesystem path
5. Creates a new `scan_run` so prior scan history is preserved
6. Walks project files (skips `vendor/` and `node_modules/`)
7. Parses each file with tree-sitter
8. Runs each change's `ts_query` against the parsed tree
9. Stores run-scoped matches in `code_matches` using scan run, change, path, and byte range identity

If stale queries are detected, scan fails fast with a message telling you to regenerate changes via `diff`.

```bash
make ev -- diff --from=11.4.0 --to=12.0.0
make evr -- scan /mnt/project --from=11.4.0 --target=12.0.0 EXTRA_HOST_PATH=../drupal
```

**Example:**
```bash
make evr -- scan /mnt/project --target=11.0.0 EXTRA_HOST_PATH=../my-custom-module

# Auto-detects current version from composer.lock
make evr -- scan /mnt/project --target=10.3.0 EXTRA_HOST_PATH=../my-site

# Specify current version explicitly
make evr -- scan /mnt/project --target=10.3.0 --from=10.2.0 EXTRA_HOST_PATH=../my-custom-module
```

**Output:**
```
Detected current version: 10.2.0
Loaded 142 changes to scan for
Scanning 87 files
 87/87 [============================] 100%
Found 12 matches in project mymodule
Scan run: 7
```

---

## `evolver apply`

Apply template-based fixes to scanned matches.

```
evolver apply (--project=<name> | --run=<id>) [--dry-run] [--interactive] [--db=<path>]
```

**Options:**
- `--project`, `-p` — Project name. Optional when `--run` is provided.
- `--run`, `-r` — Specific scan run id. Optional when `--project` is provided.
- `--dry-run` — Show diffs only, write nothing.
- `--interactive`, `-i` — Ask yes/no before each change.
- `--db` — Database file path.

**What it does:**
1. Loads pending matches that have fix templates from the requested run, or from the latest completed run for the project
2. Groups by file, sorts bottom-up by byte offset (fallback: line number)
3. For each match: applies the template, generates diff
4. Uses byte offsets when available for deterministic replacements (fallback to text search for legacy matches; ambiguous legacy matches are failed, not guessed)
5. Detects overlapping match ranges and marks overlapping candidates as `failed` (conflict)
6. In `--dry-run` mode: prints diffs, writes nothing, reports `would_apply/skipped/failed/conflicts`
7. In `--interactive` mode: prompts for each change
8. Default mode: applies all template-based fixes and reports summary counts

**Fix template types:**
- `function_rename` — Renames function calls (e.g. `drupal_render` → `\Drupal::service('renderer')->render`)
- `parameter_insert` — Inserts a parameter at a given position
- `string_replace` — Replaces a string literal (service names, etc.)
- `namespace_move` — Updates namespace in use statements

**Example:**
```bash
# Preview changes
make ev -- apply --project=mymodule --dry-run

# Preview a specific run
make ev -- apply --run=7 --dry-run

# Apply interactively
make ev -- apply --project=mymodule --interactive

# Apply all
make ev -- apply --project=mymodule
```

**Output (dry-run):**
```
--- a/src/MyService.php
+++ b/src/MyService.php
@@ -45,1 +45,1 @@
-drupal_render($element)
+\Drupal::service('renderer')->render($element)

2 fixes would apply (skipped: 0, failed: 0, conflicts: 0)
```

---

## `evolver report`

Show scan results and upgrade readiness.

```
evolver report (--project=<name> | --run=<id>) [--format=table|json] [--db=<path>]
```

**Options:**
- `--project`, `-p` — Project name. Optional when `--run` is provided.
- `--run`, `-r` — Specific scan run id. Optional when `--project` is provided.
- `--format`, `-f` — Output format: `table` (default) or `json`.
- `--db` — Database file path.

**Example:**
```bash
make ev -- report --project=mymodule
make ev -- report --run=7
make ev -- report --project=mymodule --format=json
```

**Output (table):**
```
+----------------------+------+-----------------------+----------+----------+---------+
| File                 | Line | Change                | Severity | Fix      | Status  |
+----------------------+------+-----------------------+----------+----------+---------+
| src/MyService.php    | 45   | function_removed      | breaking | template | pending |
| src/MyService.php    | 112  | signature_changed     | breaking | template | pending |
| mymodule.services.yml| 12   | service_removed       | breaking | template | pending |
| src/Form/MyForm.php  | 78   | deprecated_added      | deprec.. | manual   | pending |
+----------------------+------+-----------------------+----------+----------+---------+
Summary: 3 breaking, 1 deprecation, 2 auto-fixable
```

---

## `evolver status`

Show database statistics.

```
evolver status [--db=<path>]
```

**Example:**
```bash
evolver status
```

**Output:**
```
Evolver Status

Indexed versions: 2
  10.2.0 — 4523 files, 28234 symbols (indexed 2024-01-15 10:23:45)
  10.3.0 — 4532 files, 28847 symbols (indexed 2024-01-15 10:25:12)
Total symbols:    57081
Total changes:    142
Projects scanned: 1
Scan runs:        3
Jobs:             3 (1 active)
Code matches:     12
```

---

## `evolver query`

Debug tool: run a raw tree-sitter S-expression query against a file.

```
evolver query <pattern> <file>
```

**Arguments:**
- `pattern` — Tree-sitter S-expression query string
- `file` — File to query (extension determines language)

**Example:**
```bash
# Find all function calls named "drupal_render"
evolver query '(function_call_expression function: (name) @fn (#eq? @fn "drupal_render"))' mymodule.module

# Find all class declarations
evolver query '(class_declaration name: (name) @cls)' src/MyClass.php

# Find string literals containing a service name
evolver query '(string_content) @str (#eq? @str "old.service")' mymodule.services.yml
```

**Output:**
```
Found 2 matches:

Match #1:
  @fn [45:3] name = "drupal_render"

Match #2:
  @fn [112:7] name = "drupal_render"
```

---

## `make yaml-search`

Helper around `scripts/semantic-yaml-search.php` for searching indexed YAML symbols by semantic fields already stored in SQLite.

Use it for:
- service ids from `*.services.yml`
- extension metadata from `*.info.yml`
- route refs from `*.links.*.yml`
- Drupal asset libraries from `*.libraries.yml`
- install/module references from `recipe.yml`
- exported config dependencies from `db/config/*.yml`

```bash
make yaml-search SEARCH_TAG=<version> SEARCH_TERM=<term> [SEARCH_TYPES=type1,type2] [SEARCH_DB=<path>] [SEARCH_LIMIT=50]
```

**Variables:**
- `SEARCH_TAG` — Indexed version tag to search. Defaults to `INDEX_TAG`.
- `SEARCH_TERM` — Required search term.
- `SEARCH_TYPES` — Optional comma-separated symbol types such as `service,module_info,theme_info,drupal_library`.
- `SEARCH_DB` — Optional SQLite path. Defaults to `.data/evolver.sqlite`.
- `SEARCH_LIMIT` — Result limit. Defaults to `50`.

Asset-path searches use paths relative to the indexed root. If you indexed the whole Drupal checkout, search for `core/modules/block/js/block.admin.js`. If you indexed only `core/modules/block`, search for `js/block.admin.js`.

**Examples:**
```bash
# Find a specific service id
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=service SEARCH_TERM=block.repository

# Find a service by implementation class
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=service SEARCH_TERM='Drupal\block\BlockRepository'

# Find module info files that mention block as a dependency or extension reference
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=module_info,theme_info SEARCH_TERM=block

# Find links that reference a Drupal route
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=link_task,link_contextual SEARCH_TERM=entity.block.edit_form

# Find Drupal libraries that own a specific JS or CSS asset file
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=drupal_library SEARCH_TERM=core/modules/block/js/block.admin.js

# Find recipe manifests that install views_ui
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=recipe_manifest SEARCH_TERM=views_ui
```

**`jq` examples:**
```bash
# Show just symbol type, id, and file path
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=service SEARCH_TERM=block.repository | jq '.results[] | {symbol_type, fqn, file_path}'

# Show module info matches with dependency targets
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=module_info SEARCH_TERM=block | jq '.results[] | {fqn, file_path, dependency_targets: .metadata.dependency_targets}'

# Show Drupal libraries with their resolved asset paths
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=drupal_library SEARCH_TERM=block.admin.js | jq '.results[] | {fqn, file_path, asset_paths: .metadata.asset_paths}'
```

**Output:**
```json
{
  "tag": "11.0.0",
  "term": "block.repository",
  "types": ["service"],
  "count": 1,
  "results": [
    {
      "symbol_type": "service",
      "fqn": "block.repository",
      "file_path": "block.services.yml",
      "resolved_class_fqn": "Drupal\\block\\BlockRepository",
      "resolved_class_file_path": "src/BlockRepository.php",
      "signature": {
        "arguments": "@entity_type.manager",
        "class": "Drupal\\block\\BlockRepository",
        "tags": null
      },
      "metadata": null
    }
  ]
}
```

---

## `evolver serve`

Serve the local Amp/Twig web UI.

```
evolver serve [--host=<host>] [--port=<port>] [--db=<path>]
```

**Options:**
- `--host` — Bind host. Default: `0.0.0.0`
- `--port` — Bind port. Default: `8080`
- `--db` — Database file path.

**Example:**
```bash
make web
```

The UI is intended for managed branch-based scans. It lets you register Git-backed projects, save branches, queue scan jobs, inspect run history, and compare two runs for the same project.
It also exposes the indexed knowledge base under `Versions -> Symbols`: each symbol row links to a symbol detail page, and service/class pairs show semantic links between YAML service ids and PHP implementation classes when both were indexed.
Managed remote projects are cached under `.cache/projects/<project-slug>` by default. The shared repo lives in `repo/`, and queued scans materialize ephemeral sources in `runs/<run-id>/source`. Override the cache root with `EVOLVER_PROJECT_CACHE_DIR` if needed.
That applies to new `git_remote` registrations. Existing stored remote project paths are still used as-is, and the initial branch-detection probe uses a temporary `/tmp/evolver_remote_detect_*` clone that is auto-cleaned.
On a project page, the scan form compares one project branch against a Drupal core upgrade path (`from_core_version -> target_core_version`). The separate `Compare Scan Runs` page compares the findings produced by two stored runs.

---

## `evolver queue:work`

Process persisted scan jobs.

```
evolver queue:work [--once] [--sleep=<seconds>] [--db=<path>]
```

**Options:**
- `--once` — Process at most one queued job and exit.
- `--sleep` — Idle sleep interval between polls. Default: `1`
- `--db` — Database file path.

**Example:**
```bash
make worker
make ev -- queue:work --once
```

The worker performs Git materialization, branch checkout, source version detection, project scanning, and run completion bookkeeping. The web server does not execute scans in-request.

---

## Typical Workflow

```bash
# 1. Index the Drupal versions you're upgrading between
evolver index /path/to/drupal --tag=10.2.0
evolver index /path/to/drupal --tag=10.3.0

# 2. Generate the change set
evolver diff --from=10.2.0 --to=10.3.0

# 3. Scan your project
evolver scan /path/to/mymodule --target=10.3.0

# 4. Review what was found
evolver report --project=mymodule

# 5. Preview fixes for the latest run
evolver apply --project=mymodule --dry-run

# 6. Apply fixes interactively
evolver apply --project=mymodule --interactive

# 7. Check what's left
evolver report --project=mymodule
```
