# Tree-Sitter CLI Investigation - 2025-03-05

## Issue Discovered

Alpine Edge's `tree-sitter-cli` package **does not properly support loading external grammar .so files**.

## What Works

1. **tree-sitter-cli package is installed** at `/usr/bin/tree-sitter` (version 0.25.10)
2. **Grammar .so files exist** at `/usr/lib/tree-sitter/php.so` and `/usr/lib/tree-sitter/yaml.so`
3. **Config files can be created** in `/app/tree-sitter-config.json`
4. **Alpine's tree-sitter-cli** seems to be built without proper external grammar support

## Test Results

```bash
$ tree-sitter parse test.php --config-path /app/tree-sitter-config.json
Failed to load language for path "/tmp/test.php"
Caused by: No language found
```

Even with config specifying:
```json
{
  "parser-directories": ["/usr/lib/tree-sitter"],
  "scanners": {
    "*.php": {"parser": "/usr/lib/tree-sitter/php.so"}
  }
}
```

## Root Cause

Alpine's `tree-sitter-cli` package likely:
- Only supports built-in parsers (JavaScript, etc.)
- Or is compiled without dynamic library loading support
- Or expects a different configuration format

## Solutions to Try

### Option 1: Compile tree-sitter CLI from source
Tree-sitter is written in Rust. The CLI can be built with `cargo install --path /usr/local`.
**Issue:** We need the correct repository - `tree-sitter` is the library, not the CLI. The CLI is part of the main repo.

### Option 2: Use pre-compiled binaries
Download official tree-sitter Linux binaries which have proper grammar support.

### Option 3: Use different base image
Ubuntu/Debian packages may have better support.

### Option 4: Work around with FFI (original approach)
The FFI type incompatibility issue (TSLanguage* from different FFI instances) can be solved by:
- Using `FFI::scope()` to share the scope between FFI instances
- Defining both library functions in the same cdef
- Using a single FFI instance with dlopen/dlsym

## Working Code (PHP FFI)

We successfully tested that:
- `ts_parser_new()` creates parsers
- `ts_parser_delete()` deletes parsers
- `ts_parser_set_language()` works when passed a compatible `TSLanguage*`
- `tree_sitter_php()` function can be called via FFI

The blocker is bridging the language pointer from one FFI instance to another.

## Files Created

- `Makefile` - with `up`, `down`, `r`, `e`, `dev` commands
- `docker-compose.yml` - with `evolver` and `dev` services, `../drupal` mount
- `Dockerfile` - Alpine Edge with PHP 8.5, user/group configuration
- `docs/docker.md` - comprehensive Docker documentation
- `tree-sitter-config.json` - config template
- `test.php` - test file
- `src/TreeSitter/TreeSitterCLI.php` - CLI wrapper (not working yet due to Alpine limitation)

## Next Steps

1. **Fix tree-sitter CLI grammar loading** OR **fix FFI type incompatibility**
2. **Implement IndexCommand** with CLI-based parser
3. **Test indexing** on `../drupal/core/modules`
4. **Complete remaining tasks** (diff, scan, apply)

## Current Status

- Docker environment is working (user/group, mounts, Makefile commands)
- Composer dependencies installed
- All 47 tests pass (unit tests, no FFI integration tests yet)
- Ready to implement tree-sitter parsing solution
