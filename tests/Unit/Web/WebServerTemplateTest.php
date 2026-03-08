<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Web;

use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Tests that all Twig templates render correctly with realistic data.
 *
 * Exercises each template used by WebServer handlers, verifying fragment
 * includes work, structural HTML elements are present, and new change types
 * (library_*, hook_to_attribute) render properly.
 */
class WebServerTemplateTest extends TestCase
{
    private Environment $twig;
    private DatabaseApi $api;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/templates';
        $loader = new FilesystemLoader($templateDir);
        $this->twig = new Environment($loader, ['strict_variables' => false]);
        $this->twig->addFilter(new \Twig\TwigFilter('json_decode', function (string $json): array {
            return json_decode($json, true) ?: [];
        }));

        $this->api = new DatabaseApi(':memory:');
    }

    // -- Dashboard -----------------------------------------------------------

    public function testDashboardRendersWithProjects(): void
    {
        $html = $this->twig->render('dashboard.twig', [
            'projects' => [[
                'id' => 1, 'name' => 'mymodule', 'path' => '/tmp/mymodule',
                'project_type' => 'drupal-module', 'package_name' => 'drupal/mymodule',
                'root_name' => 'mymodule', 'source_type' => 'local_path',
                'default_branch_record' => ['branch_name' => 'main'],
                'latest_run' => ['id' => 1, 'status' => 'completed', 'match_count' => 5],
            ]],
            'active_jobs' => [],
            'recent_jobs' => [],
        ]);

        $this->assertStringContainsString('mymodule', $html);
        $this->assertStringContainsString('drupal/mymodule', $html);
    }

    public function testDashboardRendersEmpty(): void
    {
        $html = $this->twig->render('dashboard.twig', [
            'projects' => [],
            'active_jobs' => [],
            'recent_jobs' => [],
        ]);

        $this->assertStringNotEmpty($html);
    }

    // -- Run Detail ----------------------------------------------------------

    public function testRunDetailRendersAllFragments(): void
    {
        $html = $this->renderRunDetail();

        // Filter sidebar fragment
        $this->assertStringContainsString('id="filter-sidebar"', $html, 'Filter sidebar fragment missing');
        $this->assertStringContainsString('data-filter-type="severity"', $html, 'Severity filter missing');
        $this->assertStringContainsString('data-filter-type="category"', $html, 'Category filter missing');
        $this->assertStringContainsString('data-filter-type="fixability"', $html, 'Fixability filter missing');
        $this->assertStringContainsString('data-filter-type="change_type"', $html, 'Change type filter missing');
        $this->assertStringContainsString('id="file-pattern"', $html, 'File pattern filter missing');

        // Active filters fragment
        $this->assertStringContainsString('id="active-filters"', $html, 'Active filters fragment missing');
        $this->assertStringContainsString('id="active-filters-list"', $html, 'Active filters list element missing');

        // View containers
        $this->assertStringContainsString('id="view-flat"', $html, 'Flat view container missing');
        $this->assertStringContainsString('id="view-extension"', $html, 'Extension view container missing');
        $this->assertStringContainsString('id="view-category"', $html, 'Category view container missing');

        // JS
        $this->assertStringContainsString('matches.js', $html, 'matches.js script tag missing');
    }

    public function testRunDetailRendersMatchItems(): void
    {
        $html = $this->renderRunDetail();

        $this->assertStringContainsString('class="match-item', $html, 'Match item not rendered');
        $this->assertStringContainsString('data-change-type="function_removed"', $html, 'Match data attribute missing');
        $this->assertStringContainsString('data-match-id="1"', $html, 'Match ID attribute missing');
        $this->assertStringContainsString('drupal_render', $html, 'Match FQN not rendered');
    }

    public function testRunDetailMatchItemHasPreviewToggle(): void
    {
        $html = $this->renderRunDetail();

        $this->assertStringContainsString('toggleCodePreview', $html, 'Code preview toggle missing');
        $this->assertStringContainsString('preview-icon', $html, 'Preview icon missing');
        $this->assertStringContainsString('id="preview-1"', $html, 'Preview container missing');
    }

    public function testRunDetailRendersExtensionView(): void
    {
        $html = $this->renderRunDetail();

        $this->assertStringContainsString('modules/mymodule', $html, 'Extension path missing in extension view');
        $this->assertStringContainsString('extension-link', $html, 'Extension link missing');
    }

    public function testRunDetailRendersCategoryView(): void
    {
        $html = $this->renderRunDetail();

        $this->assertStringContainsString('Removals', $html, 'Category name missing');
    }

    public function testRunDetailRendersEmptyRun(): void
    {
        $html = $this->twig->render('run-detail.twig', [
            'project' => ['id' => 1, 'name' => 'test'],
            'run' => $this->makeRun(),
            'summary' => $this->makeSummary(),
            'matches' => [],
            'by_extension' => [],
            'by_category' => [],
            'logs' => [],
        ]);

        $this->assertStringContainsString('No matches', $html);
        $this->assertStringContainsString('id="filter-sidebar"', $html, 'Fragments should render even with empty matches');
    }

    public function testRunDetailHasBalancedDivs(): void
    {
        $html = $this->renderRunDetail();

        $openDivs = substr_count($html, '<div');
        $closeDivs = substr_count($html, '</div>');
        $this->assertSame($openDivs, $closeDivs, "Unbalanced <div> tags: {$openDivs} open vs {$closeDivs} close");
    }

    public function testRunDetailHasBalancedSections(): void
    {
        $html = $this->renderRunDetail();

        $openSections = substr_count($html, '<section');
        $closeSections = substr_count($html, '</section>');
        $this->assertSame($openSections, $closeSections, "Unbalanced <section> tags");
    }

    // -- Extension Detail ----------------------------------------------------

    public function testExtensionDetailRendersFragments(): void
    {
        $html = $this->renderExtensionDetail();

        $this->assertStringContainsString('modules/mymodule', $html);
        $this->assertStringContainsString('id="filter-sidebar"', $html, 'Filter sidebar missing');
        $this->assertStringContainsString('id="active-filters"', $html, 'Active filters missing');
        $this->assertStringContainsString('class="match-item', $html, 'Match items missing');
    }

    public function testExtensionDetailHasBalancedDivs(): void
    {
        $html = $this->renderExtensionDetail();

        $openDivs = substr_count($html, '<div');
        $closeDivs = substr_count($html, '</div>');
        $this->assertSame($openDivs, $closeDivs, "Unbalanced <div> tags: {$openDivs} open vs {$closeDivs} close");
    }

    public function testExtensionDetailRendersEmpty(): void
    {
        $html = $this->twig->render('extension-detail.twig', [
            'run' => ['id' => 1],
            'project' => ['id' => 1, 'name' => 'test'],
            'extension_path' => 'modules/empty',
            'matches' => [],
            'summary' => $this->makeSummary(),
        ]);

        $this->assertStringContainsString('No findings', $html);
    }

    // -- Match Item Change Types ---------------------------------------------

    public function testMatchItemRendersLibraryRemoved(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'library_removed',
            'severity' => 'breaking',
            'old_fqn' => 'core/drupal.ajax',
            'migration_hint' => 'Library removed. Remove attach_library() references.',
        ]);

        $this->assertStringContainsString('core/drupal.ajax', $html);
        $this->assertStringContainsString('library removed', $html);
        $this->assertStringContainsString('severity-breaking', $html);
        $this->assertStringContainsString('attach_library()', $html);
    }

    public function testMatchItemRendersLibraryCssRemoved(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'library_css_removed',
            'severity' => 'breaking',
            'old_fqn' => 'core/drupal.dialog',
            'migration_hint' => 'CSS file removed from library.',
        ]);

        $this->assertStringContainsString('library css removed', $html);
        $this->assertStringContainsString('core/drupal.dialog', $html);
    }

    public function testMatchItemRendersLibraryDeprecated(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'library_deprecated',
            'severity' => 'deprecation',
            'old_fqn' => 'core/jquery.once',
            'migration_hint' => 'Use core/once instead.',
        ]);

        $this->assertStringContainsString('library deprecated', $html);
        $this->assertStringContainsString('core/once', $html);
    }

    public function testMatchItemRendersHookToAttribute(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'hook_to_attribute',
            'severity' => 'modernization',
            'old_fqn' => 'form_alter',
            'migration_hint' => "Convert to #[Hook('form_alter')] attribute.",
        ]);

        $this->assertStringContainsString('form_alter', $html);
        $this->assertStringContainsString('hook to attribute', $html);
        $this->assertStringContainsString('severity-modernization', $html);
        $this->assertStringContainsString('#[Hook', $html);
    }

    public function testMatchItemRendersSignatureChanged(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'signature_changed',
            'severity' => 'breaking',
            'old_fqn' => 'Drupal\\Core\\Entity\\EntityInterface::save',
            'migration_hint' => 'Method signature changed.',
            'diff_json' => json_encode([
                'changes' => [
                    ['type' => 'parameter_added', 'position' => 1, 'param' => ['name' => '$context', 'type' => 'array']],
                ],
            ]),
        ]);

        $this->assertStringContainsString('EntityInterface::save', $html);
        $this->assertStringContainsString('signature changed', $html);
        $this->assertStringContainsString('$context', $html);
        $this->assertStringContainsString('diff-plus', $html);
    }

    public function testMatchItemRendersParameterRemoved(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'signature_changed',
            'severity' => 'breaking',
            'old_fqn' => 'Drupal\\Core\\Foo::bar',
            'diff_json' => json_encode([
                'changes' => [
                    ['type' => 'parameter_removed', 'position' => 2, 'param' => ['name' => '$legacy']],
                ],
            ]),
        ]);

        $this->assertStringContainsString('$legacy', $html);
        $this->assertStringContainsString('diff-minus', $html);
    }

    public function testMatchItemRendersTypeChange(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'signature_changed',
            'severity' => 'breaking',
            'old_fqn' => 'Drupal\\Core\\Foo::baz',
            'diff_json' => json_encode([
                'changes' => [
                    ['type' => 'parameter_type_changed', 'position' => 0, 'old_type' => 'string', 'new_type' => 'int'],
                ],
            ]),
        ]);

        $this->assertStringContainsString('string', $html);
        $this->assertStringContainsString('int', $html);
        $this->assertStringContainsString('diff-change', $html);
    }

    public function testMatchItemRendersFunctionRemoved(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'drupal_render',
            'migration_hint' => 'Use the renderer service.',
        ]);

        $this->assertStringContainsString('drupal_render', $html);
        $this->assertStringContainsString('function removed', $html);
        $this->assertStringContainsString('renderer service', $html);
    }

    public function testMatchItemRendersAutoFixable(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'drupal_render',
            'fix_method' => 'template',
        ]);

        $this->assertStringContainsString('Auto-fix available', $html);
    }

    public function testMatchItemHasCorrectDataAttributes(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'library_removed',
            'severity' => 'breaking',
            'old_fqn' => 'core/lib',
            'fix_method' => 'manual',
        ]);

        $this->assertStringContainsString('data-change-type="library_removed"', $html);
        $this->assertStringContainsString('data-fixable="no"', $html);
    }

    public function testMatchItemModernizationCategory(): void
    {
        $html = $this->renderMatchItem([
            'change_type' => 'hook_to_attribute',
            'severity' => 'modernization',
            'old_fqn' => 'form_alter',
        ]);

        // hook_to_attribute matches 'to_attribute' in the regex → category = modernization
        $this->assertStringContainsString('data-category="modernization"', $html);
    }

    // -- Filter Sidebar Fragment ---------------------------------------------

    public function testFilterSidebarRendersStandalone(): void
    {
        $html = $this->twig->render('fragments/filter-sidebar.twig');

        $this->assertStringContainsString('id="filter-sidebar"', $html);
        $this->assertStringContainsString('Severity', $html);
        $this->assertStringContainsString('Category', $html);
        $this->assertStringContainsString('Fixability', $html);
        $this->assertStringContainsString('Change Type', $html);
        $this->assertStringContainsString('File Pattern', $html);
        $this->assertStringContainsString('Apply Filters', $html);
        $this->assertStringContainsString('Clear All', $html);
    }

    public function testFilterSidebarHasCorrectCheckboxValues(): void
    {
        $html = $this->twig->render('fragments/filter-sidebar.twig');

        // Severity values
        $this->assertStringContainsString('value="breaking"', $html);
        $this->assertStringContainsString('value="deprecation"', $html);
        $this->assertStringContainsString('value="modernization"', $html);

        // Category values
        $this->assertStringContainsString('value="Removals"', $html);
        $this->assertStringContainsString('value="Modernization"', $html);
        $this->assertStringContainsString('value="Signatures"', $html);
        $this->assertStringContainsString('value="Frontend"', $html);

        // Change type values
        $this->assertStringContainsString('value="removed"', $html);
        $this->assertStringContainsString('value="deprecated"', $html);
        $this->assertStringContainsString('value="renamed"', $html);
        $this->assertStringContainsString('value="signature_changed"', $html);
        $this->assertStringContainsString('value="to_attribute"', $html);
    }

    // -- Active Filters Fragment ---------------------------------------------

    public function testActiveFiltersRendersStandalone(): void
    {
        $html = $this->twig->render('fragments/active-filters.twig');

        $this->assertStringContainsString('id="active-filters"', $html);
        $this->assertStringContainsString('id="active-filters-list"', $html);
        $this->assertStringContainsString('Clear all', $html);

        // Should NOT contain orphaned HTML from old extraction bug
        $this->assertStringNotContainsString('view-flat', $html, 'Active filters should not contain view-flat div');
        $this->assertStringNotContainsString('match-list', $html, 'Active filters should not contain match list');
        $this->assertStringNotContainsString('code-preview', $html, 'Active filters should not contain code preview');
    }

    // -- Versions Page -------------------------------------------------------

    public function testVersionsPageRenders(): void
    {
        $html = $this->twig->render('versions.twig', [
            'versions' => [
                ['id' => 1, 'tag' => '10.2.0', 'major' => 10, 'minor' => 2, 'patch' => 0, 'file_count' => 100, 'symbol_count' => 500],
            ],
            'changes_summary' => [],
            'active_jobs' => [],
        ]);

        $this->assertStringContainsString('10.2.0', $html);
    }

    // -- Project Detail ------------------------------------------------------

    public function testProjectDetailRenders(): void
    {
        $html = $this->twig->render('project-detail.twig', [
            'project' => [
                'id' => 1, 'name' => 'mymodule', 'path' => '/tmp/mymodule',
                'project_type' => 'drupal-module', 'package_name' => 'drupal/mymodule',
                'root_name' => 'mymodule', 'source_type' => 'local_path',
            ],
            'branches' => [
                ['id' => 1, 'branch_name' => 'main', 'is_default' => 1, 'latest_run' => null],
            ],
            'runs' => [],
            'versions' => [
                ['id' => 1, 'tag' => '10.2.0'],
                ['id' => 2, 'tag' => '11.0.0'],
            ],
        ]);

        $this->assertStringContainsString('mymodule', $html);
        $this->assertStringContainsString('drupal/mymodule', $html);
    }

    // -- Jobs Page -----------------------------------------------------------

    public function testJobsPageRenders(): void
    {
        $html = $this->twig->render('jobs.twig', [
            'active_jobs' => [],
            'recent_jobs' => [],
        ]);

        $this->assertStringNotEmpty($html);
    }

    // -- New Project Form ----------------------------------------------------

    public function testNewProjectFormRenders(): void
    {
        $html = $this->twig->render('project-form.twig');

        $this->assertStringNotEmpty($html);
    }

    // -- Helpers -------------------------------------------------------------

    private function renderRunDetail(array $extraMatches = []): string
    {
        $baseMatch = $this->makeMatch([
            'id' => 1,
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'drupal_render',
            'migration_hint' => 'Use renderer service.',
        ]);

        $matches = [$baseMatch, ...$extraMatches];

        return $this->twig->render('run-detail.twig', [
            'project' => ['id' => 1, 'name' => 'test-project'],
            'run' => $this->makeRun(),
            'summary' => $this->makeSummary(['total' => count($matches)]),
            'matches' => $matches,
            'by_extension' => [
                'modules/mymodule' => [
                    'count' => count($matches),
                    'by_severity' => ['breaking' => count($matches)],
                    'matches' => $matches,
                ],
            ],
            'by_category' => [
                'Removals' => [
                    'count' => count($matches),
                    'matches' => $matches,
                ],
            ],
            'logs' => [],
        ]);
    }

    private function renderExtensionDetail(array $matches = []): string
    {
        if ($matches === []) {
            $matches = [$this->makeMatch([
                'id' => 1,
                'change_type' => 'function_removed',
                'severity' => 'breaking',
                'old_fqn' => 'drupal_render',
            ])];
        }

        return $this->twig->render('extension-detail.twig', [
            'run' => ['id' => 1],
            'project' => ['id' => 1, 'name' => 'test'],
            'extension_path' => 'modules/mymodule',
            'matches' => $matches,
            'summary' => $this->makeSummary(['total' => count($matches)]),
        ]);
    }

    private function renderMatchItem(array $overrides = []): string
    {
        return $this->twig->render('fragments/match-item.twig', [
            'match' => $this->makeMatch($overrides),
        ]);
    }

    private function makeMatch(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'file_path' => 'src/Controller/MyController.php',
            'line_start' => 42,
            'line_end' => 42,
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'some_function',
            'migration_hint' => null,
            'fix_method' => 'manual',
            'diff_json' => null,
            'matched_source' => 'some_function()',
        ], $overrides);
    }

    private function makeRun(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'branch_name' => 'main',
            'from_core_version' => '10.2.0',
            'target_core_version' => '11.0.0',
            'status' => 'completed',
            'commit_sha' => 'abc1234',
            'scanned_file_count' => 50,
            'file_count' => 50,
            'match_count' => 3,
            'job_id' => null,
        ], $overrides);
    }

    private function makeSummary(array $overrides = []): array
    {
        return array_merge([
            'total' => 1,
            'auto_fixable' => 0,
            'by_severity' => ['breaking' => 1],
            'by_category' => ['Removals' => 1],
        ], $overrides);
    }

    private function assertStringNotEmpty(string $html): void
    {
        $this->assertNotSame('', trim($html), 'Rendered HTML should not be empty');
    }
}
