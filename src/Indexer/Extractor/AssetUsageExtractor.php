<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

/**
 * Extracts library usage (attachments) from PHP and Twig files via regex.
 *
 * Finds:
 * - #attached['library'] arrays in PHP render arrays
 * - {{ attach_library('module/library') }} calls in Twig templates
 *
 * This extractor uses regex rather than tree-sitter because the patterns
 * are simple string literals that don't need AST context.
 */
final class AssetUsageExtractor
{
    private const string SYMBOL_TYPE = 'library_usage';

    /**
     * Extract library usage symbols from file content.
     *
     * @return list<array<string, mixed>>
     */
    public function extract(string $filePath, string $source): array
    {
        if (str_ends_with($filePath, '.php') || str_ends_with($filePath, '.module')
            || str_ends_with($filePath, '.inc') || str_ends_with($filePath, '.install')
            || str_ends_with($filePath, '.theme') || str_ends_with($filePath, '.profile')) {
            return $this->extractFromPhp($filePath, $source);
        }

        if (str_ends_with($filePath, '.twig')) {
            return $this->extractFromTwig($filePath, $source);
        }

        return [];
    }

    /**
     * Extract library attachments from PHP files.
     *
     * Matches patterns like:
     *   '#attached' => ['library' => ['module/library_name']]
     *   $build['#attached']['library'][] = 'module/library_name';
     *
     * @return list<array<string, mixed>>
     */
    private function extractFromPhp(string $filePath, string $source): array
    {
        $symbols = [];

        // Match library names in #attached context
        // Pattern: string literals that look like 'module/library' near #attached
        if (preg_match_all(
            '/[\'"]#attached[\'"]\s*(?:\]?\s*(?:=>|\[)).*?[\'"]library[\'"]\s*(?:\]?\s*(?:=>|\[)).*?[\'"]([\w_]+\/[\w_.-]+)[\'"]/s',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches[1] as [$libraryName, $offset]) {
                $lineStart = substr_count($source, "\n", 0, $offset) + 1;
                $symbols[] = $this->createSymbol($filePath, $libraryName, 'php_attached', $lineStart, $offset, $offset + strlen($libraryName));
            }
        }

        // Also match simpler inline patterns: 'module/library' in library arrays
        // This catches cases the complex regex above might miss
        if (preg_match_all(
            '/\[\'library\'\]\s*\[\s*\]\s*=\s*[\'"]([\w_]+\/[\w_.-]+)[\'"]/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches[1] as [$libraryName, $offset]) {
                $lineStart = substr_count($source, "\n", 0, $offset) + 1;
                $symbols[] = $this->createSymbol($filePath, $libraryName, 'php_attached', $lineStart, $offset, $offset + strlen($libraryName));
            }
        }

        return $this->deduplicateSymbols($symbols);
    }

    /**
     * Extract library attachments from Twig files.
     *
     * Matches: {{ attach_library('module/library_name') }}
     *
     * @return list<array<string, mixed>>
     */
    private function extractFromTwig(string $filePath, string $source): array
    {
        $symbols = [];

        if (preg_match_all(
            '/attach_library\s*\(\s*[\'"]([\w_]+\/[\w_.-]+)[\'"]\s*\)/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches[1] as [$libraryName, $offset]) {
                $lineStart = substr_count($source, "\n", 0, $offset) + 1;
                $symbols[] = $this->createSymbol($filePath, $libraryName, 'twig_attach', $lineStart, $offset, $offset + strlen($libraryName));
            }
        }

        return $symbols;
    }

    /**
     * @return array<string, mixed>
     */
    private function createSymbol(
        string $filePath,
        string $libraryName,
        string $attachmentType,
        int $lineStart,
        int $byteStart,
        int $byteEnd,
    ): array {
        $language = str_ends_with($filePath, '.twig') || str_ends_with($filePath, '.html.twig') ? 'twig' : 'php';

        return [
            'language' => $language,
            'symbol_type' => self::SYMBOL_TYPE,
            'fqn' => 'library_usage:' . $libraryName,
            'name' => $libraryName,
            'namespace' => null,
            'parent_symbol' => null,
            'signature_hash' => hash('sha256', "library_usage|{$libraryName}|{$filePath}|{$byteStart}"),
            'signature_json' => json_encode([
                'library_name' => $libraryName,
                'attachment_type' => $attachmentType,
            ]),
            'metadata_json' => json_encode([
                'library_name' => $libraryName,
                'attachment_type' => $attachmentType,
                'owner' => strstr($libraryName, '/', true) ?: $libraryName,
            ]),
            'source_text' => $libraryName,
            'line_start' => $lineStart,
            'line_end' => $lineStart,
            'byte_start' => $byteStart,
            'byte_end' => $byteEnd,
        ];
    }

    /**
     * Remove duplicate library references in the same file.
     *
     * @param list<array<string, mixed>> $symbols
     * @return list<array<string, mixed>>
     */
    private function deduplicateSymbols(array $symbols): array
    {
        $seen = [];
        $result = [];

        foreach ($symbols as $symbol) {
            $key = $symbol['fqn'] . '|' . $symbol['byte_start'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $symbol;
        }

        return $result;
    }
}
