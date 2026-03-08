# Phase 1 UI Enhancement Plan

## Overview

Phase 1 backend and UI are substantially complete. This document records the original plan and implementation status.

## Implementation Status

### Completed
- ✅ Project type detection with visual badges (`pill.type-*` classes)
- ✅ Package name and root name displayed in dashboard and project detail
- ✅ Cross-file relations (services→classes, routes→controllers, plugins→classes)
- ✅ Extension impact graph (visible in version compare view)
- ✅ Scan results grouped by extension and category (toggle buttons)
- ✅ Severity classification and migration hints (displayed in results)
- ✅ Auto-fixable detection (shown as "Auto-fix available" badge)
- ✅ Code preview panel with click-to-expand (`toggleCodePreview` in `matches.js`)
- ✅ Advanced filtering sidebar (`filter-sidebar.twig`) with severity, category, fixability, change type, file pattern
- ✅ Per-extension drill-down pages (`/runs/{id}/extensions/{path}`)
- ✅ Match item fragment (`match-item.twig`) shared across all views
- ✅ Language-grouped symbol distribution cards on version detail
- ✅ Symbols browser with language and type filtering
- ✅ Library/asset analysis (`LibraryDiffer`, `AssetUsageExtractor`)
- ✅ Hook modernization detection (`VersionDiffer::detectHookModernization()`)
- ✅ Event subscriber extraction from YAML

### Remaining (deferred to Phase 2+)
1. **Bulk apply fixes** - UI to select and apply multiple auto-fixable changes at once
2. **Export results** - Download scan results as JSON/CSV/markdown report
3. **Enhanced diff visualization** - Richer inline diff display for signature changes
4. **Extension dependency graph** - Visual representation of extension dependencies and impact

## Implementation Plan

### Priority 1: Quick Wins (1-2 days)

#### 1.1 Project Metadata Display
**Files:** `templates/project-detail.twig`, `templates/dashboard.twig`

**Changes:**
```twig
{# project-detail.twig - add to project information section #}
<div class="status-grid">
    {% if project.package_name %}
    <div>
        <span class="meta-label">Package name</span>
        <strong><code>{{ project.package_name }}</code></strong>
    </div>
    {% endif %}
    {% if project.root_name %}
    <div>
        <span class="meta-label">Display name</span>
        <strong>{{ project.root_name }}</strong>
    </div>
    {% endif %}
    {# ... existing fields ... #}
</div>
```

**Benefits:** Users can see which project they're looking at without checking composer.json

#### 1.2 Project Type Badges
**Files:** `templates/dashboard.twig`, `public/assets/app.css`

**Changes:**
```twig
{# dashboard.twig #}
<span class="pill type-{{ project.type }}">{{ project.type|replace({'-': ' '})|title }}</span>
```

```css
/* app.css */
.pill.type-drupal-module { background: #e3f2fd; color: #1565c0; }
.pill.type-drupal-theme { background: #fce4ec; color: #c2185b; }
.pill.type-drupal-profile { background: #f3e5f5; color: #7b1fa2; }
.pill.type-drupal-site { background: #e8f5e9; color: #2e7d32; }
.pill.type-symfony { background: #fff3e0; color: #ef6c00; }
```

**Benefits:** Visual distinction between project types at a glance

#### 1.3 Modernization Filter Button
**Files:** `templates/run-detail.twig`, `src/Web/WebServer.php`

**Changes:**
```twig
{# Add filter button #}
<div class="panel-actions">
    <button class="button small" onclick="filterView('all')">All</button>
    <button class="button small" onclick="filterView('modernization')">Modernization</button>
    <button class="button small" onclick="filterView('breaking')">Breaking</button>
    <button class="button small" onclick="filterView('fixable')">Auto-fixable</button>
</div>
```

**Benefits:** Quick access to actionable upgrade suggestions

---

### Priority 2: Code Preview (2-3 days)

#### 2.1 Code Preview Panel
**Files:** `templates/run-detail.twig`, `src/Web/WebServer.php`, `src/Storage/DatabaseApi.php`

**New endpoint:** `GET /matches/{id}/preview`

