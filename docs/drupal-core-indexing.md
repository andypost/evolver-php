# Drupal Core Indexing Plan

## Overview

This plan details how to index Drupal core from a sibling checkout at `../drupal` for change detection and automated upgrade assistance in Evolver.

## Prerequisites

### Drupal Core Checkout

A full Drupal core repository clone at `../drupal` relative to the `evolver-php` checkout.

```bash
# From the evolver-php directory
cd /path/to/projects
git clone https://github.com/drupal/core.git drupal
cd evolver-php

# Or if you already have Drupal core elsewhere, symlink it
ln -s /path/to/drupal ../drupal
```

**Recommended structure:**
```
~/projects/
├── drupal/           # Drupal core repository
│   ├── core/
│   │   ├── lib/
│   │   ├── modules/
│   │   ├── themes/
│   │   └── ...
│   └── .git/
└── evolver-php/      # This project
    ├── src/
    └── .data/evolver.sqlite
```

### Docker Setup (Recommended)

For one-off indexing and scanning, use `make evr -- ... EXTRA_HOST_PATH=...`. The Makefile runs a fresh container with `docker compose run --rm --volume ...` and mounts that path read-only at `/mnt/project`.

**Verify user/group IDs match:**
```bash
# Check your local UID/GID
id

# Should match these defaults (or set in .env)
echo "LOCAL_UID=$(id -u)" > .env
echo "LOCAL_GID=$(id -g)" >> .env
```

## Indexing Workflow

### Step 1: Initial Setup

```bash
cd evolver-php

# Build container with your user ID
make build
make up

# Create .env for persistent user mapping (if needed)
echo "LOCAL_UID=$(id -u)" > .env
echo "LOCAL_GID=$(id -g)" >> .env

# Verify Drupal is accessible
make er -- ls -la /mnt/project/core/lib/Drupal.php EXTRA_HOST_PATH=../drupal
```

### Step 2: Checkout and Index Target Versions

**Option A: Worktree approach (keeps single drupal repo)**

```bash
cd ../drupal

# Fetch all tags
git fetch --tags --depth=1

# Create worktrees for each version to index
git worktree add ../drupal-10.2.0 10.2.0
git worktree add ../drupal-10.3.0 10.3.0
git worktree add ../drupal-11.0.0 11.0.0

cd evolver-php
```

**Option B: Separate clones (simpler, more disk space)**

```bash
# From projects directory
git clone --depth=1 --branch=10.2.0 https://github.com/drupal/core.git drupal-10.2.0
git clone --depth=1 --branch=10.3.0 https://github.com/drupal/core.git drupal-10.3.0
git clone --depth=1 --branch=11.0.0 https://github.com/drupal/core.git drupal-11.0.0
```

### Step 3: Index Each Version

```bash
# Using the /mnt/project mount
make evr -- index /mnt/project --tag=10.2.0 EXTRA_HOST_PATH=../drupal-10.2.0
make evr -- index /mnt/project --tag=10.3.0 EXTRA_HOST_PATH=../drupal-10.3.0
make evr -- index /mnt/project --tag=11.0.0 EXTRA_HOST_PATH=../drupal-11.0.0
```

**Expected output:**
```
Found 4532 files to index
 4532/4532 [============================] 100% Done!
Indexed 4532 files, 28847 symbols for version 10.2.0
```

### Step 4: Verify Indexed Versions

```bash
make ev -- status
```

Output should show:
```
Evolver Status

Indexed versions: 3
  10.2.0 — 4523 files, 28234 symbols (indexed 2025-03-05 ...)
  10.3.0 — 4532 files, 28847 symbols (indexed 2025-03-05 ...)
  11.0.0 — 4678 files, 31245 symbols (indexed 2025-03-05 ...)
Total symbols:    88326
```

## Change Detection

### Generate Diffs

```bash
# Compare adjacent releases
make ev -- diff --from=10.2.0 --to=10.3.0
make ev -- diff --from=10.3.0 --to=11.0.0

# Or compare across major versions
make ev -- diff --from=10.2.0 --to=11.0.0
```

**Expected output:**
```
Found 342 changes between 10.2.0 and 11.0.0:
  breaking: 87
  deprecation: 203
  removal: 52
```

## Scanning Target Projects

```bash
# Scan a module in the same parent directory
make evr -- scan /mnt/project --target=11.0.0 EXTRA_HOST_PATH=../mymodule

# View report
make ev -- report --project=mymodule

# Apply fixes
make ev -- apply --project=mymodule --dry-run
make ev -- apply --project=mymodule --interactive
```

## Automation Strategies

### GitHub Actions: Index Core On Schedule

