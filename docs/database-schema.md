# Database Schema

Evolver uses SQLite 3 with WAL journaling mode and foreign keys enabled.

## Entity Relationship

```
versions ──1:N──> parsed_files ──1:N──> symbols
    │                                      ▲
    │                                      │
    ├── from_version_id ─── changes ───────┤ (old_symbol_id)
    └── to_version_id  ─── changes ───────┘ (new_symbol_id)
                               │
projects ──1:N──> code_matches ┘ (change_id)
```

## Tables

### versions

Stores indexed Drupal core versions.

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| tag | TEXT UNIQUE | Version tag (e.g. "10.3.0") |
| major | INTEGER | Major version number |
| minor | INTEGER | Minor version number |
| patch | INTEGER | Patch version number |
| weight | INTEGER | Precomputed sortable version weight (`major * 1000000 + minor * 1000 + patch`) |
| file_count | INTEGER | Number of parsed files |
| symbol_count | INTEGER | Number of extracted symbols |
| indexed_at | TEXT | ISO 8601 timestamp |

### parsed_files

Stores parsed file metadata and compressed ASTs.

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| version_id | INTEGER FK | → versions(id) CASCADE |
| file_path | TEXT | Relative path from Drupal root |
| language | TEXT | "php" or "yaml" |
| file_hash | TEXT | SHA-256 of file content (skip re-parse) |
| ast_sexp | BLOB | zlib-compressed S-expression |
| ast_json | TEXT | JSON tree (optional, for queries) |
| line_count | INTEGER | Lines in file |
| byte_size | INTEGER | File size in bytes |
| parsed_at | TEXT | ISO 8601 timestamp |

**Unique constraint:** `(version_id, file_path)`

Re-indexing the same version updates this row in place and replaces symbols for that file.

### symbols

Stores extracted symbols from parsed files.

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| version_id | INTEGER FK | → versions(id) CASCADE |
| file_id | INTEGER FK | → parsed_files(id) CASCADE |
| language | TEXT | "php" or "yaml" |
| symbol_type | TEXT | See types below |
| fqn | TEXT | Fully qualified name |
| name | TEXT | Short name |
| namespace | TEXT | PHP namespace (nullable) |
| parent_symbol | TEXT | Class name for methods (nullable) |
| visibility | TEXT | public/protected/private (nullable) |
| is_static | INTEGER | 0 or 1 |
| signature_hash | TEXT | SHA-256 of type+name+params+return |
| signature_json | TEXT | JSON: {params:[], return_type} |
| ast_node_sexp | TEXT | S-expression of symbol's AST node |
| ast_node_json | TEXT | JSON of symbol's AST subtree |
| source_text | TEXT | Raw source code of the symbol |
| line_start | INTEGER | Start line (1-based) |
| line_end | INTEGER | End line (1-based) |
| byte_start | INTEGER | Start byte offset |
| byte_end | INTEGER | End byte offset |
| docblock | TEXT | PHPDoc comment text |
| is_deprecated | INTEGER | 0 or 1 |
| deprecation_message | TEXT | From @trigger_error or @deprecated |
| deprecation_version | TEXT | "10.3.0" (deprecated in) |
| removal_version | TEXT | "11.0.0" (removed from) |
| metadata_json | TEXT | Extra language-specific data |

**Symbol types (PHP):** `function`, `class`, `method`, `interface`, `trait`, `constant`

**Symbol types (YAML):** `service`, `route`, `permission`

**Indexes:**
- `idx_sym_fqn` — on fqn
- `idx_sym_name` — on name
- `idx_sym_type` — on symbol_type
- `idx_sym_version` — on version_id
- `idx_sym_hash` — on signature_hash
- `idx_sym_deprecated` — partial, WHERE is_deprecated = 1
- `idx_sym_file` — on file_id
- `idx_sym_parent` — partial, WHERE parent_symbol IS NOT NULL

### changes

Stores detected changes between two versions.

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| from_version_id | INTEGER FK | → versions(id) |
| to_version_id | INTEGER FK | → versions(id) |
| language | TEXT | Source language for generated query matching |
| change_type | TEXT | See types below |
| severity | TEXT | info / deprecation / breaking / removal |
| old_symbol_id | INTEGER FK | → symbols(id), nullable |
| new_symbol_id | INTEGER FK | → symbols(id), nullable |
| old_fqn | TEXT | FQN before change |
| new_fqn | TEXT | FQN after change (renames) |
| diff_json | TEXT | Structured diff details |
| ts_query | TEXT | Tree-sitter query to find affected code |
| query_version | INTEGER | Query generator version used to produce `ts_query` |
| fix_template | TEXT | JSON fix template (nullable) |
| migration_hint | TEXT | Human-readable migration guidance |
| confidence | REAL | 0.0–1.0, default 1.0 |
| created_at | TEXT | ISO 8601 timestamp |

