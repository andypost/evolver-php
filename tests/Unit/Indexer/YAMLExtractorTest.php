<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Indexer\Extractor\YAMLExtractor;
use DrupalEvolver\TreeSitter\Parser;

class YAMLExtractorTest extends BaseExtractorTestCase
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

        $this->parser = new Parser();
        $this->extractor = new YAMLExtractor($this->parser->registry());
    }

    public function testExtractsServices(): void
    {
        $source = $this->getFixture('yaml/services.yml');
        $symbols = $this->extractSymbols($source, 'my_module.services.yml');

        $services = array_filter($symbols, fn($s) => $s['symbol_type'] === 'service');
        $this->assertCount(1, $services);
        $service = reset($services);
        $this->assertEquals('my_module.example', $service['fqn']);
        
        $meta = json_decode($service['metadata_json'], true);
        $this->assertEquals('Drupal\my_module\Example', $meta['class']);
    }

    public function testExtractsLibraries(): void
    {
        $source = $this->getFixture('yaml/libraries.yml');
        $symbols = $this->extractSymbols($source, 'my_module.libraries.yml');

        $libs = array_filter($symbols, fn($s) => $s['symbol_type'] === 'drupal_library');
        $this->assertCount(1, $libs);
        $lib = reset($libs);
        $this->assertEquals('global', $lib['fqn']);

        $meta = json_decode($lib['metadata_json'], true);
        $this->assertContains('css/style.css', $meta['asset_paths']);
        $this->assertContains('js/script.js', $meta['asset_paths']);
    }

    public function testExtractsRoutes(): void
    {
        $source = $this->getFixture('yaml/routing.yml');
        $symbols = $this->extractSymbols($source, 'my_module.routing.yml');

        $routes = array_filter($symbols, fn($s) => $s['symbol_type'] === 'drupal_route');
        $this->assertCount(1, $routes);
        $route = reset($routes);
        $this->assertEquals('my_module.example', $route['fqn']);

        $meta = json_decode($route['metadata_json'], true);
        $this->assertEquals('/example/{param}', $meta['path']);
        $this->assertStringContainsString('ExampleController', $meta['controller']);
        $this->assertNotNull($meta['defaults']);
    }

    public function testExtractsPermissions(): void
    {
        $source = $this->getFixture('yaml/permissions.yml');
        $symbols = $this->extractSymbols($source, 'my_module.permissions.yml');

        $permissions = array_filter($symbols, fn($s) => $s['symbol_type'] === 'drupal_permission');
        $this->assertCount(2, $permissions);

        $ids = array_column($permissions, 'fqn');
        $this->assertContains('access example content', $ids);
        $this->assertContains('administer example settings', $ids);

        $accessPerm = array_filter($permissions, fn($p) => $p['fqn'] === 'access example content');
        $this->assertCount(1, $accessPerm);
        $perm = reset($accessPerm);
        $meta = json_decode($perm['metadata_json'], true);
        $this->assertEquals('Access example content', $meta['title']);
        $this->assertTrue($meta['restrict_access']);
    }

    private function extractSymbols(string $source, string $filePath): array
    {
        $tree = $this->parser->parse($source, 'yaml');
        return $this->extractor->extract($tree->rootNode(), $source, $filePath);
    }
}
