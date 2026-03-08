<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Indexer\Extractor\TwigExtractor;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

class TwigExtractorTest extends TestCase
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
{% extends "layout.twig" %}
{% include '@my_module/button.html.twig' with { label: 'Click me' } %}
<div class="content">
    {{ project.name }}
    {% if active %}
        {{ message|upper }}
    {% endif %}
</div>
{% component 'my_module:card' %}
    {% slot 'header' %}Title{% endslot %}
{% endcomponent %}
TWIG;

        $symbols = $this->extract($source, 'test.twig');

        $this->assertGreaterThan(0, count($symbols));

        $types = array_column($symbols, 'symbol_type');
        $names = array_column($symbols, 'name');

        $this->assertContains('twig_extends', $types);
        $this->assertContains('layout.twig', $names);

        $this->assertContains('twig_include', $types);
        $this->assertContains('@my_module/button.html.twig', $names);

        $this->assertContains('twig_variable', $types);
        $this->assertContains('project.name', $names);
        $this->assertContains('message', $names);

        $this->assertContains('sdc_call', $types);
        $this->assertContains('my_module:card', $names);
    }

    public function testExtractsSdcCallStyles(): void
    {
        $source = <<<'TWIG'
{% include 'my_module:my-button' %}
{% embed 'my_module:modal' %}{% endembed %}
{{ component('my_theme:hero', { title: 'Hello' }) }}
TWIG;

        $symbols = $this->extract($source, 'test.twig');
        $types = array_column($symbols, 'symbol_type');
        $names = array_column($symbols, 'name');

        $this->assertContains('sdc_include', $types);
        $this->assertContains('my_module:my-button', $names);

        $this->assertContains('sdc_embed', $types);
        $this->assertContains('my_module:modal', $names);

        // Function call detection might be fragile due to AST depth, 
        // but we want to track it if possible.
        $this->assertContains('sdc_function', $types);
        $this->assertContains('my_theme:hero', $names);
    }

    /**
     * Regression test: parse all project templates to ensure grammar compatibility.
     */
    public function testParseProjectTemplates(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/templates';
        $files = glob($templateDir . '/*.twig');
        $files = array_merge($files, glob($templateDir . '/**/*.twig'));

        $this->assertNotEmpty($files, "No templates found in $templateDir");

        foreach ($files as $filePath) {
            if (!is_file($filePath)) continue;
            
            $content = file_get_contents($filePath);
            $tree = $this->parser->parse($content, 'twig');
            $this->assertNotNull($tree, "Failed to parse $filePath");
            
            $symbols = $this->extractor->extract($tree->rootNode(), $content, $filePath);
            $this->assertIsArray($symbols);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extract(string $source, string $filePath): array
    {
        $tree = $this->parser?->parse($source, 'twig');
        if ($tree === null || $this->extractor === null) {
            $this->fail('Failed to initialize Twig extractor.');
        }

        return $this->extractor->extract($tree->rootNode(), $source, $filePath);
    }
}
