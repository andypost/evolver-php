<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Indexer\Extractor\YAMLExtractor;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

class YAMLExtractorTest extends TestCase
{
    private ?Parser $parser = null;
    private ?YAMLExtractor $extractor = null;

    protected function setUp(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
        }

        putenv('EVOLVER_USE_CLI=0');
        putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');

        try {
            $this->parser = new Parser();
            $this->extractor = new YAMLExtractor($this->parser->registry());
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Tree-sitter parser unavailable: ' . $e->getMessage());
        }
    }

    public function testExtractsInfoFileWithSemanticMetadata(): void
    {
        $symbols = $this->extract(<<<'YAML'
name: Example module
type: module
description: Example description
dependencies:
  - drupal:block
  - drupal:node (>=11)
configure: example.settings
libraries-extend:
  core/drupal.dialog:
    - example/dialog
YAML, 'modules/custom/example/example.info.yml');

        $this->assertCount(1, $symbols);
        $symbol = $symbols[0];
        $this->assertSame('module_info', $symbol['symbol_type']);
        $this->assertSame('example', $symbol['fqn']);
        $this->assertSame('example', $symbol['name']);

        $signature = json_decode((string) $symbol['signature_json'], true);
        $metadata = json_decode((string) $symbol['metadata_json'], true);

        $this->assertSame('module', $signature['type']);
        $this->assertSame(['drupal:block', 'drupal:node (>=11)'], $signature['dependencies']);
        $this->assertSame('example.settings', $metadata['configure_route']);
        $this->assertSame(['block', 'node'], $metadata['dependency_targets']);
        $this->assertContains('core', $metadata['mentioned_extensions']);
        $this->assertContains('example', $metadata['mentioned_extensions']);
    }

    public function testExtractsLinksMenuEntriesWithRouteMetadata(): void
    {
        $symbols = $this->extract(<<<'YAML'
block.admin_display:
  title: 'Block layout'
  description: 'Manage blocks'
  parent: system.admin_structure
  route_name: block.admin_display
YAML, 'core/modules/block/block.links.menu.yml');

        $this->assertCount(1, $symbols);
        $symbol = $symbols[0];
        $this->assertSame('link_menu', $symbol['symbol_type']);
        $this->assertSame('block.admin_display', $symbol['fqn']);

        $metadata = json_decode((string) $symbol['metadata_json'], true);
        $this->assertSame('block.admin_display', $metadata['route_name']);
        $this->assertSame('system.admin_structure', $metadata['parent']);
        $this->assertSame(['block.admin_display'], $metadata['route_refs']);
    }

    public function testExtractsConfigExportAndSkipsCompareNoiseKeys(): void
    {
        $symbols = $this->extract(<<<'YAML'
uuid: 00000000-0000-0000-0000-000000000000
langcode: en
_core:
  default_config_hash: abc123
status: true
dependencies:
  module:
    - node
    - block
third_party_settings:
  example:
    flag: true
YAML, 'db/config/system.site.yml');

        $this->assertCount(1, $symbols);
        $symbol = $symbols[0];
        $this->assertSame('config_export', $symbol['symbol_type']);
        $this->assertSame('system.site', $symbol['fqn']);

        $signature = json_decode((string) $symbol['signature_json'], true);
        $metadata = json_decode((string) $symbol['metadata_json'], true);

        $this->assertArrayNotHasKey('uuid', $signature);
        $this->assertArrayNotHasKey('langcode', $signature);
        $this->assertArrayNotHasKey('_core', $signature);
        $this->assertSame(['block', 'node'], $signature['dependencies']['module']);
        $this->assertSame(['uuid', 'langcode', '_core.default_config_hash'], $metadata['skipped_keys']);
    }

    public function testExtractsRecipeManifestSemantics(): void
    {
        $symbols = $this->extract(<<<'YAML'
name: Standard
type: recipe
install:
  - node
  - block
recipes:
  - basic_html_format
config:
  strict: false
YAML, 'core/recipes/standard/recipe.yml');

        $this->assertCount(1, $symbols);
        $symbol = $symbols[0];
        $this->assertSame('recipe_manifest', $symbol['symbol_type']);
        $this->assertSame('standard', $symbol['fqn']);

        $signature = json_decode((string) $symbol['signature_json'], true);
        $metadata = json_decode((string) $symbol['metadata_json'], true);

        $this->assertSame(['block', 'node'], $signature['install']);
        $this->assertSame(['block', 'node'], $metadata['install']);
        $this->assertSame(['basic_html_format'], $metadata['recipes']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extract(string $source, string $filePath): array
    {
        $tree = $this->parser?->parse($source, 'yaml');
        if ($tree === null || $this->extractor === null) {
            $this->fail('Failed to initialize YAML extractor.');
        }

        return $this->extractor->extract($tree->rootNode(), $source, $filePath);
    }
}
