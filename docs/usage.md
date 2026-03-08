# Usage Guide

Complete workflow examples for Evolver.

## Initial Setup

The recommended way to run Evolver is via `make`, which manages a long-running Docker container with hot caches (JIT, FFI Preload) for maximum performance.

```bash
# Clone the repository
git clone https://github.com/andypost/evolver-php.git
cd evolver-php

# Build and start the engine
make build
make up

# Verify it works
make ev -- status

# Optional: start the local dashboard and worker in separate terminals
make web
make worker
# Open http://localhost:8080
```

For ad-hoc commands inside the running dev container, use `make e -- ...`. For external mounts, use `make er -- ...` or `make evr -- ...`. For PHPUnit, use `make tests` or `make e -- vendor/bin/phpunit ...`.

## Managed Projects in the Web UI

The local web UI is intended for repeated custom module or site analysis:

```bash
make web
make worker
```

Then open `http://localhost:8080` and:
- register a Git-backed project
- save one or more branches
- queue scans for a specific project branch against a source-to-target core upgrade path
- compare two completed scan runs for the same project
- open a version's symbol browser and click a symbol name to inspect its linked details

The worker performs the blocking Git and scan work. The web UI only handles forms, run history, and SSE status updates.
New managed remote projects use a shared Git cache under `.cache/projects/<project-slug>/repo` by default, with ephemeral scan sources under `.cache/projects/<project-slug>/runs/<run-id>/source`. Set `EVOLVER_PROJECT_CACHE_DIR` to override that root. Existing stored remote project paths are still honored, and branch detection uses a temporary `/tmp/evolver_remote_detect_*` clone that is removed automatically.
The project page has two separate concepts:
- `Scan Branch Against Core Upgrade Path` checks one project branch against indexed Drupal core changes
- `Compare Scan Runs` compares findings from two already-completed scans of the same project

## Complete Workflow: Drupal 10 → 11 Upgrade

This example walks through upgrading a custom module from Drupal 10.2.0 to Drupal 11.0.0.

### Step 1: Index Drupal Core Versions

Ensure you have a sibling `../drupal` checkout or another project path you want to mount. For external indexing and scanning, use `make evr -- ... EXTRA_HOST_PATH=...`; the one-off container mounts that path read-only at `/mnt/project`.

```bash
# Index 10.2.0
(cd ../drupal && git checkout 10.2.0)
make evr -- index /mnt/project --tag=10.2.0 --workers=8 EXTRA_HOST_PATH=../drupal

# Index 11.0.0
(cd ../drupal && git checkout 11.0.0)
make evr -- index /mnt/project --tag=11.0.0 --workers=8 EXTRA_HOST_PATH=../drupal
```

Re-running `index` for the same tag is safe: unchanged file paths are skipped, while changed files are refreshed in place.

### Step 2: Generate Change Set

```bash
make ev -- diff --from=10.2.0 --to=11.0.0 --workers=8
```

### Step 3: Scan Your Module

The engine container mounts this checkout at `/app`. For external projects, use `make evr -- ... EXTRA_HOST_PATH=...`; the one-off container will mount that path read-only at `/mnt/project`. The stored project name is the basename of the scanned path.

```bash
# Scan a project from the external mount
make evr -- scan /mnt/project --target=11.0.0 EXTRA_HOST_PATH=../my-custom-module
```

The CLI scan now creates a new `scan_run` and prints its run id. Scan history is preserved instead of replacing prior runs.

### Step 4: Review Findings

```bash
make ev -- report --project=my-custom-module
make ev -- report --run=1
```

### Step 5: Apply Fixes

**Dry Run (Preview)**
```bash
make ev -- apply --project=my-custom-module --dry-run
make ev -- apply --run=1 --dry-run
```

**Apply all automatically**
```bash
make ev -- apply --project=my-custom-module
```

**Interactive mode (confirm each change)**
```bash
make ev -- apply --project=my-custom-module --interactive
```

## Advanced Usage

### Comparing Core Modules Across Branches