**Changes:**
```php
// WebServer.php
public function handleMatchPreview(Request $request): Response
{
    $matchId = $this->routeId($request);
    $match = $this->api->codeMatches()->findById($matchId);

    if ($match === null) {
        return $this->text('Match not found', HttpStatus::NOT_FOUND);
    }

    // Read source file and extract relevant lines
    $filePath = $match['file_path'];
    $lineStart = max(1, ($match['line_start'] ?? 1) - 3);
    $lineEnd = ($match['line_end'] ?? $lineStart) + 3;

    $sourceLines = $this->readSourceLines($match['project_id'], $filePath, $lineStart, $lineEnd);

    return $this->json([
        'file_path' => $filePath,
        'line_start' => $lineStart,
        'line_end' => $lineEnd,
        'highlight_start' => $match['line_start'] ?? 1,
        'highlight_end' => $match['line_end'] ?? $lineStart,
        'source' => $sourceLines,
    ]);
}
```

```twig
{# run-detail.twig - add preview panel #}
<div class="match-item">
    <div class="match-header" onclick="toggleCodePreview({{ match.id }})">
        {# existing header content #}
        <span class="preview-hint">Click to preview code</span>
    </div>
    <div id="preview-{{ match.id }}" class="code-preview" style="display: none;">
        <div class="code-loading">Loading...</div>
    </div>
</div>
```

**Benefits:** Users can see context around matches without opening files

---

### Priority 3: Per-Extension Pages (2-3 days)

#### 3.1 Extension Detail Page
**Files:** `templates/extension-detail.twig` (new), `src/Web/WebServer.php`

**New route:** `GET /runs/{runId}/extensions/{extensionName}`

**Changes:**
```php
// WebServer.php
public function handleExtensionDetail(Request $request): Response
{
    $runId = (int) $request->getAttribute('runId');
    $extensionName = (string) $request->getAttribute('extensionName');

    $matches = $this->api->getMatchesForExtension($runId, $extensionName);
    $summary = $this->api->summarizeExtensionMatches($matches);

    return $this->html('extension-detail.twig', [
        'run' => $this->api->scanRuns()->findById($runId),
        'extension_name' => $extensionName,
        'matches' => $matches,
        'summary' => $summary,
    ]);
}
```

```twig
{# extension-detail.twig #}
{% extends "layout.twig" %}

{% block content %}
<section class="hero hero-compact">
    <div>
        <p class="eyebrow">Extension Analysis</p>
        <h1><code>{{ extension_name }}</code></h1>
        <p class="muted">{{ summary.total }} findings • {{ summary.auto_fixable }} auto-fixable</p>
    </div>
    <a class="button" href="/runs/{{ run.id }}">Back to scan</a>
</section>

{# Extension summary stats #}
<section class="grid three-up">
    <article class="panel stat-panel">
        <span class="meta-label">Breaking Changes</span>
        <strong>{{ summary.breaking }}</strong>
    </article>
    <article class="panel stat-panel">
        <span class="meta-label">Modernizations</span>
        <strong>{{ summary.modernization }}</strong>
    </article>
    <article class="panel stat-panel">
        <span class="meta-label">Low Risk</span>
        <strong>{{ summary.low_risk }}</strong>
    </article>
</section>

{# Match list for this extension #}
{# ... similar to run-detail.twig but filtered ... #}
{% endblock %}
```

**Benefits:** Focused view for fixing issues in specific modules/themes

---

### Priority 4: Advanced Filtering (1-2 days)

#### 4.1 Filter Sidebar
**Files:** `templates/run-detail.twig`, `public/assets/app.css`

**Changes:**
```twig
{# Add filter sidebar #}
<aside class="filters">
    <h3>Filters</h3>

    <div class="filter-group">
        <h4>Severity</h4>
        <label><input type="checkbox" checked data-filter="severity" value="breaking"> Breaking</label>
        <label><input type="checkbox" checked data-filter="severity" value="deprecation"> Deprecation</label>
        <label><input type="checkbox" checked data-filter="severity" value="modernization"> Modernization</label>
    </div>

    <div class="filter-group">
        <h4>Category</h4>
        <label><input type="checkbox" checked data-filter="category" value="Removals"> Removals</label>
        <label><input type="checkbox" checked data-filter="category" value="Modernization"> Modernization</label>
        <label><input type="checkbox" checked data-filter="category" value="Signatures"> Signatures</label>
        <label><input type="checkbox" checked data-filter="category" value="Frontend"> Frontend</label>
    </div>

    <div class="filter-group">
        <h4>Fixability</h4>
        <label><input type="checkbox" data-filter="fixable" value="yes"> Auto-fixable only</label>
    </div>

    <button class="button small" onclick="clearFilters()">Clear all</button>
</aside>
```

