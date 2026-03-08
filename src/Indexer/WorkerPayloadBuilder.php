<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer;

use DrupalEvolver\Adapter\DrupalCoreAdapter;
use DrupalEvolver\Indexer\Extractor\CSSExtractor;
use DrupalEvolver\Indexer\Extractor\DrupalLibrariesExtractor;
use DrupalEvolver\Indexer\Extractor\JSExtractor;
use DrupalEvolver\Indexer\Extractor\PHPExtractor;
use DrupalEvolver\Indexer\Extractor\YAMLExtractor;
use DrupalEvolver\Indexer\Extractor\TwigExtractor;
use DrupalEvolver\Indexer\Extractor\SimpleFileExtractor;
use DrupalEvolver\Indexer\DrupalExtensionResolver;
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
        $adapter = new DrupalCoreAdapter();
        $classifier = new FileClassifier($adapter);
        $phpExtractor = new PHPExtractor($parser->registry(), $adapter);
        $yamlExtractor = new YAMLExtractor($parser->registry());
        $jsExtractor = new JSExtractor($parser->registry());
        $cssExtractor = new CSSExtractor($parser->registry());
        $libExtractor = new DrupalLibrariesExtractor($parser->registry());
        $twigExtractor = new TwigExtractor($parser->registry());
        $simpleExtractor = new SimpleFileExtractor();

        $entries = [];
        $processedFiles = 0;

        $extensionResolver = new DrupalExtensionResolver();
        $currentDirectory = null;
        $currentSdcId = null;

        foreach ($files as $filePath) {
            $relativePath = substr($filePath, strlen($path) + 1);
            $language = $classifier->classify($filePath);
            if ($onProcessed !== null) {
                $onProcessed();
            }

            if ($language === null) {
                continue;
            }

            $directory = dirname($filePath);
            if ($directory !== $currentDirectory) {
                $currentDirectory = $directory;
                $currentSdcId = self::detectSdcId($filePath, $extensionResolver);
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
                if ($language === 'twig') {
                    $tree = $parser->parse($content, 'twig');
                    if ($tree === null) {
                        $symbols = $simpleExtractor->extractWithoutRoot($content, $filePath);
                        $root = null;
                    } else {
                        $root = $tree->rootNode();
                        $symbols = $twigExtractor->extract($root, $content, $relativePath, $filePath);
                        }
                        } else {
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

                        $symbols = $extractor->extract($root, $content, $relativePath, $filePath);                        }
                // Tag symbols with SDC context if present
                if ($currentSdcId !== null) {
                    foreach ($symbols as &$symbol) {
                        $metadata = isset($symbol['metadata_json']) ? json_decode($symbol['metadata_json'], true) : [];
                        $metadata['sdc_component'] = $currentSdcId;
                        $symbol['metadata_json'] = json_encode($metadata);
                    }
                    unset($symbol);
                }

                $entries[] = [
                    'file' => [
                        'file_path' => $relativePath,
                        'language' => $language,
                        'file_hash' => $fileHash,
                        'ast_sexp' => ($storeAst && $root) ? gzcompress($root->sexp()) : null,
                        'ast_json' => null,
                        'line_count' => substr_count($content, "\n") + 1,
                        'byte_size' => strlen($content),
                    ],
                    'symbols' => $symbols,
                ];
                $processedFiles++;

                if (isset($tree)) {
                    unset($tree);
                }
                unset($root, $content);
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

    private static function detectSdcId(string $filePath, DrupalExtensionResolver $extensionResolver): ?string
    {
        $directory = dirname($filePath);
        $componentFiles = glob($directory . '/*.component.yml');
        
        if (empty($componentFiles)) {
            return null;
        }

        $componentName = basename($componentFiles[0], '.component.yml');
        $extension = $extensionResolver->resolve($filePath);

        return $extension ? "{$extension}:{$componentName}" : $componentName;
    }
}