**Change types (PHP):** `function_removed`, `class_removed`, `method_removed`, `interface_removed`, `trait_removed`, `constant_removed`, `signature_changed`, `deprecated_added`

**Change types (YAML):** `service_removed`, `route_removed`, `permission_removed`

**Indexes:**
- `idx_chg_versions` — on (from_version_id, to_version_id)
- `idx_chg_type` — on change_type
- `idx_chg_old_fqn` — on old_fqn
- `idx_chg_severity` — on severity

### ast_snapshots

Stores rich AST context for deep inspection or future LLM integration.

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| symbol_id | INTEGER FK | → symbols(id) CASCADE |
| format | TEXT | "sexp", "json", "source", "source_context" |
| content | BLOB | zlib-compressed content |
| context_lines | INTEGER | Lines of surrounding context included |
| byte_range | TEXT | "start:end" |

### projects

Stores scanned target projects.

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| name | TEXT | Project name |
| path | TEXT | Filesystem path |
| type | TEXT | "site", "module", "theme", "profile" |
| core_version | TEXT | Detected from composer.lock |
| last_scanned | TEXT | ISO 8601 timestamp |

**Unique index:** `path`

### project_extensions

Stores the custom extensions discovered inside a scanned project so upgrade plans and
project-level dependency graphs can work against the target app, not just indexed core versions.

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| project_id | INTEGER FK | → projects(id) CASCADE |
| machine_name | TEXT | Extension machine name |
| extension_type | TEXT | "module", "theme", "profile", "recipe" |
| label | TEXT | Human-readable label from metadata |
| dependencies | TEXT | JSON list of declared internal/external dependencies |
| file_path | TEXT | Relative path to the extension manifest |

**Unique constraint:** `(project_id, machine_name)`

### code_matches

Stores matches found when scanning target projects.

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| project_id | INTEGER FK | → projects(id) CASCADE |
| change_id | INTEGER FK | → changes(id) |
| file_path | TEXT | Relative path in project |
| line_start | INTEGER | Start line of match |
| line_end | INTEGER | End line of match |
| byte_start | INTEGER | Start byte offset, normalized to `-1` when unavailable |
| byte_end | INTEGER | End byte offset, normalized to `-1` when unavailable |
| matched_source | TEXT | The code that matched |
| suggested_fix | TEXT | Generated replacement |
| fix_method | TEXT | "template", "manual" |
| status | TEXT | "pending", "applied", "reviewed", "skipped", "failed" |
| applied_at | TEXT | ISO 8601 timestamp |

**Unique constraint:** `(project_id, change_id, file_path, byte_start, byte_end)`

**Indexes:**
- `idx_match_project` — on project_id
- `idx_match_status` — on status
- `idx_match_change` — on change_id
- `idx_project_path` — unique, on projects(path)

### schema_meta

Stores metadata about the database itself.

| Column | Type | Description |
|--------|------|-------------|
| key | TEXT PK | Metadata key |
| value | TEXT | Metadata value |

Currently stores: `schema_version` = `"7"`

Schema version 7 includes all version 6 migrations and adds `changes.query_version` to support stale query detection during scan.

## Upgrade Path Query

The scanner resolves multi-hop upgrades by comparing version weights rather than string tags:

```sql
SELECT DISTINCT c.*
FROM changes c
JOIN versions v_from ON v_from.id = c.from_version_id
JOIN versions v_to ON v_to.id = c.to_version_id
JOIN versions v_start ON v_start.id = :start_id
JOIN versions v_end ON v_end.id = :end_id
WHERE v_from.weight >= v_start.weight
  AND v_to.weight <= v_end.weight
  AND v_from.weight < v_to.weight
ORDER BY v_from.weight, v_to.weight, c.id
```

## Key Queries

### Hash-based diff (find removed symbols)
```sql
SELECT * FROM symbols WHERE version_id = :old
  AND signature_hash NOT IN (SELECT signature_hash FROM symbols WHERE version_id = :new)
```

### Signature change detection
```sql
SELECT o.*, n.*
FROM symbols o
JOIN symbols n ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
WHERE o.version_id = :old AND n.version_id = :new
  AND o.signature_hash != n.signature_hash
```

### Deprecation lifecycle
```sql
-- Newly deprecated
SELECT n.* FROM symbols n
JOIN symbols o ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
WHERE n.version_id = :new AND o.version_id = :old
  AND n.is_deprecated = 1 AND o.is_deprecated = 0

-- Deprecated then removed
SELECT o.* FROM symbols o
WHERE o.version_id = :old AND o.is_deprecated = 1
  AND o.fqn NOT IN (SELECT fqn FROM symbols WHERE version_id = :new AND symbol_type = o.symbol_type)
```
