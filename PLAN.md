# DrupalEvolver MVP Plan

## Concept

PHP CLI tool (Symfony Console) that uses tree-sitter via FFI to parse Drupal core across version tags, extract symbols and deprecation patterns into SQLite, diff versions to detect breaking changes, and apply transformation patterns to target codebases.

**MVP languages:** PHP (tree-sitter-php), YAML (tree-sitter-yaml)
**Runtime:** PHP 8.3+, ext-ffi, ext-pdo_sqlite, Symfony Console 7.x
**Storage:** SQLite 3 with JSON1 extension

---

## Architecture Overview

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

---

## MVP Milestones

### M0: Project Skeleton + FFI Binding
**Goal:** Composer project boots, tree-sitter parses a PHP file, result printed.

**Deliverables:**
- `composer.json` with symfony/console, ext-ffi, ext-pdo_sqlite
- `bin/evolver` entry point
- `src/TreeSitter/FFIBinding.php` — raw C function bindings
- `src/TreeSitter/Parser.php` — high-level: parse(string $source, string $lang): Tree
- `src/TreeSitter/Node.php` — OOP wrapper: type(), text(), children(), namedChildren(), sexp()
- `src/TreeSitter/Query.php` — run S-expression queries, iterate matches
- `src/TreeSitter/LanguageRegistry.php` — load grammar .so files by name
- `Makefile` target to compile tree-sitter-php and tree-sitter-yaml grammars
- Smoke test: parse a .php file, print s-expression

**Key decisions:**
- TSNode is a struct (not pointer) — 24 bytes, passed by value in C. FFI needs careful handling here. Use `FFI::type()` to define the struct layout, or work through `ts_node_string()` and `ts_tree_cursor_*` APIs which use pointers.
- Grammar .so files go in `grammars/` dir, path configurable via env/config
- tree-sitter C library (`libtree-sitter.so`) — either system-installed or bundled

**Risk:** TSNode pass-by-value in FFI. PHP FFI handles structs but it's fiddly. Fallback: use tree cursor API (pointer-based) exclusively.

---

### M1: SQLite Schema + File Indexing
**Goal:** Parse all PHP/YAML files from a Drupal core checkout, store parsed data in SQLite.

**Deliverables:**
- `src/Storage/Database.php` — PDO wrapper, WAL mode, pragmas
- `src/Storage/Schema.php` — CREATE TABLE statements, version migrations
- `src/Storage/Repository/*.php` — VersionRepo, FileRepo, SymbolRepo
- `src/Indexer/CoreIndexer.php` — given a drupal core path + tag, index all files
- `src/Indexer/FileClassifier.php` — map extensions to grammars (.php,.module,.inc,.install → php; .yml → yaml)
- `evolver index <path> --tag=10.3.0` command

**SQLite schema (MVP subset):**

