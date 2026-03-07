# Evolver

Evolver is a PHP CLI tool with a local web UI. It uses [tree-sitter](https://tree-sitter.github.io/) via FFI to analyze Drupal core across version tags, detect breaking changes and deprecations, queue and compare project scan runs, and apply mechanical fixes.

## What It Does

1. **Index** Drupal core versions — parses all PHP and YAML files with tree-sitter, extracts symbols (functions, classes, methods, services, routes, permissions) into SQLite
2. **Diff** two indexed versions — detects removals, renames, signature changes, new deprecations, and generates tree-sitter query patterns for each change
3. **Scan** a Drupal module/theme/site — runs the generated patterns against your code and reports every match with location and severity
4. **Apply** mechanical fixes — uses stored fix templates to do surgical source-level replacements (function renames, parameter inserts, service renames, namespace moves)

## Quick Start (Docker)

The recommended way to run Evolver is using Docker. This provides a pre-configured environment with all necessary Tree-sitter libraries and PHP 8.5.

1. **Build and Start:**
   ```bash
   make build
   make up
   ```
   This starts the full stack (Web UI on port 8080 and the background Queue Worker) in a single container.

2. **Access Web UI:** Open `http://localhost:8080`

3. **Check Status (CLI):**
   ```bash
   make ev -- status
   ```

## Development & Execution

The `Makefile` provides several shortcuts for interacting with the running container.

### Common Execution
- `make ev -- <args>` - Execute an evolver command (e.g., `make ev -- status`)
- `make evr -- <args> EXTRA_HOST_PATH=/path` - Run evolver with an external volume mounted at `/mnt/project`
- `make tests` - Run the PHPUnit test suite

### Debugging & Tools
- `make sh` - Enter the container shell as the `evolver` user
- `make shell0` - Enter the container shell as `root`
- `make php -- <args>` - Run raw PHP commands
- `make phpsh` - Start an interactive PHP shell (`php -a`)
- `make r -- <cmd>` - Run any shell command in the container (alias for `make e`)

## CLI Commands

| Command | Description |
|---------|-------------|
| `evolver index <path> --tag=<v> [-w 4]` | Parse Drupal core and store symbols. Supports parallel workers. |
| `evolver diff --from=<v1> --to=<v2>` | Compare two indexed versions and detect all changes. |
| `evolver scan <path> --target=<v> [-w 4]` | Scan a project against stored changes. Supports parallel workers. |
| `evolver apply --project=<name>|--run=<id>` | Apply template-based fixes to the latest project run or a specific run. |
| `evolver report --project=<name>|--run=<id>` | Show scan results as table or JSON. |
| `evolver status` | Database stats: versions, symbols, changes, matches. |
| `evolver query <pattern> <file>` | Debug: run a raw tree-sitter S-expression query. |
| `evolver serve [--host --port]` | Serve the Amp/Twig web UI. |
| `evolver queue:work [--once]` | Process persisted branch-scan jobs. |

See [docs/commands.md](docs/commands.md) for full command reference and [docs/usage.md](docs/usage.md) for workflow examples.

## Architecture

Evolver uses a single-container architecture for simplicity. Both the **Web Server** (Amp HTTP) and the **Queue Worker** share the same SQLite database and filesystem.

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

- **Web Server:** Handles the UI, API, and job queueing.
- **Worker:** Processes background tasks like indexing and scanning using `pcntl_fork` for multi-core parallelism.
- **FFI Preload:** The Docker image uses PHP 8.5 with FFI Preload enabled for maximum Tree-sitter performance.

## License

GPL-2.0-or-later
