<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Web;

final class ResultsPageRenderTest extends UiTestCase
{
    public function testRunDetailRendersAllFragments(): void
    {
        $html = $this->renderRunDetail();

        $this->assertStringContainsString('/runs/1/plan', $html, 'Upgrade plan link missing');
        $this->assertStringContainsString('id="filter-sidebar"', $html, 'Filter sidebar fragment missing');
        $this->assertStringContainsString('data-filter-type="severity"', $html, 'Severity filter missing');
        $this->assertStringContainsString('data-filter-type="category"', $html, 'Category filter missing');
        $this->assertStringContainsString('data-filter-type="fixability"', $html, 'Fixability filter missing');
        $this->assertStringContainsString('data-filter-type="change_type"', $html, 'Change type filter missing');
        $this->assertStringContainsString('id="file-pattern"', $html, 'File pattern filter missing');

        $this->assertStringContainsString('id="active-filters"', $html, 'Active filters fragment missing');
        $this->assertStringContainsString('id="active-filters-list"', $html, 'Active filters list element missing');

        $this->assertStringContainsString('id="view-flat"', $html, 'Flat view container missing');
        $this->assertStringContainsString('id="view-extension"', $html, 'Extension view container missing');
        $this->assertStringContainsString('id="view-category"', $html, 'Category view container missing');

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
        $html = $this->renderTemplate('run-detail.twig', $this->makeRunDetailContext([]));

        $this->assertStringContainsString('No matches', $html);
        $this->assertStringContainsString('id="filter-sidebar"', $html, 'Fragments should render even with empty matches');
    }

    public function testRunDetailHasBalancedDivs(): void
    {
        $this->assertBalancedTag($this->renderRunDetail(), 'div');
    }

    public function testRunDetailHasBalancedSections(): void
    {
        $this->assertBalancedTag($this->renderRunDetail(), 'section');
    }

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
        $this->assertBalancedTag($this->renderExtensionDetail(), 'div');
    }

    public function testExtensionDetailRendersEmpty(): void
    {
        $html = $this->renderTemplate('extension-detail.twig', $this->makeExtensionDetailContext([]));

        $this->assertStringContainsString('No findings', $html);
    }

    public function testRunPlanPageRenders(): void
    {
        $html = $this->renderTemplate('run-plan.twig', [
            'project' => ['id' => 1, 'name' => 'test-project'],
            'run' => $this->makeRun(),
            'summary' => $this->makeSummary([
                'by_severity' => ['breaking' => 10, 'warning' => 4],
            ]),
            'plan' => [
                [
                    'machine_name' => 'custom_core_api',
                    'label' => 'Core API',
                    'type' => 'module',
                    'path' => 'modules/custom/custom_core_api',
                    'dependencies' => [],
                    'dependents' => ['custom_ecommerce'],
                    'match_count' => 10,
                    'by_severity' => ['breaking' => 10],
                ],
                [
                    'machine_name' => 'custom_theme',
                    'label' => 'Custom Theme',
                    'type' => 'theme',
                    'path' => 'themes/custom/custom_theme',
                    'dependencies' => ['custom_ecommerce'],
                    'dependents' => [],
                    'match_count' => 0,
                    'by_severity' => [],
                ],
            ],
        ]);

        $this->assertStringContainsString('Upgrade Plan', $html);
        $this->assertStringContainsString('Recommended Upgrade Path', $html);
        $this->assertStringContainsString('custom_core_api', $html);
        $this->assertStringContainsString('custom_ecommerce', $html);
        $this->assertStringContainsString('10 break', $html);
        $this->assertStringContainsString('Clean', $html);
        $this->assertStringContainsString('/runs/1/extensions/modules%2Fcustom%2Fcustom_core_api', $html);
        $this->assertStringNotContainsString('/runs/1/extensions/themes%2Fcustom%2Fcustom_theme', $html);
    }
}