```sql
PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

CREATE TABLE versions (
    id          INTEGER PRIMARY KEY,
    tag         TEXT NOT NULL UNIQUE,
    major       INTEGER NOT NULL,
    minor       INTEGER NOT NULL,
    patch       INTEGER NOT NULL,
    file_count  INTEGER DEFAULT 0,
    symbol_count INTEGER DEFAULT 0,
    indexed_at  TEXT
);

CREATE TABLE parsed_files (
    id          INTEGER PRIMARY KEY,
    version_id  INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
    file_path   TEXT NOT NULL,
    language    TEXT NOT NULL,           -- 'php', 'yaml'
    file_hash   TEXT NOT NULL,          -- sha256, skip re-parse on unchanged
    ast_sexp    BLOB,                   -- zlib-compressed s-expression
    ast_json    TEXT,                   -- JSON tree for queryable storage
    line_count  INTEGER,
    byte_size   INTEGER,
    parsed_at   TEXT DEFAULT (datetime('now')),
    UNIQUE(version_id, file_path)
);

CREATE TABLE symbols (
    id                  INTEGER PRIMARY KEY,
    version_id          INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
    file_id             INTEGER NOT NULL REFERENCES parsed_files(id) ON DELETE CASCADE,
    language            TEXT NOT NULL,
    symbol_type         TEXT NOT NULL,
    -- Types for PHP: function, class, method, interface, trait,
    --   constant, hook_implementation, service_definition,
    --   event_subscriber, plugin, form, controller, entity
    -- Types for YAML: service, parameter, route, permission,
    --   config_schema, menu_link, plugin_definition, library
    fqn                 TEXT NOT NULL,       -- fully qualified name
    name                TEXT NOT NULL,       -- short name
    namespace           TEXT,
    parent_symbol       TEXT,               -- class name for methods
    visibility          TEXT,               -- public/protected/private (methods)
    is_static           INTEGER DEFAULT 0,
    signature_hash      TEXT,               -- hash of (type+name+params+return)
    signature_json      TEXT,               -- {params:[], return_type, ...}
    ast_node_sexp       TEXT,               -- s-expression of this symbol's AST node
    ast_node_json       TEXT,               -- JSON of this symbol's AST subtree
    source_text         TEXT,               -- raw source code of the symbol
    line_start          INTEGER,
    line_end            INTEGER,
    byte_start          INTEGER,
    byte_end            INTEGER,
    docblock            TEXT,
    is_deprecated       INTEGER DEFAULT 0,
    deprecation_message TEXT,
    deprecation_version TEXT,               -- "deprecated in drupal:X.Y.Z"
    removal_version     TEXT,               -- "removed from drupal:X.Y.Z"
    metadata_json       TEXT                -- extra language-specific data
);

CREATE INDEX idx_sym_fqn         ON symbols(fqn);
CREATE INDEX idx_sym_name        ON symbols(name);
CREATE INDEX idx_sym_type        ON symbols(symbol_type);
CREATE INDEX idx_sym_version     ON symbols(version_id);
CREATE INDEX idx_sym_hash        ON symbols(signature_hash);
CREATE INDEX idx_sym_deprecated  ON symbols(is_deprecated) WHERE is_deprecated = 1;
CREATE INDEX idx_sym_file        ON symbols(file_id);
CREATE INDEX idx_sym_parent      ON symbols(parent_symbol) WHERE parent_symbol IS NOT NULL;

CREATE TABLE changes (
    id                  INTEGER PRIMARY KEY,
    from_version_id     INTEGER NOT NULL REFERENCES versions(id),
    to_version_id       INTEGER NOT NULL REFERENCES versions(id),
    change_type         TEXT NOT NULL,
    -- PHP: function_removed, function_renamed, signature_changed,
    --   class_removed, class_renamed, method_removed, method_added,
    --   parameter_added, parameter_removed, parameter_type_changed,
    --   return_type_changed, visibility_changed, hook_removed,
    --   hook_to_attribute, hook_to_event, deprecated_added
    -- YAML: service_removed, service_renamed, service_class_changed,
    --   route_removed, route_changed, permission_removed,
    --   config_key_removed, config_key_renamed, library_changed
    severity            TEXT DEFAULT 'deprecation',
    -- 'info', 'deprecation', 'breaking', 'removal'
    old_symbol_id       INTEGER REFERENCES symbols(id),
    new_symbol_id       INTEGER REFERENCES symbols(id),
    old_fqn             TEXT,
    new_fqn             TEXT,
    diff_json           TEXT,               -- structured diff details
    ts_query            TEXT,               -- tree-sitter query to find affected code
    fix_template        TEXT,               -- mechanical fix template (if possible)
    migration_hint      TEXT,
    confidence          REAL DEFAULT 1.0,
    created_at          TEXT DEFAULT (datetime('now'))
);

CREATE INDEX idx_chg_versions ON changes(from_version_id, to_version_id);
CREATE INDEX idx_chg_type     ON changes(change_type);
CREATE INDEX idx_chg_old_fqn  ON changes(old_fqn);
CREATE INDEX idx_chg_severity ON changes(severity);

-- For storing rich AST context (LLM calls later, deep inspection)
CREATE TABLE ast_snapshots (
    id              INTEGER PRIMARY KEY,
    symbol_id       INTEGER NOT NULL REFERENCES symbols(id) ON DELETE CASCADE,
    format          TEXT NOT NULL,       -- 'sexp', 'json', 'source', 'source_context'
    content         BLOB NOT NULL,       -- zlib-compressed
    context_lines   INTEGER DEFAULT 0,  -- lines of surrounding context included
    byte_range      TEXT                -- "start:end"
);

-- Scanned target projects (M3)
CREATE TABLE projects (
    id              INTEGER PRIMARY KEY,
    name            TEXT NOT NULL,
    path            TEXT NOT NULL,
    type            TEXT,               -- 'site', 'module', 'theme', 'profile'
    core_version    TEXT,               -- detected from composer.lock
    last_scanned    TEXT
);

-- Matches found in target code (M3)
CREATE TABLE code_matches (
    id              INTEGER PRIMARY KEY,
    project_id      INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    change_id       INTEGER NOT NULL REFERENCES changes(id),
    file_path       TEXT NOT NULL,
    line_start      INTEGER,
    line_end        INTEGER,
    matched_source  TEXT,               -- the code that matched
    suggested_fix   TEXT,               -- generated replacement
    fix_method      TEXT,               -- 'template', 'rector', 'llm', 'manual'
    status          TEXT DEFAULT 'pending',
    -- 'pending', 'applied', 'reviewed', 'skipped', 'failed'
    applied_at      TEXT
);

CREATE INDEX idx_match_project ON code_matches(project_id);
CREATE INDEX idx_match_status  ON code_matches(status);
CREATE INDEX idx_match_change  ON code_matches(change_id);
```

