# Docker Setup

## Overview

Evolver now runs with a single container service: `evolver`.

That service is used for:
- normal in-repo CLI commands via `exec`
- ad-hoc shell commands (`make e -- ...`)
- one-off external analysis via `make er -- ...` and `make evr -- ...`
- interactive shell (`make shell`)
- the Amp web UI (`make web`)
- the queue worker (`make worker`)
- FFI readiness checks (`make r`)

## Why One Container Is Enough

Both old services (`evolver` and `dev`) were built from the same Dockerfile and had the same runtime dependencies.
The only practical difference was command style (`run` vs `exec`).

Using one `evolver` service avoids drift where one image is rebuilt and the other is stale (for example, FFI loaded in one but missing in the other).

## Build Performance

`make build` runs `docker compose build` with `COMPOSE_BAKE=true`, so BuildKit's Bake frontend is used by default.

The image build is also structured to keep rebuilds fast:
- `composer.json` and `composer.lock` are copied before the rest of the source tree, so normal code edits do not invalidate `composer install`
- Composer uses a BuildKit cache mount at `/home/evolver/.composer/cache`
- local helper checkouts like `pharborist/` and `drupalmoduleupgrader/` are excluded from the Docker build context

No extra BuildKit bootstrap or warmup step is required.

## OPcache/JIT Defaults

The image enables OPcache for CLI, but keeps JIT disabled by default:

- `opcache.enable_cli=1`
- `opcache.jit=disable`
- `opcache.jit_buffer_size=0`

This is intentional: indexing/scanning benchmarks in this repo showed no consistent speedup from JIT, and in many runs JIT was slower.

If you need to experiment, enable JIT per command with `-d` flags instead of changing base image defaults.

## FFI Parser Preparation Flow

### 1) Build-time packages

The Docker image installs all parser dependencies:
- `php85`
- matching `php85-ffi`, `php85-pdo_sqlite`
- `tree-sitter`, `tree-sitter-php`, `tree-sitter-yaml`
- `tree-sitter-twig` built from source and installed as `/usr/lib/libtree-sitter-twig.so`

Twig is compiled in the intermediate `ts-source` stage. The final runtime image keeps the grammar `.so` files and `tree-sitter`, but not the Twig build toolchain.

The image installs:
- memprof: `php85-pecl-memprof`
- meminfo: `php85-meminfo`

Core and grammar libraries come from Alpine packages:
- `/usr/lib/libtree-sitter.so*` (versioned SONAME on Alpine)
- `/usr/lib/libtree-sitter-php.so`
- `/usr/lib/libtree-sitter-yaml.so`
- `/usr/lib/libtree-sitter-twig.so`

### 2) Runtime FFI enablement

FFI is enabled by Alpine PHP config (series-aware path: `/etc/phpXX/conf.d/...`):

```ini
ffi.enable=1
```

### 3) Runtime library resolution (no manual bootstrap required)

The compose service sets:

```yaml
EVOLVER_GRAMMAR_PATH: /usr/lib
```

Parser loading is resilient:
- `Parser` resolves `libtree-sitter.so` or versioned SONAME variants from `$EVOLVER_GRAMMAR_PATH` and falls back to `/usr/lib`.
- `LanguageRegistry` resolves grammar `.so` files across common names and paths:
  - `tree-sitter-<lang>.so`
  - `libtree-sitter-<lang>.so`
  - `/usr/lib/tree-sitter/<lang>.so`

This means FFI parsing works even when `/app` is bind-mounted from host.

## Common Commands

```bash
# Build image
make build

# Start the long-running service
make up

# Check status
make ev -- status

# Start the local web UI and worker in separate terminals
make web
make worker

# Run any shell command inside the same container image
make e -- php --ri FFI

# Open shell
make shell

# FFI smoke check
make r
```

## Memprof Profiling (Parser/Scanner)

Use the consolidated profiling targets:

```bash
make up
make profile EXTRA_HOST_PATH=../drupal
make profile-report
```

Reports are written under `.data/profiles/`.

## Index and Compare Core

```bash
make evr -- index /mnt/project --tag=10.2.0 EXTRA_HOST_PATH=../drupal
make evr -- index /mnt/project --tag=10.3.0 EXTRA_HOST_PATH=../drupal
make ev -- diff --from=10.2.0 --to=10.3.0
```

Only the `evolver` service is needed for this full flow.

## Local Web UI

The compose service publishes the web UI at `127.0.0.1:8080`.

```bash
make up
make web
make worker
```

Then open `http://localhost:8080`.

## Troubleshooting

### FFI missing (`Class "FFI" not found`)

Rebuild the single image and rerun:

```bash
docker compose build evolver
make ev -- status
```

Check FFI module:

```bash
make e -- php --ri FFI
```

### memprof missing

If profiling reports that memprof is unavailable, rebuild the image and verify module loading:

```bash
make build
make up
make e -- php --ri memprof
```

### Grammar not found

Check installed libs:

```bash
make e -- ls -la /usr/lib/libtree-sitter.so* /usr/lib/libtree-sitter-php.so /usr/lib/libtree-sitter-yaml.so
```

### Permission issues on generated files

Ensure `.env` contains matching UID/GID and rebuild:

```bash
echo "LOCAL_UID=$(id -u)" > .env
echo "LOCAL_GID=$(id -g)" >> .env
docker compose build --no-cache evolver
```
