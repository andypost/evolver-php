<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Web;

final class FragmentRenderTest extends UiTestCase
{
    public function testFilterSidebarRendersStandalone(): void
    {
        $html = $this->renderTemplate('fragments/filter-sidebar.twig');

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
        $html = $this->renderTemplate('fragments/filter-sidebar.twig');

        $this->assertStringContainsString('value="breaking"', $html);
        $this->assertStringContainsString('value="deprecation"', $html);
        $this->assertStringContainsString('value="modernization"', $html);

        $this->assertStringContainsString('value="Removals"', $html);
        $this->assertStringContainsString('value="Modernization"', $html);
        $this->assertStringContainsString('value="Signatures"', $html);
        $this->assertStringContainsString('value="Frontend"', $html);

        $this->assertStringContainsString('value="removed"', $html);
        $this->assertStringContainsString('value="deprecated"', $html);
        $this->assertStringContainsString('value="renamed"', $html);
        $this->assertStringContainsString('value="signature_changed"', $html);
        $this->assertStringContainsString('value="to_attribute"', $html);
    }

    public function testActiveFiltersRendersStandalone(): void
    {
        $html = $this->renderTemplate('fragments/active-filters.twig');

        $this->assertStringContainsString('id="active-filters"', $html);
        $this->assertStringContainsString('id="active-filters-list"', $html);
        $this->assertStringContainsString('Clear all', $html);
        $this->assertStringNotContainsString('view-flat', $html, 'Active filters should not contain view-flat div');
        $this->assertStringNotContainsString('match-list', $html, 'Active filters should not contain match list');
        $this->assertStringNotContainsString('code-preview', $html, 'Active filters should not contain code preview');
    }
}