**Indexing flow:**

```
evolver index /path/to/drupal --tag=10.3.0
  │
  ├─ git checkout 10.3.0
  ├─ Walk directory, classify files by extension
  ├─ For each file:
  │   ├─ sha256 hash → skip if already indexed for this version
  │   ├─ tree-sitter parse → AST
  │   ├─ Store compressed sexp + JSON in parsed_files
  │   ├─ Extract symbols → store in symbols table
  │   │   ├─ PHP: functions, classes, methods, interfaces, traits
  │   │   ├─ PHP: @trigger_error calls → deprecation records
  │   │   ├─ PHP: @deprecated docblocks → deprecation records
  │   │   ├─ YAML: service definitions, routes, permissions
  │   │   └─ Compute signature_hash per symbol
  │   └─ Optionally store ast_snapshots for key symbols
  └─ Update version record with counts
```

**Performance target:** Index one Drupal core version (4000+ files) in < 30 seconds.

---

### M2: Version Diffing + Change Detection
**Goal:** Given two indexed versions, detect all changes and store as patterns.

**Deliverables:**
- `src/Differ/VersionDiffer.php` — orchestrates all diff strategies
- `src/Differ/SymbolDiffer.php` — hash-based removed/added, signature comparison
- `src/Differ/RenameMatcher.php` — fuzzy match removed→added (same body, similar name)
- `src/Differ/SignatureDiffer.php` — param-level diff (added/removed/retyped/reordered)
- `src/Differ/YAMLDiffer.php` — service/route/config key diffs
- `src/Differ/DeprecationTracker.php` — newly deprecated, newly removed
- `src/Pattern/QueryGenerator.php` — change → tree-sitter query pattern
- `evolver diff --from=10.2.0 --to=10.3.0` command

**Diff strategies (priority order):**

1. **Hash diff** (fast, covers 80%)
   ```sql
   -- Removed: hash exists in old, not in new
   SELECT * FROM symbols WHERE version_id = :old
     AND signature_hash NOT IN (SELECT signature_hash FROM symbols WHERE version_id = :new)

   -- Added: hash exists in new, not in old  
   SELECT * FROM symbols WHERE version_id = :new
     AND signature_hash NOT IN (SELECT signature_hash FROM symbols WHERE version_id = :old)
   ```

2. **FQN match with signature change** (same name, different hash)
   ```sql
   SELECT o.*, n.*
   FROM symbols o
   JOIN symbols n ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
   WHERE o.version_id = :old AND n.version_id = :new
     AND o.signature_hash != n.signature_hash
   ```
   Then run `SignatureDiffer` on each pair to get param-level diff.

3. **Rename detection** (removed symbol A, added symbol B with similar body/structure)
   - Same file path, similar line position
   - Similar AST structure (compare ast_node_json subtrees)
   - Namespace move: same short name, different namespace
   - Levenshtein on FQN below threshold

4. **Deprecation lifecycle tracking**
   - `is_deprecated=0` in old → `is_deprecated=1` in new = newly deprecated
   - `is_deprecated=1` in old → symbol gone in new = deprecated-then-removed
   - Parse `deprecation_version` and `removal_version` from @trigger_error messages

5. **YAML key diff**
   - Compare service names, class values, argument lists
   - Route paths, controller references
   - Config schema keys

**Query pattern generation:**

Each detected change gets a `ts_query` field — a tree-sitter S-expression query that will find affected code in target projects:

```
Change: function_removed "drupal_render"
ts_query: (function_call_expression function: (name) @fn (#eq? @fn "drupal_render"))

Change: class_renamed "Drupal\Core\Foo" → "Drupal\Core\Bar"  
ts_query: (qualified_name (namespace_name_as_prefix) (name) @cls (#eq? @cls "Foo"))
// + namespace use statement matching

Change: service_renamed "old.service" → "new.service"
ts_query for PHP: (string (string_content) @svc (#eq? @svc "old.service"))
ts_query for YAML: (flow_node (plain_scalar) @svc (#eq? @svc "old.service"))

Change: parameter_added to function "foo_bar" at position 2
ts_query: (function_call_expression function: (name) @fn (#eq? @fn "foo_bar") arguments: (arguments) @args)
// + arg count check in post-processing
```