```bash
# Index all core modules from 11.x
(cd ../drupal && git checkout 11.x)
make evr -- index /mnt/project/core/modules --tag=11.9.99-modules EXTRA_HOST_PATH=../drupal

# Index all core modules from main
(cd ../drupal && git checkout main)
make evr -- index /mnt/project/core/modules --tag=12.0.0 EXTRA_HOST_PATH=../drupal

# Compare
make ev -- diff --from=11.9.99-modules --to=12.0.0
```

### Comparing Latest 10.x Modules to 11.x

```bash
# Example: latest 10.x tag vs 11.x modules
(cd ../drupal && git checkout 10.6.3)
make evr -- index /mnt/project/core/modules --tag=10.6.3-modules EXTRA_HOST_PATH=../drupal

(cd ../drupal && git checkout 11.x)
make evr -- index /mnt/project/core/modules --tag=11.9.99-modules EXTRA_HOST_PATH=../drupal

make ev -- diff --from=10.6.3-modules --to=11.9.99-modules --workers=1
```

### Memory & Performance Profiling

Run the full profiling suite to compare different profilers (SPX, XHProf, Meminfo, Memprof):

```bash
make profile EXTRA_HOST_PATH=../drupal
make profile-report
```

### Indexing SDC Components

Drupal's Single Directory Components (SDC) require their parent extension (module, theme, or profile) to be indexed for full cross-referencing.

```bash
# Index a theme with SDC components
make evr -- index /mnt/project/core/profiles/demo_umami/themes/umami --tag=12.0.0-umami EXTRA_HOST_PATH=../drupal

# Index all profiles (including Umami)
make evr -- index /mnt/project/core/profiles --tag=12.0.0-profiles EXTRA_HOST_PATH=../drupal
```

When indexing SDCs, Evolver automatically:
1. Detects `*.component.yml` files and creates `sdc_component` symbols.
2. Identifies associated `.twig`, `.css`, and `.js` files within the component directory.
3. Tags all symbols (selectors, variables, functions) found in those files with the SDC component ID (e.g., `umami:badge`).
4. Creates semantic links between the `sdc_component` symbol and all its constituent assets.

### Debugging Queries

Test tree-sitter S-expression queries directly:

```bash
make ev -- query '(class_declaration name: (name) @cls)' src/Indexer/CoreIndexer.php
```

Search semantic YAML and SDC data already indexed into SQLite:

```bash
# Find SDC components
make yaml-search SEARCH_TAG=12.0.0-profiles SEARCH_TYPES=sdc_component SEARCH_TERM=badge

# Find service ids
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=service SEARCH_TERM=block.repository

# Find module/theme info files that mention "block"
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=module_info,theme_info SEARCH_TERM=block

# Find Drupal libraries that own a specific asset file
make yaml-search SEARCH_TAG=11.0.0 SEARCH_TYPES=drupal_library SEARCH_TERM=core/modules/block/js/block.admin.js

# Narrow the JSON output with jq
make yaml-search SEARCH_TAG=12.0.0-profiles SEARCH_TYPES=sdc_component SEARCH_TERM=badge | jq '.results[] | {fqn, file_path, metadata: .metadata}'
```

For `drupal_library` and `sdc_component` asset searches, the path is relative to the indexed root.

In the web UI, open `Knowledge Base`, choose a version, then open `Symbols`. Symbol names now link to a detail page:
- **YAML Services**: shows the linked PHP implementation class and reverse `registered service` relationship.
- **Drupal Libraries**: shows related JS and CSS symbols from referenced assets. JS/CSS symbols link back to the owning library.
- **SDC Components**: shows all symbols (CSS selectors, Twig variables/blocks, JS symbols) belonging to that component. Assets also provide a `part of component` link back to the main SDC declaration.

## Troubleshooting

### Disk I/O or Locking Errors
If you encounter `SQLSTATE[HY000]: General error: 10 disk I/O error`, it is usually due to a corrupted SQLite WAL file or a permission mismatch on the `.data` directory.

```bash
make down
rm -rf .data/evolver.sqlite*
make up
```

### Resetting the Environment
```bash
make clean
make build
make up
```
