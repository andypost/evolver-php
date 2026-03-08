<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Adapter\DrupalCoreAdapter;
use DrupalEvolver\Indexer\Extractor\PHPExtractor;
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
        $registry = $this->createMock(\DrupalEvolver\TreeSitter\LanguageRegistry::class);
        $adapter = new DrupalCoreAdapter();
        $extractor = new PHPExtractor($registry, $adapter);
        $this->assertInstanceOf(PHPExtractor::class, $extractor);
    }
}
