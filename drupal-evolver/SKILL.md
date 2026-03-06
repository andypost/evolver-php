---
name: drupal-evolver-compare
description: Compare Drupal modules or themes across versions or branches using DrupalEvolver. Use when asked to compare changes, find breaking changes, or analyze differences in Drupal components between tags or branches.
---

# Comparing Modules and Themes with DrupalEvolver

This skill guides you through the process of comparing specific Drupal modules or themes across different versions or branches (e.g., comparing `11.x` to `main`, or `10.0.0` to `11.x`).

## Core Concepts

*   **Semver Tags Required:** The DrupalEvolver indexer requires a valid semantic versioning tag (e.g., `11.9.99` instead of `11.x`). You must translate branch names into valid semver tags for the indexer to accept them.
*   **Parallel Workers:** For speed, use the `--workers=N` flag. `4` is a good default for individual modules, while `8` is better for parsing the full core.
*   **Database Cleanup:** If you are running multiple isolated comparisons, you may want to clean the database state first.

## Workflow: Comparing a Specific Module/Theme

To accurately compare a component (like the `views` or `migrate` module) between two branches, follow these steps. 

### Step 1: Ensure a Clean State (Optional)
If you want to ensure the diff only contains changes for the module you are currently interested in, start by deleting the existing database:
```bash
rm -f .data/evolver.sqlite*
```
*(Note: If you want to keep previously indexed versions, you can use `sqlite3 .data/evolver.sqlite "DELETE FROM versions WHERE tag = '...'"` instead).*

### Step 2: Index the First Version (e.g., 10.0.0 or 11.x)
Check out the required branch in the sibling `drupal` repository, and run the indexer on the target directory. Use a semver-compatible tag.

```bash
# Example for 11.x
(cd ../drupal && git checkout 11.x)
docker compose run --rm evolver index /drupal/core/modules/my_module --tag=11.9.99 --workers=4
```

### Step 3: Index the Second Version (e.g., main or 12.0.0)
Check out the second branch and repeat the indexing process.

```bash
# Example for main
(cd ../drupal && git checkout main)
docker compose run --rm evolver index /drupal/core/modules/my_module --tag=12.0.0 --workers=4
```

### Step 4: Run the Diff Comparison
Use the `diff` command to calculate changes between the two indexed tags.

```bash
docker compose run --rm evolver diff --from=11.9.99 --to=12.0.0
```

The tool will output the total number of changes, categorized by breaking changes, deprecations, and removals.

### Step 5: (Optional) Inspect the Results
If you need to see exactly what changed, you can query the SQLite database directly:
```bash
sqlite3 .data/evolver.sqlite "SELECT old_fqn, change_type FROM changes WHERE from_version_id = (SELECT id FROM versions WHERE tag = '11.9.99') AND to_version_id = (SELECT id FROM versions WHERE tag = '12.0.0')"
```

## Advanced: Full Core vs. Single Module
*   **Single Module (Fast):** Indexing just `/drupal/core/modules/views` is extremely fast (1-3 seconds). This is best for seeing changes strictly contained within that module's files.
*   **Full Core (Comprehensive):** If you want to see if symbols from a module moved to a core library (or vice-versa), you should index the entire `/drupal/core` directory for both versions before diffing. Full core indexing takes about ~50 seconds with 8 workers.
