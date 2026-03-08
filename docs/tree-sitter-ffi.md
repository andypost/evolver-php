# Tree-sitter FFI Integration

## Overview

Evolver uses PHP FFI to call tree-sitter's C API directly.
This is the default parser mode (`EVOLVER_USE_CLI=0`).

## FFI Runtime Preparation

The parser stack is prepared automatically in Docker:

1. PHP 8.5 runtime includes `ext-ffi`.
2. Core tree-sitter library is installed at `/usr/lib/libtree-sitter.so*` (versioned on Alpine).
3. Grammar libraries are installed at:
   - `/usr/lib/libtree-sitter-php.so`
   - `/usr/lib/libtree-sitter-yaml.so`
   - `/usr/lib/libtree-sitter-twig.so`
4. `EVOLVER_GRAMMAR_PATH` defaults to `/usr/lib` in `compose.yaml`.
5. Runtime library resolution falls back safely across common names/paths.

No separate grammar compilation step is required in Docker.

## Library Resolution Rules

`Parser` resolves core library in this order:
1. `$EVOLVER_GRAMMAR_PATH/libtree-sitter.so`
2. `$EVOLVER_GRAMMAR_PATH/libtree-sitter.so.0`
3. `/usr/lib/libtree-sitter.so` / `/usr/lib/libtree-sitter.so.0`
4. Any versioned `libtree-sitter.so.*` candidate in those paths

`LanguageRegistry` resolves language grammar in this order:
1. `$EVOLVER_GRAMMAR_PATH/tree-sitter-<lang>.so`
2. `$EVOLVER_GRAMMAR_PATH/libtree-sitter-<lang>.so`
3. `/usr/lib/libtree-sitter-<lang>.so`
4. `/usr/lib/tree-sitter/<lang>.so`

This avoids failures when `/app` is bind-mounted from host.

## FFI Type Compatibility and Casting

`TSLanguage*` values from grammar FFI handles are cast to the core FFI handle before parser use.
Without this cast, type mismatch/segfault behavior is possible across FFI scopes.

```php
$lang = $this->registry->loadLanguage($language);
$lang = $this->ffi->ffi()->cast('const TSLanguage *', $lang);
$this->ffi->ts_parser_set_language($parser, $lang);
```

## Main Classes

- `FFIBinding`: raw `FFI::cdef()` bindings for tree-sitter C API.
- `LanguageRegistry`: loads per-language grammar shared libs.
- `Parser`: high-level parse API (`parse($source, $language)`).
- `Tree` and `Node`: wrappers around C tree/node structures.
- `Query`: query cursor/match handling for S-expression queries.

## TSNode Notes

`TSNode` is a by-value struct in tree-sitter C API. Code paths copy/cast it carefully to keep references valid across iterations and FFI calls.

## Quick Verification

```bash
make e -- php --ri FFI
make e -- ls -la /usr/lib/libtree-sitter.so* /usr/lib/libtree-sitter-php.so /usr/lib/libtree-sitter-yaml.so
make r
```
