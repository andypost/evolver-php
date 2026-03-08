<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Adapter\DrupalCoreAdapter;
use DrupalEvolver\Indexer\Extractor\PHPExtractor;
use DrupalEvolver\TreeSitter\Parser;

class PHPExtractorTest extends BaseExtractorTestCase
{
    private ?Parser $parser = null;
    private ?PHPExtractor $extractor = null;

    protected function setUp(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
        }

        putenv('EVOLVER_USE_CLI=0');
        putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');

        $this->parser = new Parser();
        $adapter = new DrupalCoreAdapter();
        $this->extractor = new PHPExtractor($this->parser->registry(), $adapter);
    }

    public function testExtractsPluginDefinitions(): void
    {
        $source = $this->getFixture('php/plugins.php');

        $tree = $this->parser->parse($source, 'php');
        $symbols = $this->extractor->extract($tree->rootNode(), $source, 'test.php');

        $pluginSymbols = array_filter($symbols, fn($s) => $s['symbol_type'] === 'plugin_definition');
        $this->assertCount(2, $pluginSymbols, 'Should find 2 plugins (one annotation, one attribute)');

        $ids = array_column($pluginSymbols, 'fqn');
        $this->assertContains('my_block', $ids);
        $this->assertContains('my_config_action', $ids);
    }

    public function testPluginDefinitionsExposeStructuredMetadataForAnnotationAndAttributeForms(): void
    {
        $source = $this->getFixture('php/plugins.php');

        $tree = $this->parser->parse($source, 'php');
        $symbols = $this->extractor->extract($tree->rootNode(), $source, 'test.php');

        $pluginSymbols = array_values(array_filter(
            $symbols,
            static fn(array $symbol): bool => $symbol['symbol_type'] === 'plugin_definition'
        ));

        $byId = [];
        foreach ($pluginSymbols as $symbol) {
            $byId[$symbol['fqn']] = json_decode((string) ($symbol['metadata_json'] ?? '{}'), true);
        }

        $this->assertSame('Block', $byId['my_block']['plugin_type'] ?? null);
        $this->assertSame('my_block', $byId['my_block']['plugin_id'] ?? null);
        $this->assertSame('ConfigAction', $byId['my_config_action']['plugin_type'] ?? null);
        $this->assertSame('my_config_action', $byId['my_config_action']['plugin_id'] ?? null);
    }

    public function testExtractsEventSubscribers(): void
    {
        $source = $this->getFixture('php/event_subscriber.php');

        $tree = $this->parser->parse($source, 'php');
        $symbols = $this->extractor->extract($tree->rootNode(), $source, 'test.php');

        $subscribers = array_filter($symbols, fn($s) => $s['symbol_type'] === 'event_subscriber');
        $this->assertCount(1, $subscribers);

        $sub = reset($subscribers);
        $meta = json_decode($sub['metadata_json'], true);
        $this->assertContains('kernel.request', $meta['events']);
        $this->assertContains('\Drupal\Core\Config\ConfigEvents::SAVE', $meta['events']);
    }
}
