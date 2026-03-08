<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\TreeSitter\Node;

/**
 * A fallback extractor for files without a Tree-sitter grammar (e.g. Twig).
 */
class SimpleFileExtractor implements ExtractorInterface
{
    public function extract(Node $root, string $source, string $filePath, ?string $absolutePath = null): array
    {
        // For non-AST files, we just return a single symbol representing the file itself.
        $basename = basename($filePath);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        
        return [
            [
                'fqn' => $basename,
                'name' => $basename,
                'symbol_type' => $ext . '_file',
                'line_start' => 1,
                'line_end' => substr_count($source, "\n") + 1,
                'signature_json' => json_encode(['type' => 'file']),
                'signature_hash' => hash('sha256', $source),
                'language' => $ext,
            ]
        ];
    }

    /**
     * Special method for files that don't have an AST.
     */
    public function extractWithoutRoot(string $source, string $filePath): array
    {
        $basename = basename($filePath);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        
        return [
            [
                'fqn' => $basename,
                'name' => $basename,
                'symbol_type' => $ext . '_file',
                'line_start' => 1,
                'line_end' => substr_count($source, "\n") + 1,
                'signature_json' => json_encode(['type' => 'file']),
                'signature_hash' => hash('sha256', $source),
                'language' => $ext,
            ]
        ];
    }
}