---

### M3: Project Scanner
**Goal:** Scan a Drupal module/theme/site against stored changes, report all hits.

**Deliverables:**
- `src/Scanner/ProjectScanner.php` — walk project files, parse, run queries
- `src/Scanner/VersionDetector.php` — read composer.lock to detect current core version
- `src/Scanner/MatchCollector.php` — run ts_queries from changes against parsed files
- `evolver scan /path/to/project --target=11.0.0` command
- `evolver report --project=my_module --format=table|json` command

**Scan flow:**

```
evolver scan /path/to/mymodule --target=11.0.0
  │
  ├─ Detect current core version from composer.lock (or --from flag)
  ├─ Load all changes between current → target from DB
  ├─ Group changes by language (PHP changes, YAML changes)
  ├─ Walk project files:
  │   ├─ Classify by language
  │   ├─ Parse with tree-sitter
  │   └─ For each relevant change:
  │       ├─ Run ts_query against parsed tree
  │       ├─ For each match:
  │       │   ├─ Extract matched source text + location
  │       │   ├─ Generate suggested fix (from fix_template if available)
  │       │   └─ Store in code_matches
  │       └─ Post-process: verify match (e.g. arg count for signature changes)
  └─ Print summary report

Report output (table format):
┌──────────────────────┬────────────┬──────────────┬──────────┬──────────┐
│ File                 │ Line       │ Change       │ Severity │ Fix      │
├──────────────────────┼────────────┼──────────────┼──────────┼──────────┤
│ src/MyService.php    │ 45         │ func removed │ breaking │ template │
│ src/MyService.php    │ 112        │ sig changed  │ breaking │ template │
│ mymodule.services.yml│ 12         │ svc renamed  │ breaking │ template │
│ src/Form/MyForm.php  │ 78         │ deprecated   │ warning  │ manual   │
└──────────────────────┴────────────┴──────────────┴──────────┴──────────┘
Summary: 3 breaking, 1 deprecation, 2 auto-fixable
```

---

### M4: Pattern Applier (Template-Based Fixes)
**Goal:** Apply mechanical fixes from stored patterns to scanned code.

**Deliverables:**
- `src/Applier/TemplateApplier.php` — apply fix_template to matched code
- `src/Applier/FixTemplate.php` — template engine for code transformations
- `src/Applier/DiffGenerator.php` — generate unified diff for review
- `evolver apply --project=my_module [--dry-run] [--interactive]` command

**Fix template format:**

Templates stored in `changes.fix_template` as JSON:

```json
{
  "type": "function_rename",
  "old": "drupal_render",
  "new": "\\Drupal::service('renderer')->render",
  "arg_map": [0]
}
```

```json
{
  "type": "parameter_insert",
  "function": "some_function",
  "position": 2,
  "value": "NULL",
  "comment": "// New param added in 10.3.0"
}
```

```json
{
  "type": "string_replace",
  "context": "service_reference",
  "old": "old.service.name",
  "new": "new.service.name"
}
```

```json
{
  "type": "namespace_move",
  "old_namespace": "Drupal\\Core\\Old",
  "new_namespace": "Drupal\\Core\\New",
  "class": "SomeClass"
}
```

**Apply modes:**
- `--dry-run`: Show diffs only, write nothing
- `--interactive`: Show each diff, ask y/n/skip
- Default: Apply all template-based fixes, skip anything needing manual/LLM

**Important:** MVP does NOT modify AST and re-print. It works at the source text level — uses byte offsets from tree-sitter matches to do surgical string replacements. This avoids the entire AST→source round-trip problem.

```php
// Pseudocode for applying a fix
$source = file_get_contents($match->file_path);
$before = substr($source, 0, $match->byte_start);
$after  = substr($source, $match->byte_end);
$fixed  = $before . $template->apply($match->matched_source) . $after;
// Apply bottom-up (highest byte offset first) to preserve offsets
```

---

## File-to-Grammar Mapping (MVP)

```yaml
php:
  extensions: [.php, .module, .inc, .install, .profile, .theme, .engine]
  grammar: tree-sitter-php
  extractors: [PHPFunctionExtractor, PHPClassExtractor, PHPDeprecationExtractor]

yaml:
  extensions: [.yml, .yaml]
  grammar: tree-sitter-yaml
  extractors: [YAMLServiceExtractor, YAMLRouteExtractor, YAMLConfigExtractor]
```

