<?php

declare(strict_types=1);

namespace DrupalEvolver\TreeSitter;

use RuntimeException;

class LanguageRegistry
{
    private array $languages = [];
    private array $ffis = [];
    private string $grammarPath;

    public function __construct(?string $grammarPath = null)
    {
        $this->grammarPath = $grammarPath
            ?? ($_ENV['EVOLVER_GRAMMAR_PATH'] ?? getenv('EVOLVER_GRAMMAR_PATH') ?: '/usr/lib');
    }

    public function loadLanguage(string $name): \FFI\CData
    {
        // Map pseudo-languages to actual grammars
        $grammarName = match($name) {
            'drupal_libraries' => 'yaml',
            default => $name,
        };

        if (isset($this->languages[$name])) {
            return $this->languages[$name];
        }

        $soFile = $this->resolveGrammarLibraryPath($grammarName);

        $funcName = 'tree_sitter_' . $grammarName;

        $langFfi = \FFI::cdef(
            "typedef struct TSLanguage TSLanguage; const TSLanguage *{$funcName}(void);",
            $soFile
        );

        $this->ffis[$name] = $langFfi;
        $this->languages[$name] = $langFfi->$funcName();
        return $this->languages[$name];
    }

    public function grammarPath(): string
    {
        return $this->grammarPath;
    }

    private function resolveGrammarLibraryPath(string $name): string
    {
        $base = rtrim($this->grammarPath, '/');
        $candidates = [
            "{$base}/tree-sitter-{$name}.so",
            "{$base}/libtree-sitter-{$name}.so",
            "/usr/lib/libtree-sitter-{$name}.so",
            "/usr/lib/tree-sitter/{$name}.so",
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "Grammar file not found for {$name}. Checked: " . implode(', ', $candidates)
        );
    }
}