```css
/* app.css */
.filters {
    background: var(--panel-bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 1rem;
}

.filter-group {
    margin-bottom: 1.5rem;
}

.filter-group label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
    cursor: pointer;
}
```

**Benefits:** Users can quickly find relevant issues

---

### Priority 5: Export Functionality (1 day)

#### 5.1 Export Buttons
**Files:** `templates/run-detail.twig`, `src/Web/WebServer.php`

**New routes:**
- `GET /runs/{runId}/export/json`
- `GET /runs/{runId}/export/csv`
- `GET /runs/{runId}/export/markdown`

**Changes:**
```twig
{# Add export buttons #}
<div class="panel-actions">
    <button class="button small" onclick="exportRun('json')">Export JSON</button>
    <button class="button small" onclick="exportRun('csv')">Export CSV</button>
    <button class="button small" onclick="exportRun('md')">Export Report</button>
</div>
```

```php
// WebServer.php
public function handleExport(Request $request): Response
{
    $runId = $this->routeId($request);
    $format = $request->getAttribute('format');

    $run = $this->api->scanRuns()->findById($runId);
    $matches = $this->api->findMatchesWithChangesForRun($runId);

    return match($format) {
        'json' => $this->json(['run' => $run, 'matches' => $matches]),
        'csv' => $this->csv($matches, "scan-{$runId}-{$run['target_core_version']}.csv"),
        'md' => $this->markdown($this->generateMarkdownReport($run, $matches)),
        default => $this->text('Invalid format', HttpStatus::BAD_REQUEST),
    };
}
```

**Benefits:** Users can share findings offline or import into other tools

---

## Backend Enhancements Needed

### Library/Asset Analysis (3-5 days)

#### 1. LibrariesYamlExtractor
**New file:** `src/Indexer/Extractor/LibrariesExtractor.php`

**Responsibilities:**
- Parse `*.libraries.yml` files
- Extract library definitions (name, version, CSS/JS files, dependencies)
- Store as `library_definition` symbols

#### 2. AssetUsageExtractor
**New file:** `src/Indexer/Extractor/AssetUsageExtractor.php`

**Responsibilities:**
- Find library attachments in PHP (`#attached`)
- Find library usage in Twig (`{{ attach_library() }}`)
- Link usage to library definitions

#### 3. LibraryDiffer
**New file:** `src/Differ/LibraryDiffer.php`

**Responsibilities:**
- Detect library removals/renames
- Detect asset path changes
- Detect dependency changes

### Enhanced Hooks Detection (2-3 days)

#### 1. HooksExtractor
**Enhancement:** `src/Indexer/Extractor/PHPExtractor.php`

**New functionality:**
- Detect procedural function implementations (`function MODULE_foo()`)
- Match against core hook definitions
- Store as `hook_implementation` symbols

#### 2. HookModernizationGenerator
**New file:** `src/Scanner/HookModernizationScanner.php`

**Responsibilities:**
- Generate `#[Hook('foo')]` attribute suggestions
- Create virtual matches for legacy hook implementations
- Provide before/after code examples

---

## Summary

### Completed Features
| Feature | Status | Files |
|---------|--------|-------|
| Project metadata display | ✅ | `dashboard.twig`, `project-detail.twig` |
| Project type badges | ✅ | `app.css` (`.pill.type-*`) |
| Modernization filter | ✅ | `filter-sidebar.twig`, `matches.js` |
| Code preview panel | ✅ | `match-item.twig`, `matches.js`, `WebServer::handleMatchPreview()` |
| Per-extension pages | ✅ | `extension-detail.twig`, `WebServer::handleExtensionDetail()` |
| Advanced filtering | ✅ | `filter-sidebar.twig`, `active-filters.twig`, `matches.js` |
| Library/Asset analysis | ✅ | `LibraryDiffer`, `AssetUsageExtractor`, `DrupalLibrariesExtractor` |
| Hook modernization | ✅ | `VersionDiffer::detectHookModernization()`, `ChangeSeverityClassifier` |
| Event subscribers | ✅ | `YAMLExtractor::extractEventSubscriberFromService()` |
| Language-grouped symbols | ✅ | `detail.twig` (lang-cards), `symbols.twig` (language filter) |

### Deferred to Phase 2+
| Feature | Notes |
|---------|-------|
| Export results | JSON/CSV/markdown download |
| Bulk apply fixes | Multi-select auto-fixable changes |
| Enhanced diff visualization | Richer signature change display |
| Extension dependency graph | Visual impact graph |
