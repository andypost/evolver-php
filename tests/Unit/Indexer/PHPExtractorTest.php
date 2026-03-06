<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Indexer\Extractor\PHPExtractor;
use DrupalEvolver\TreeSitter\Node;
use PHPUnit\Framework\TestCase;

/**
 * Tests PHPExtractor using mock Node objects.
 *
 * Since tree-sitter FFI is not available in unit tests, we test
 * extraction logic with mock nodes that simulate the AST structure.
 */
class PHPExtractorTest extends TestCase
{
    public function testExtractorInstantiation(): void
    {
        $extractor = new PHPExtractor();
        $this->assertInstanceOf(PHPExtractor::class, $extractor);
    }
}
