<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Web;

final class MatchItemRenderTest extends UiTestCase
{
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

        $this->assertStringContainsString('data-category="modernization"', $html);
    }
}
