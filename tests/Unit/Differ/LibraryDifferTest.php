<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Differ;

use DrupalEvolver\Differ\LibraryDiffer;
use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

class LibraryDifferTest extends TestCase
{
    private DatabaseApi $api;
    private LibraryDiffer $differ;

    protected function setUp(): void
    {
        $this->api = new DatabaseApi(':memory:');
        $this->differ = new LibraryDiffer($this->api);
    }

    public function testDetectsLibraryRemoval(): void
    {
        [$fromId, $toId] = $this->seedVersions();
        $fromFile = $this->api->files()->create($fromId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h1', null, null, 10, 100);
        $toFile = $this->api->files()->create($toId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h2', null, null, 10, 100);

        $this->createLibrarySymbol($fromId, $fromFile, 'drupal.ajax', ['css_assets' => ['misc/ajax.css']]);
        $this->createLibrarySymbol($toId, $toFile, 'drupal.dialog', ['css_assets' => ['misc/dialog.css']]);

        $changes = $this->differ->diffLibraries($fromId, $toId);

        $removed = array_filter($changes, fn($c) => $c['change_type'] === 'library_removed');
        $this->assertCount(1, $removed);
        $change = reset($removed);
        $this->assertSame('drupal.ajax', $change['old_fqn']);
        $this->assertSame('breaking', $change['severity']);
    }

    public function testDetectsLibraryAddition(): void
    {
        [$fromId, $toId] = $this->seedVersions();
        $fromFile = $this->api->files()->create($fromId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h1', null, null, 10, 100);
        $toFile = $this->api->files()->create($toId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h2', null, null, 10, 100);

        $this->createLibrarySymbol($fromId, $fromFile, 'drupal.ajax', []);
        $this->createLibrarySymbol($toId, $toFile, 'drupal.ajax', []);
        $this->createLibrarySymbol($toId, $toFile, 'drupal.new_feature', []);

        $changes = $this->differ->diffLibraries($fromId, $toId);

        $added = array_filter($changes, fn($c) => $c['change_type'] === 'library_added');
        $this->assertCount(1, $added);
        $change = reset($added);
        $this->assertSame('drupal.new_feature', $change['new_fqn']);
    }

    public function testDetectsCssAssetRemoval(): void
    {
        [$fromId, $toId] = $this->seedVersions();
        $fromFile = $this->api->files()->create($fromId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h1', null, null, 10, 100);
        $toFile = $this->api->files()->create($toId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h2', null, null, 10, 100);

        $this->createLibrarySymbol($fromId, $fromFile, 'drupal.ajax', ['css_assets' => ['misc/ajax.css', 'misc/extra.css']]);
        $this->createLibrarySymbol($toId, $toFile, 'drupal.ajax', ['css_assets' => ['misc/ajax.css']]);

        $changes = $this->differ->diffLibraries($fromId, $toId);

        $cssRemoved = array_filter($changes, fn($c) => $c['change_type'] === 'library_css_removed');
        $this->assertCount(1, $cssRemoved);
        $this->assertSame('breaking', reset($cssRemoved)['severity']);
    }

    public function testDetectsJsAssetRemoval(): void
    {
        [$fromId, $toId] = $this->seedVersions();
        $fromFile = $this->api->files()->create($fromId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h1', null, null, 10, 100);
        $toFile = $this->api->files()->create($toId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h2', null, null, 10, 100);

        $this->createLibrarySymbol($fromId, $fromFile, 'drupal.ajax', ['javascript_assets' => ['misc/ajax.js']]);
        $this->createLibrarySymbol($toId, $toFile, 'drupal.ajax', ['javascript_assets' => []]);

        $changes = $this->differ->diffLibraries($fromId, $toId);

        $jsRemoved = array_filter($changes, fn($c) => $c['change_type'] === 'library_js_removed');
        $this->assertCount(1, $jsRemoved);
    }

    public function testDetectsDependencyRemoval(): void
    {
        [$fromId, $toId] = $this->seedVersions();
        $fromFile = $this->api->files()->create($fromId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h1', null, null, 10, 100);
        $toFile = $this->api->files()->create($toId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h2', null, null, 10, 100);

        $this->createLibrarySymbol($fromId, $fromFile, 'drupal.ajax', ['dependency_libraries' => ['core/jquery', 'core/drupalSettings']]);
        $this->createLibrarySymbol($toId, $toFile, 'drupal.ajax', ['dependency_libraries' => ['core/drupalSettings']]);

        $changes = $this->differ->diffLibraries($fromId, $toId);

        $depRemoved = array_filter($changes, fn($c) => $c['change_type'] === 'library_dependency_removed');
        $this->assertCount(1, $depRemoved);
        $this->assertSame('warning', reset($depRemoved)['severity']);
    }

    public function testDetectsNewDeprecation(): void
    {
        [$fromId, $toId] = $this->seedVersions();
        $fromFile = $this->api->files()->create($fromId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h1', null, null, 10, 100);
        $toFile = $this->api->files()->create($toId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h2', null, null, 10, 100);

        $this->createLibrarySymbol($fromId, $fromFile, 'drupal.ajax', []);
        $this->createLibrarySymbol($toId, $toFile, 'drupal.ajax', [], deprecated: true, deprecationMessage: 'Use drupal.once instead');

        $changes = $this->differ->diffLibraries($fromId, $toId);

        $deprecated = array_filter($changes, fn($c) => $c['change_type'] === 'library_deprecated');
        $this->assertCount(1, $deprecated);
        $this->assertSame('deprecation', reset($deprecated)['severity']);
    }

    public function testNoChangesForIdenticalLibraries(): void
    {
        [$fromId, $toId] = $this->seedVersions();
        $fromFile = $this->api->files()->create($fromId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h1', null, null, 10, 100);
        $toFile = $this->api->files()->create($toId, 'core/misc/misc.libraries.yml', 'drupal_libraries', 'h2', null, null, 10, 100);

        $this->createLibrarySymbol($fromId, $fromFile, 'drupal.ajax', ['css_assets' => ['misc/ajax.css'], 'javascript_assets' => ['misc/ajax.js']]);
        $this->createLibrarySymbol($toId, $toFile, 'drupal.ajax', ['css_assets' => ['misc/ajax.css'], 'javascript_assets' => ['misc/ajax.js']]);

        $changes = $this->differ->diffLibraries($fromId, $toId);

        // Should have no breaking/removal changes (additions are fine)
        $breaking = array_filter($changes, fn($c) => in_array($c['change_type'], ['library_removed', 'library_css_removed', 'library_js_removed'], true));
        $this->assertCount(0, $breaking);
    }

    /**
     * @return array{int, int}
     */
    private function seedVersions(): array
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);
        return [$fromId, $toId];
    }

    private function createLibrarySymbol(
        int $versionId,
        int $fileId,
        string $name,
        array $metadata,
        bool $deprecated = false,
        ?string $deprecationMessage = null,
    ): int {
        return $this->api->symbols()->create([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'drupal_libraries',
            'symbol_type' => 'drupal_library',
            'fqn' => $name,
            'name' => $name,
            'signature_hash' => hash('sha256', "drupal_library|{$name}|{$versionId}"),
            'metadata_json' => json_encode(array_merge([
                'file_kind' => 'drupal_library',
                'css_assets' => [],
                'javascript_assets' => [],
                'dependency_libraries' => [],
            ], $metadata)),
            'is_deprecated' => $deprecated ? 1 : 0,
            'deprecation_message' => $deprecationMessage,
        ]);
    }
}