---

## PHP Extraction Targets (MVP)

### From tree-sitter-php AST:

| Node Type | Extracted As | Key Fields |
|---|---|---|
| `function_definition` | function | name, params, return_type, body hash |
| `class_declaration` | class | name, parent, interfaces, traits |
| `method_declaration` | method | name, visibility, static, params, return |
| `interface_declaration` | interface | name, methods |
| `trait_declaration` | trait | name, methods |
| `function_call_expression` where fn=`trigger_error` | deprecation | message text, level |
| `attribute` (PHP 8) | attribute | name, arguments |
| `const_declaration` | constant | name, value |

### From @trigger_error message parsing (regex on extracted text):

```
@trigger_error('{symbol} is deprecated in drupal:{version} and is removed
from drupal:{version}. Use {replacement} instead. See {url}', E_USER_DEPRECATED);
```

Extract: symbol, deprecated_in, removed_in, replacement_hint, change_record_url

### From docblock @deprecated tags:

```
@deprecated in drupal:10.3.0 and is removed from drupal:11.0.0.
  Use \Drupal\Core\Something instead.
```

---

## YAML Extraction Targets (MVP)

### services.yml
- Service name (top-level key)
- Class value
- Arguments list
- Tags (name, priority)
- Decorates, parent, factory

### *.routing.yml
- Route name
- Path
- Controller/form reference
- Requirements, options

### *.permissions.yml
- Permission machine name
- Title, description

---

## CLI Commands Summary (MVP)

```
evolver index <drupal-path> --tag=<version>
    Index a single Drupal core version.
    Parses all PHP/YAML files, extracts symbols, stores in DB.

evolver diff --from=<version> --to=<version>
    Compare two indexed versions.
    Detects removals, renames, signature changes, new deprecations.
    Generates ts_query patterns and fix templates.

evolver scan <project-path> --target=<version> [--from=<version>]
    Scan a module/theme/site against stored changes.
    Runs ts_queries, collects matches, generates report.

evolver apply --project=<name> [--dry-run] [--interactive]
    Apply template-based fixes to scanned matches.
    Shows diffs, optionally applies changes.

evolver report --project=<name> [--format=table|json|html]
    Show scan results and upgrade readiness.

evolver status
    DB stats: indexed versions, total symbols, changes, scan results.

evolver query <ts-query> <file>
    Debug tool: run a raw tree-sitter query against a file.
    Useful for testing patterns.
```

---

## Development Order

```
Week 1-2: M0 — FFI binding works, parse PHP + YAML files
    Day 1-3: Compile grammars, FFI cdef, basic parse
    Day 4-5: Node wrapper, tree walking
    Day 6-8: Query API, test against Drupal files
    Day 9-10: Symfony Console skeleton, smoke test command

Week 3-4: M1 — Indexing into SQLite
    Day 1-2: Schema, migrations, PDO wrapper
    Day 3-5: PHP extractor (functions, classes, deprecations)
    Day 6-7: YAML extractor (services, routes)
    Day 8-10: CoreIndexer, index command, progress bars

Week 5-6: M2 — Version diffing
    Day 1-3: Hash diff, FQN diff, signature differ
    Day 4-5: Rename detection, deprecation lifecycle
    Day 6-7: YAML differ
    Day 8-10: Query pattern generation, diff command

Week 7-8: M3+M4 — Scanner + Applier
    Day 1-3: Project scanner, version detection
    Day 4-5: Match collection, reporting
    Day 6-8: Template applier, dry-run, interactive mode
    Day 9-10: Integration test: index 10.2 + 10.3, scan a real module, apply fixes
```

---

## Testing Strategy

- **Fixtures:** Small .php/.yml files with known symbols, known deprecations
- **Golden tests:** Index fixture, diff fixture, compare output against expected JSON
- **Real-world smoke:** Index actual Drupal 10.2.0 + 10.3.0, diff, verify known deprecations appear
- **Round-trip:** Scan + apply on a test module, verify code is valid PHP after fix

---

## Future (Post-MVP)

- **tree-sitter-javascript** for JS behavior changes
- **tree-sitter-css** for CSS custom property / class removals
- **tree-sitter-twig** for template function/filter deprecations
- **LLM integration** for complex refactors (hook→event subscriber, plugin migrations)
- **Rector rule generation** from change patterns
- **Web dashboard** for visual reports
- **drupal.org change record API** integration for enrichment
- **Contrib module tracking** — index contrib releases, track transitive deprecations
- **CI integration** — `evolver scan` as a CI step with exit codes