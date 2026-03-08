# TODO - Evolver Roadmap

## Product Direction

Evolver stays `Evolver`.

Primary goal:
- make Evolver genuinely useful for analyzing custom Drupal code during upgrades

Secondary goal:
- generalize the engine later so the same workflow can support Symfony and selected JS/CSS upgrade tracking

Principle:
- Drupal remains the first-class adapter, not a throwaway MVP
- extensibility work should follow proven value from Drupal custom module analysis

## Phase 1 - Drupal Custom Modules First

Top priority: custom modules, custom themes, custom profiles, and site-level app code.

### 1.1 Project typing ✅ DONE
- [x] Detect and store project types:
  - `drupal-module`
  - `drupal-theme`
  - `drupal-profile`
  - `drupal-site`
  - `drupal-core`
  - `symfony`
  - `generic`
- [x] Detect project metadata from `composer.json`, `*.info.yml`, and directory shape
- [x] Persist `project_type`, package name, and root module/theme name in project records
- [x] `ProjectTypeDetector` fully implemented with metadata extraction
- [x] Database schema has `package_name` and `root_name` columns

### 1.2 Better Drupal-specific app analysis ✅ DONE
- [x] Services extraction (YAMLExtractor)
- [x] Routes extraction (YAMLExtractor)
- [x] Permissions extraction (YAMLExtractor)
- [x] Plugins extraction (YAMLExtractor)
- [x] Config schema extraction (YAMLExtractor)
- [x] Cross-file relations (SymbolRelationBuilder: services→classes, routes→controllers, plugins→classes)
- [x] Extension impact graph (DatabaseApi::getExtensionImpactGraph)
- [x] Group report output by extension and category (UI views with toggle buttons)
- [x] **Library usage** across JS/CSS assets (libraries.yml → asset linking) via `AssetUsageExtractor`
- [x] **Frontend assets** parsing and relation tracking via `DrupalLibrariesExtractor`
- [x] **Hooks detection** - procedural hook → `#[Hook]` attribute suggestions via `VersionDiffer::detectHookModernization()`
- [x] **Event subscribers** extraction from YAML via `YAMLExtractor`
- [x] **Per-extension drill-down** pages (`/runs/{id}/extensions/{path}`) via `extension-detail.twig`

### 1.3 Upgrade-focused regression reporting ✅ DONE
- [x] Higher-level findings for removed APIs, renamed service IDs, route/controller changes, signature changes, config changes, plugin drift, namespace moves
- [x] Severity, confidence, and "why this is risky" summaries via `ChangeSeverityClassifier`
- [x] "Likely mechanical fix" vs "manual review required" classification via `fix_method` field
- [x] Migration hints inline in scan results

### 1.4 Expected implementation size
- 10-15 existing files touched
- 6-10 new files
- 1 schema migration
- roughly 800-1500 LOC

### 1.5 Expected value
- high value for upgrade regressions in custom Drupal code
- realistic target: catch a large share of framework-driven breakage before runtime
- low value for pure business-logic regressions with unchanged API shape

### Phase 1 UI Enhancements Needed

The backend for Phase 1.2 and 1.3 is largely complete, but the UI needs enhancements to expose this functionality:

#### Missing UI Features
- [x] **Project metadata display** - Show `package_name` and `root_name` in project detail and dashboard ✅ DONE
- [x] **Project type badges** - Visual indicators (icon + color) for drupal-module, drupal-theme, etc. ✅ DONE
- [x] **Modernization filter** - Quick filter to show only modernization suggestions ✅ DONE
- [x] **Per-extension drill-down** - Click an extension in grouping view to get dedicated page with just that extension's findings ✅ DONE
- [x] **Code preview panel** - Show actual source code snippets for matches (with syntax highlighting) ✅ DONE
- [x] **Advanced filtering** - Collapsible sidebar with severity, category, fixability, change type, and file pattern filters ✅ DONE
- [ ] **Bulk apply fixes** - UI to select and apply multiple auto-fixable changes at once
- [ ] **Export results** - Download scan results as JSON/CSV/markdown report
- [ ] **Diff visualization** - Inline diff display for signature changes (already partially in template, could be enhanced)
- [ ] **Extension dependency graph** - Visual representation of extension dependencies and impact

### Phase 1 Completion Checklist

#### Backend (remaining)
1. **Library/Asset Analysis** ✅ DONE
   - Parse `*.libraries.yml` files via `DrupalLibrariesExtractor`
   - Extract JS/CSS dependencies, external assets, deprecations
   - Track library usage in PHP and Twig via `AssetUsageExtractor`
   - `LibraryDiffer` generates changes for library removals, asset removals, dependency removals, deprecations
   - Integrated into `VersionDiffer` pipeline
   - `QueryGenerator` handles `library_*` change types
   - `ChangeSeverityClassifier` classifies library-related changes

