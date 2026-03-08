<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\TreeSitter\Node;

interface ExtractorInterface
{
    /**
     * Extract symbols from a parsed AST.
     *
     * @return array<int, array<string, mixed>> Array of symbol data arrays
     */
    public function extract(Node $root, string $source, string $filePath, ?string $absolutePath = null): array;
}
