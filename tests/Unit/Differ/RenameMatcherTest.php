<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Differ;

use DrupalEvolver\Differ\RenameMatcher;
use PHPUnit\Framework\TestCase;

class RenameMatcherTest extends TestCase
{
    private RenameMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new RenameMatcher();
    }

    public function testMatchesRenamedPhpFunction(): void
    {
        $removed = [[
            'id' => 10,
            'language' => 'php',
            'symbol_type' => 'function',
            'fqn' => 'Drupal\\Core\\render_old',
            'name' => 'render_old',
            'signature_json' => '{"params":[{"name":"$build","type":"array"}],"return_type":"string"}',
            'source_text' => 'function render_old(array $build): string { return implode(",", $build); }',
        ]];

        $added = [[
            'id' => 20,
            'language' => 'php',
            'symbol_type' => 'function',
            'fqn' => 'Drupal\\Core\\render_new',
            'name' => 'render_new',
            'signature_json' => '{"params":[{"name":"$build","type":"array"}],"return_type":"string"}',
            'source_text' => 'function render_new(array $build): string { return implode(",", $build); }',
        ]];

        $matches = $this->matcher->match($removed, $added);

        $this->assertCount(1, $matches);
        $this->assertSame('function_renamed', $matches[0]['change_type']);
        $this->assertSame(10, $matches[0]['old']['id']);
        $this->assertSame(20, $matches[0]['new']['id']);
        $this->assertGreaterThanOrEqual(0.70, $matches[0]['confidence']);
    }

    public function testDoesNotMatchDifferentTypes(): void
    {
        $removed = [[
            'language' => 'php',
            'symbol_type' => 'function',
            'fqn' => 'Drupal\\Core\\old_func',
            'name' => 'old_func',
            'signature_json' => '{"params":[],"return_type":null}',
            'source_text' => 'function old_func() {}',
        ]];

        $added = [[
            'language' => 'php',
            'symbol_type' => 'class',
            'fqn' => 'Drupal\\Core\\NewClass',
            'name' => 'NewClass',
            'signature_json' => '{"parent":null}',
            'source_text' => 'class NewClass {}',
        ]];

        $matches = $this->matcher->match($removed, $added);
        $this->assertSame([], $matches);
    }

    public function testSkipsNonPhpSymbols(): void
    {
        $removed = [[
            'language' => 'yaml',
            'symbol_type' => 'service',
            'fqn' => 'old.service',
            'name' => 'old.service',
            'source_text' => 'old.service: ~',
        ]];

        $added = [[
            'language' => 'yaml',
            'symbol_type' => 'service',
            'fqn' => 'new.service',
            'name' => 'new.service',
            'source_text' => 'new.service: ~',
        ]];

        $matches = $this->matcher->match($removed, $added);
        $this->assertSame([], $matches);
    }
}