2. **Enhanced Hooks Detection** ✅ DONE
   - PHPExtractor detects procedural hooks (`.module` files) and `#[Hook]` attributes
   - `VersionDiffer::detectHookModernization()` compares procedural vs attribute hooks
   - Generates `hook_to_attribute` modernization suggestions with migration hints
   - `QueryGenerator` generates tree-sitter queries for procedural hook functions
   - `ChangeSeverityClassifier` classifies `hook_to_attribute` as modernization

3. **Event Subscribers** ✅ DONE
   - `YAMLExtractor` emits `event_subscriber` symbols from `*.services.yml` when service has `event_subscriber` tag
   - Links to subscriber class via metadata
   - Works alongside existing PHP-side `EventSubscriberInterface` detection

#### UI (remaining)
1. **Dashboard Enhancements** ✅ DONE
   - Project type badges on project cards ✅
   - Package name display ✅

2. **Project Detail Enhancements** ✅ DONE
   - Show package_name and root_name ✅
   - Quick actions via `selectBranchForScan()` ✅

3. **Scan Results Enhancements** ✅ DONE
   - Code preview panel with click-to-expand (`toggleCodePreview`) ✅
   - Advanced filtering sidebar (`filter-sidebar.twig`, `matches.js`) ✅
   - Modernization filter ✅
   - Per-extension drill-down pages (`extension-detail.twig`) ✅
   - Match item fragment extraction (`match-item.twig`) ✅

4. **Version Detail Enhancements** ✅ DONE
   - Language-grouped symbol distribution cards ✅
   - Clickable cards linking to symbols browser with language filter ✅
   - Symbols browser with language/type filtering ✅

5. **Remaining UI work** (Phase 2+)
   - [ ] Bulk apply fixes
   - [ ] Export results (JSON/CSV/markdown)
   - [ ] Enhanced diff visualization
   - [ ] Extension dependency graph visualization

### UI Testing Strategy

All UI tests live in `tests/Unit/Web/WebServerTemplateTest.php`. Tests render Twig templates
directly with mock data — no HTTP server or browser required.

#### What we test
- **Render smoke tests** — every template renders without Twig errors given realistic context arrays
- **Fragment inclusion** — shared fragments (`match-item.twig`, `filter-sidebar.twig`, `active-filters.twig`) are included and produce expected DOM elements
- **Data attributes** — `data-category`, `data-fixable`, `data-change-type`, `data-match-id` are present for JS filtering
- **Balanced HTML** — open/close counts for `<div>` and `<section>` tags match (catches broken nesting)
- **Change type coverage** — each change type (`function_removed`, `library_removed`, `hook_to_attribute`, `signature_changed`, etc.) renders its specific UI elements (severity badge, diff block, migration advice)
- **Empty states** — templates degrade gracefully when data arrays are empty

#### What we don't test (and why)
- **JavaScript behavior** — `matches.js` filtering, code preview fetch, view toggling. These are client-side and would need a browser harness (Playwright/Cypress). Low priority since the JS is thin glue code.
- **CSS rendering** — Visual correctness of `.lang-card`, `.badge-lang--*`, hover states. Would need visual regression testing. Not worth the tooling cost at this stage.
- **WebServer handler wiring** — Route registration and request→response flow. Covered implicitly by integration tests in Docker.

#### Adding a new template test
1. Add a `testNewTemplateRenders()` method that calls `$this->twig->render()` with realistic mock data
2. Assert key DOM elements are present (`assertStringContainsString`)
3. For templates using fragments, verify fragment-specific selectors (e.g., `id="filter-sidebar"`)
4. Add a balanced tags check if the template has non-trivial nesting
5. For new change types, add a `testMatchItemRendersNewType()` via `renderMatchItem(['change_type' => '...'])`

#### Convention
- Mock data helpers (`makeMatch()`, `makeRun()`, `makeSummary()`) keep test data DRY
- Tests are grouped by template with comment headers (`// -- Dashboard ---`)
- Render helpers (`renderRunDetail()`, `renderExtensionDetail()`, `renderMatchItem()`) encapsulate template+context pairs

## Phase 2 - Drupal App Analysis Depth

After project typing works, improve how much Evolver understands about a Drupal application as a whole.

### 2.1 Extension graph
- [ ] Build dependency and impact graph across:
  - modules
  - themes
  - services
  - routes
  - config
  - libraries
- [ ] Attribute findings to the owning custom extension
- [ ] Show upgrade hotspots by extension

### 2.2 Regression surfaces
- [ ] Track project-specific regression categories:
  - container wiring drift
  - controller and form handler drift
  - plugin discovery changes
  - deprecated lifecycle risk
  - library and asset-level breakage
  - config install/schema drift
- [ ] Add aggregate risk scoring per project and per extension

### 2.3 Expected implementation size
- 12-20 existing files touched
- 8-14 new files
- 1-2 schema migrations
- roughly 1200-2500 LOC

### 2.4 Expected value
- this is where Evolver becomes meaningfully useful for real app audits
- strong for upgrade readiness reviews and regression triage
- still not a substitute for runtime tests

