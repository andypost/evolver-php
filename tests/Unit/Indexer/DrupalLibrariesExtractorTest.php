<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Indexer\Extractor\DrupalLibrariesExtractor;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

final class DrupalLibrariesExtractorTest extends TestCase
{
    private ?Parser $parser = null;
    private ?DrupalLibrariesExtractor $extractor = null;

    protected function setUp(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
        }

        putenv('EVOLVER_USE_CLI=0');
        putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');

        try {
            $this->parser = new Parser();
            $this->extractor = new DrupalLibrariesExtractor($this->parser->registry());
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Tree-sitter parser unavailable: ' . $e->getMessage());
        }
    }

    public function testExtractsDrupalLibraryAssetMetadata(): void
    {
        $symbols = $this->extract(<<<'YAML'
drupal.block.admin:
  version: VERSION
  js:
    js/block.admin.js: {}
  css:
    theme:
      css/block.admin.css: {}
  dependencies:
    - core/drupal
    - core/once
YAML, 'core/modules/block/block.libraries.yml');

        $this->assertCount(1, $symbols);
        $symbol = $symbols[0];
        $this->assertSame('drupal_libraries', $symbol['language']);
        $this->assertSame('drupal_library', $symbol['symbol_type']);
        $this->assertSame('drupal.block.admin', $symbol['fqn']);

        $signature = json_decode((string) $symbol['signature_json'], true);
        $metadata = json_decode((string) $symbol['metadata_json'], true);

        $this->assertSame(['core/drupal', 'core/once'], $signature['dependencies']);
        $this->assertSame('block', $metadata['owner']);
        $this->assertSame(['core/modules/block/css/block.admin.css', 'core/modules/block/js/block.admin.js'], $metadata['asset_paths']);
        $this->assertSame(['core/modules/block/js/block.admin.js'], $metadata['javascript_assets']);
        $this->assertSame(['core/modules/block/css/block.admin.css'], $metadata['css_assets']);
        $this->assertSame(['core'], $metadata['dependency_owners']);
    }

    public function testExtractsLibraryDeprecationMetadata(): void
    {
        $symbols = $this->extract(<<<'YAML'
deprecated.library:
  deprecated: 'The "%library_id%" asset library is deprecated in drupal:10.1.0 and is removed from drupal:11.0.0. Use another library instead.'
  js:
    js/legacy.js: {}
YAML, 'modules/custom/example/example.libraries.yml');

        $this->assertCount(1, $symbols);
        $symbol = $symbols[0];
        $this->assertSame(1, $symbol['is_deprecated']);
        $this->assertSame('10.1.0', $symbol['deprecation_version']);
        $this->assertSame('11.0.0', $symbol['removal_version']);
        $this->assertStringContainsString('deprecated in drupal:10.1.0', (string) $symbol['deprecation_message']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extract(string $source, string $filePath): array
    {
        $tree = $this->parser?->parse($source, 'drupal_libraries');
        if ($tree === null || $this->extractor === null) {
            $this->fail('Failed to initialize Drupal libraries extractor.');
        }

        return $this->extractor->extract($tree->rootNode(), $source, $filePath);
    }
}
