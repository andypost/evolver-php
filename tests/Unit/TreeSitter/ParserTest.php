<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\TreeSitter;

use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private ?Parser $parser = null;

    protected function setUp(): void
    {
        // Skip all tests if tree-sitter FFI is not available
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
            return;
        }

        try {
            // Use FFI for tests in Docker
            putenv('EVOLVER_USE_CLI=0');
            putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');
            $this->parser = new Parser();
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'libtree-sitter.so not found')) {
                $this->markTestSkipped('tree-sitter libraries not found: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    public function testParsePhp(): void
    {
        $source = '<?php function test() {}';
        $tree = $this->parser->parse($source, 'php');
        
        $this->assertEquals('program', $tree->rootNode()->type());
        $this->assertGreaterThan(0, $tree->rootNode()->namedChildCount());
    }

    public function testParseYaml(): void
    {
        $source = 'name: Drupal';
        $tree = $this->parser->parse($source, 'yaml');
        
        $this->assertEquals('stream', $tree->rootNode()->type());
    }

    public function testNodeWalk(): void
    {
        $source = '<?php function a() {} function b() {}';
        $tree = $this->parser->parse($source, 'php');
        
        $types = [];
        $tree->rootNode()->walk(function ($node) use (&$types) {
            $types[] = $node->type();
        });
        
        $this->assertContains('function_definition', $types);
        $this->assertGreaterThan(5, count($types));
    }
}
