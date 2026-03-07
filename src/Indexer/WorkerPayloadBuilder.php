<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer;

use DrupalEvolver\Indexer\Extractor\CSSExtractor;
use DrupalEvolver\Indexer\Extractor\DrupalLibrariesExtractor;
use DrupalEvolver\Indexer\Extractor\JSExtractor;
use DrupalEvolver\Indexer\Extractor\PHPExtractor;
use DrupalEvolver\Indexer\Extractor\YAMLExtractor;
use DrupalEvolver\TreeSitter\Parser;

final class WorkerPayloadBuilder
{
    public static function buildWithFreshParser(
        string $path,
        array $files,
        array $existingFileHashes,
        bool $storeAst,
    ): array {
        $parser = new Parser();

        try {
            return self::buildWithParser($path, $files, $existingFileHashes, $storeAst, $parser);
        } finally {
            unset($parser);
            gc_collect_cycles();
        }
    }

    public static function buildWithParser(
        string $path,
        array $files,
        array $existingFileHashes,
        bool $storeAst,
        Parser $parser,
        ?callable $onProcessed = null,
    ): array {
        $classifier = new FileClassifier();
        $phpExtractor = new PHPExtractor($parser->registry());
        $yamlExtractor = new YAMLExtractor($parser->registry());
        $jsExtractor = new JSExtractor($parser->registry());
        $cssExtractor = new CSSExtractor($parser->registry());
        $libExtractor = new DrupalLibrariesExtractor($parser->registry());

        $entries = [];
        $processedFiles = 0;

        foreach ($files as $filePath) {
            $relativePath = substr($filePath, strlen($path) + 1);
            $language = $classifier->classify($filePath);
            if ($onProcessed !== null) {
                $onProcessed();
            }

            if ($language === null) {
                continue;
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            $fileHash = hash('sha256', $content);
            if (($existingFileHashes[$relativePath] ?? null) === $fileHash) {
                continue;
            }

            try {
                $tree = $parser->parse($content, $language);
                if ($tree === null) {
                    continue;
                }

                $root = $tree->rootNode();
                $extractor = match ($language) {
                    'php' => $phpExtractor,
                    'yaml' => $yamlExtractor,
                    'javascript' => $jsExtractor,
                    'css' => $cssExtractor,
                    'drupal_libraries' => $libExtractor,
                    default => null,
                };

                if ($extractor === null) {
                    continue;
                }

                $entries[] = [
                    'file' => [
                        'file_path' => $relativePath,
                        'language' => $language,
                        'file_hash' => $fileHash,
                        'ast_sexp' => $storeAst ? gzcompress($root->sexp()) : null,
                        'ast_json' => null,
                        'line_count' => substr_count($content, "\n") + 1,
                        'byte_size' => strlen($content),
                    ],
                    'symbols' => $extractor->extract($root, $content, $relativePath),
                ];
                $processedFiles++;

                unset($tree, $root, $content);
            } catch (\Throwable $e) {
                file_put_contents('/app/.data/profiles/indexing_errors.log', "Error indexing {$filePath}: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }

        unset($phpExtractor, $yamlExtractor, $jsExtractor, $cssExtractor, $libExtractor);

        return [
            'processed_files' => $processedFiles,
            'entries' => $entries,
            'peak_mem' => memory_get_peak_usage(true),
        ];
    }
}