```yaml
name: Index Drupal Core

on:
  schedule:
    - cron: '0 0 1 * *'  # Monthly: first of month
  workflow_dispatch:

jobs:
  index:
    runs-on: ubuntu-latest
    container:
      image: ghcr.io/andypost/evolver-php:latest

    steps:
      - uses: actions/checkout@4

      - name: Cache Drupal core
        uses: actions/cache@4
        with:
          path: drupal
          key: drupal-${{ github.run_id }}

      - name: Clone Drupal core
        run: |
          git clone --depth=1 --branch=11.0.x https://github.com/drupal/core.git drupal

      - name: Index version
        run: evolver index drupal --tag=11.0.x

      - name: Upload database
        uses: actions/upload-artifact@4
        with:
          name: evolver-db
          path: .data/evolver.sqlite
```

### Local: Watch for Drupal Core Updates

```bash
# Add to ~/.bashrc or similar
drupal-update-check() {
  cd ~/projects/evolver-php
  cd ../drupal
  git fetch --tags
  LATEST=$(git tag -l "11.*" | sort -V | tail -1)
  cd ../evolver-php
  make evr -- index /mnt/project --tag=$LATEST EXTRA_HOST_PATH=../drupal
}
```

## Troubleshooting

### "Permission denied" errors

The container runs as your user ID. If files were created as root:

```bash
# Fix ownership
sudo chown -R $USER:$USER .data/evolver.sqlite
```

### "../drupal not found" inside container

The one-off helper must receive the host path to mount. External mounts are always read-only. Verify:

```bash
make er -- ls -la /mnt/project EXTRA_HOST_PATH=../drupal
```

### Out of memory during indexing

Drupal core is large (~4500 files). Adjust Docker limits:

```yaml
# Example one-off run with extra limits
docker compose run --rm --volume ../drupal:/mnt/project:ro evolver php bin/evolver index /mnt/project --tag=10.2.0
```

### Git worktree conflicts

When using worktrees, you can't have the same branch checked out multiple times:

```bash
cd ../drupal
git worktree list
# Remove old worktrees when done
git worktree remove ../drupal-10.2.0
```

## Real-world Case Study: Drupal 11.x to 12.0.0 (main)

In March 2025, we verified the tool by indexing and diffing the `user` and `node` modules between the current `11.x` branch and the `main` branch (targeted for Drupal 12.0.0).

### Verification Steps

1.  **Index 11.x (User Module):**
    ```bash
    (cd ../drupal && git checkout 11.x)
    make evr -- index /mnt/project/core/modules/user --tag=11.9.99 EXTRA_HOST_PATH=../drupal
    ```
2.  **Index main (User Module):**
    ```bash
    (cd ../drupal && git checkout main)
    make evr -- index /mnt/project/core/modules/user --tag=12.0.0 EXTRA_HOST_PATH=../drupal
    ```
3.  **Generate Diff:**
    ```bash
    make ev -- diff --from=11.9.99 --to=12.0.0
    ```

### Verified Results

| Module | Version Range | Files | Symbols | Changes Detected |
|--------|---------------|-------|---------|------------------|
| **User** | 11.x → 12.x | 410 → 332 | 1851 → 1657 | 13 removals |
| **Node** | 11.x → 12.x | 375 → 299 | 1514 → 1289 | 7 breaking, 22 removals |

### Key Findings

#### 1. Procedural to Attribute Hook Migration
The tool correctly identified the removal of several legacy global functions and hooks, while simultaneously indexing the new `#[Hook]` attribute implementations in the `main` branch.

#### 2. API Removals
- **User Module:** Correctly flagged the removal of legacy migration plugins (e.g., `EntityUserRole`, `ConvertTokens`) and internal entity methods (`User::__get`).
- **Node Module:** Identified the removal of long-standing global functions like `node_mark()`, `node_title_list()`, and `node_add_body_field()`.

#### 3. Breaking Signature Changes
- Detected constructor signature changes in critical classes like `NodeAccessGrantsCacheContext` and `NodePreviewController`.
- Flagged interface changes in `NodeTypeInterface` (e.g., `getPreviewMode` and `setPreviewMode`).

## Performance Notes

| Operation | Time (approx) | Notes |
|-----------|---------------|-------|
| Index one version | 3-5 min | 4500 files, depends on CPU |
| Diff two versions | 5-10 sec | Pure SQL queries |
| Scan a module | 5-30 sec | Depends on module size |
| Apply fixes | <1 sec | Per file |

**Optimization tips:**
- Index once, scan many times (database is cached)
- Use `:memory:` SQLite for disposable operations
- Use `--db` option to maintain multiple databases for different use cases

## File Ownership Notes

With the new Docker setup:
- Files created inside the container have YOUR uid:gid
- `.data/evolver.sqlite` is writable by your user directly
- No `sudo` needed to delete or inspect the database

Verify:
```bash
ls -la .data/evolver.sqlite
# Should show your username, not root
```
