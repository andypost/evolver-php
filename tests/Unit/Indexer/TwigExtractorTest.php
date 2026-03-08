<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Indexer\Extractor\TwigExtractor;
use DrupalEvolver\TreeSitter\Parser;

class TwigExtractorTest extends BaseExtractorTestCase
{
    private ?Parser $parser = null;
    private ?TwigExtractor $extractor = null;

    protected function setUp(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
        }

        putenv('EVOLVER_USE_CLI=0');
        putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');

        $this->parser = new Parser();
        $this->parser->registry()->loadLanguage('twig');
        $this->extractor = new TwigExtractor($this->parser->registry());
    }

    public function testExtractsTagsAndVariables(): void
    {
        $source = <<<'TWIG'
<div class="{{ classes }}">
    {% extends "base.html.twig" %}
    {% include "@core/blocks.html.twig" %}
    {{ block('content') }}
    {% if status %}
        <p>{{ message|upper }}</p>
    {% endif %}
</div>
TWIG;

        $symbols = $this->extractSymbols($source, 'test.html.twig');

        $tags = array_filter($symbols, fn($s) => $s['symbol_type'] === 'twig_tag' || $s['symbol_type'] === 'twig_symbol');
        $this->assertNotEmpty($tags);

        $vars = array_filter($symbols, fn($s) => $s['symbol_type'] === 'twig_variable');
        $this->assertNotEmpty($vars);
        $names = array_column($vars, 'fqn');
        $this->assertContains('classes', $names);
        $this->assertContains('message', $names);

        $filters = array_filter($symbols, fn($s) => $s['symbol_type'] === 'twig_filter');
        $this->assertNotEmpty($filters);
        $this->assertContains('upper', array_column($filters, 'fqn'));
    }

    public function testExtractsSdcCalls(): void
    {
        $source = $this->getFixture('twig/sdc_example.html.twig');

        $symbols = $this->extractSymbols($source, 'component.html.twig');

        $sdcIncludes = array_filter($symbols, fn($s) => $s['symbol_type'] === 'sdc_include');
        $this->assertCount(1, $sdcIncludes);
        $this->assertEquals('my_module:icon', reset($sdcIncludes)['fqn']);

        $sdcEmbeds = array_filter($symbols, fn($s) => $s['symbol_type'] === 'sdc_embed');
        $this->assertCount(1, $sdcEmbeds);
        $this->assertEquals('my_module:card', reset($sdcEmbeds)['fqn']);

        $sdcFunctions = array_filter($symbols, fn($s) => $s['symbol_type'] === 'sdc_function');
        $this->assertCount(1, $sdcFunctions);
        $this->assertEquals('my_module:button', reset($sdcFunctions)['fqn']);
    }

    public function testParsesProjectTemplates(): void
    {
        $source = '{% include "example.html.twig" %}{{ var }}';
        $symbols = $this->extractSymbols($source, 'example.twig');
        
        $this->assertNotEmpty($symbols);
        $fqns = array_column($symbols, 'fqn');
        $this->assertContains('example.html.twig', $fqns);
        $this->assertContains('var', $fqns);
    }

    private function extractSymbols(string $source, string $filePath): array
    {
        $tree = $this->parser->parse($source, 'twig');
        if ($tree === null || $this->extractor === null) {
            $this->fail('Failed to initialize Twig extractor.');
        }

        return $this->extractor->extract($tree->rootNode(), $source, $filePath);
    }
}