## Phase 3 - Adapter Architecture

Only do this after Drupal custom-module analysis is strong enough to justify generalization.

### 3.1 Core abstractions
- [ ] Introduce adapter interfaces for:
  - project detection
  - version detection
  - symbol extraction providers
  - diff rule providers
  - query generation
  - fix template generation
- [ ] Move Drupal-specific logic behind a `DrupalAdapter`

### 3.2 Data model changes
- [ ] Add adapter scope concepts:
  - `ecosystem`
  - `package_name`
  - `project_type`
- [ ] Stop treating a version as only a global tag
- [ ] Allow change sets to belong to a package or framework scope

### 3.3 Expected implementation size
- 15-25 existing files touched
- 10-18 new files
- 2-4 schema migrations
- roughly 1500-3000 LOC

### 3.4 Expected value
- unlocks Symfony and package-scoped upgrade intelligence
- reduces Drupal coupling in the storage and scanning model
- makes future ecosystems cheaper to add

## Phase 4 - Symfony Support

Symfony is the best next target after Drupal because the PHP analysis model already exists.

### 4.0 POC — proven (2026-03-06)

Symfony 7.4.6 → 8.0.0 indexed and diffed with only 4 lines of new code:
- `trigger_deprecation()` version extraction in `PHPExtractor::checkDeprecation()`
- `@deprecated since Symfony X.Y` docblock pattern in `PHPExtractor::applyDeprecationFromDocblock()`
- `symfony/framework-bundle` version detection in `VersionDetector`
- bare `--db` filenames now resolve to `.data/` in `Database`

Results: 16,310 changes detected (5,435 files, 31,149 symbols in 7.4.6 vs 4,278 files, 30,215 in 8.0.0), 3,109 renames matched, 93 deprecated symbols with version tags. `Request::get()` removal and `TaggedIterator` removals both confirmed. ~98s total.

See `docs/SYMFONY_ADAPTATION.md` for full details.

### 4.1 Symfony adapter — remaining work
- [x] Read versions from `composer.lock` (`symfony/framework-bundle`)
- [x] Index and diff Symfony components (PHP symbols, renames, signature changes, deprecations)
- [ ] `SymfonyYamlExtractor` — services, framework config, security, routes
- [ ] `AttributeExtractor` — detect `#[Route]`, `#[IsGranted]`, `#[Template]` and YAML→attribute migrations
- [ ] Symfony-specific fix templates (namespace moves, method renames, attribute conversions)
- [ ] Multi-version indexing (7.0 → 7.1 → ... → 8.0) for granular upgrade paths
- [ ] Symfony-specific change rules: Messenger handlers, event subscribers, config tree changes

### 4.2 Expected implementation size (remaining)
- 4-6 new files (extractors, templates)
- 2-4 existing files touched
- roughly 400-800 LOC

### 4.3 Expected value
- high value for framework upgrade regressions
- especially strong for config and DI-related drift

## Phase 5 - JS/CSS Upgrade Tracking

Do this after package scoping exists. JS/CSS support should be package-aware, not just AST-aware.

### 5.1 Package-aware upgrade model
- [ ] Detect frontend projects from `package.json` and lockfiles
- [ ] Track upgrades by package and version range
- [ ] Support package-specific rule sets rather than one generic JS/CSS differ

### 5.2 Useful first targets
- [ ] JS/TS import and export renames
- [ ] package API removals
- [ ] config migrations for build tools
- [ ] selector and custom-property drift where it is machine-detectable
- [ ] library handle and asset path changes in Drupal-integrated frontend code

### 5.3 Expected implementation size
- 12-20 new files
- 6-10 existing files touched
- roughly 1200-2500 LOC

### 5.4 Expected value
- medium value overall
- high value for package and config migrations
- lower value for behavioral regressions and styling regressions without explicit rule sets

## Regression Analysis - What Evolver Should Be Good At

### High-value regression classes
- removed or renamed APIs
- signature changes
- namespace moves
- service ID changes
- route/controller target changes
- config key removals and renames
- plugin ID changes
- deprecated-to-removed lifecycle

### Medium-value regression classes
- framework convention changes
- frontend package migration issues
- asset and selector drift with known mappings

### Low-value regression classes
- business logic regressions
- data migration bugs
- performance regressions
- runtime state bugs
- semantic changes with identical API shape

## Success Criteria

### Near-term
- Evolver should be clearly useful on real custom Drupal projects before any non-Drupal expansion

### Mid-term
- Evolver should surface upgrade regressions early enough to reduce manual audit time materially

### Long-term
- Evolver should become an upgrade intelligence engine with Drupal as the strongest adapter and Symfony as the second

## Non-Goals Right Now

- no rebrand away from `Evolver`
- no broad multi-framework rewrite before Drupal custom-module analysis is strong
- no attempt to replace runtime tests
- no claim that static analysis can catch all regressions
