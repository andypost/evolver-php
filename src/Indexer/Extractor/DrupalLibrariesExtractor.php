<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\Symbol\SymbolType;
use DrupalEvolver\TreeSitter\LanguageRegistry;
use DrupalEvolver\TreeSitter\Node;
use DrupalEvolver\TreeSitter\Query;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class DrupalLibrariesExtractor implements ExtractorInterface
{
    private ?Query $query = null;

    public function __construct(private LanguageRegistry $registry) {}

    public function extract(Node $root, string $source, string $filePath, ?string $absolutePath = null): array
    {
        $ranges = $this->collectLibraryRanges($root, $source);

        try {
            $data = Yaml::parse($source);
        } catch (ParseException) {
            return $this->extractFallbackSymbols($ranges, $source);
        }

        if (!is_array($data)) {
            return $this->extractFallbackSymbols($ranges, $source);
        }

        $symbols = [];
        $owner = $this->resolveLibraryOwner($filePath);

        foreach ($data as $libraryName => $definition) {
            if (!is_string($libraryName) || $libraryName === '') {
                continue;
            }

            $definitionArray = is_array($definition) ? $definition : ['value' => $definition];
            $normalized = $this->normalizeValue($definitionArray);
            if (!is_array($normalized)) {
                $normalized = ['value' => $normalized];
            }

            $assetEntries = $this->extractAssetEntries($definitionArray, $filePath);
            $javascriptAssets = [];
            $cssAssets = [];
            $assetPaths = [];
            $externalAssets = [];

            foreach ($assetEntries as $entry) {
                if (($entry['internal'] ?? false) === true) {
                    $assetPaths[] = (string) $entry['resolved_path'];
                    if (($entry['asset_type'] ?? null) === 'javascript') {
                        $javascriptAssets[] = (string) $entry['resolved_path'];
                    } elseif (($entry['asset_type'] ?? null) === 'css') {
                        $cssAssets[] = (string) $entry['resolved_path'];
                    }
                } else {
                    $externalAssets[] = (string) $entry['source_path'];
                }
            }

            $dependencies = $this->extractStringList($definitionArray['dependencies'] ?? []);
            $deprecationMessage = $this->resolveDeprecationMessage($definitionArray);
            $range = $ranges[$libraryName] ?? null;
            $signatureJson = $this->encodeJson($normalized);
            $metadata = [
                'file_kind' => SymbolType::DrupalLibrary->value,
                'owner' => $owner,
                'asset_paths' => $this->uniqueStrings($assetPaths),
                'javascript_assets' => $this->uniqueStrings($javascriptAssets),
                'css_assets' => $this->uniqueStrings($cssAssets),
                'external_assets' => $this->uniqueStrings($externalAssets),
                'asset_entries' => $assetEntries,
                'dependency_libraries' => $dependencies,
                'dependency_owners' => $this->extractLibraryOwners($dependencies),
                'remote' => $this->stringOrNull($definitionArray['remote'] ?? null),
                'version' => $this->stringOrNull($definitionArray['version'] ?? null),
            ];

            $symbol = [
                'language' => 'drupal_libraries',
                'symbol_type' => SymbolType::DrupalLibrary->value,
                'fqn' => $libraryName,
                'name' => $libraryName,
                'signature_hash' => hash('sha256', SymbolType::DrupalLibrary->value . "|{$libraryName}|{$signatureJson}"),
                'signature_json' => $signatureJson,
                'metadata_json' => $this->encodeJson($metadata),
                'source_text' => $this->encodeJson([$libraryName => $normalized]),
                'line_start' => $range['line_start'] ?? 1,
                'line_end' => $range['line_end'] ?? (substr_count($source, "\n") + 1),
                'byte_start' => $range['byte_start'] ?? 0,
                'byte_end' => $range['byte_end'] ?? strlen($source),
            ];

            if ($deprecationMessage !== null) {
                $symbol['is_deprecated'] = 1;
                $symbol['deprecation_message'] = $deprecationMessage;

                if (preg_match('/deprecated in drupal:(\d+\.\d+\.\d+)/', $deprecationMessage, $matches)) {
                    $symbol['deprecation_version'] = $matches[1];
                }
                if (preg_match('/removed from drupal:(\d+\.\d+\.\d+)/', $deprecationMessage, $matches)) {
                    $symbol['removal_version'] = $matches[1];
                }
                if (preg_match('/deprecated in Drupal (\d+\.\d+\.\d+)/', $deprecationMessage, $matches)) {
                    $symbol['deprecation_version'] = $matches[1];
                }
                if (preg_match('/removed in Drupal (\d+\.\d+\.\d+)/', $deprecationMessage, $matches)) {
                    $symbol['removal_version'] = $matches[1];
                }
            }

            $symbols[] = $symbol;
        }

        return $symbols !== [] ? $symbols : $this->extractFallbackSymbols($ranges, $source);
    }

    /**
     * @return array<string, array{line_start: int, line_end: int, byte_start: int, byte_end: int}>
     */
    private function collectLibraryRanges(Node $root, string $source): array
    {
        $ranges = [];
        $query = $this->getQuery($root);

        foreach ($query->matches($root, $source) as $captures) {
            $keyNode = $captures['key'] ?? null;
            $valueNode = $captures['value'] ?? null;
            if ($keyNode === null || $valueNode === null) {
                continue;
            }

            $libraryName = trim($keyNode->text(), "'\" ");
            if ($libraryName === '') {
                continue;
            }

            $ranges[$libraryName] = [
                'line_start' => $keyNode->startPoint()['row'] + 1,
                'line_end' => $valueNode->endPoint()['row'] + 1,
                'byte_start' => $keyNode->startByte(),
                'byte_end' => $valueNode->endByte(),
            ];
        }

        return $ranges;
    }

    /**
     * @param array<string, array{line_start: int, line_end: int, byte_start: int, byte_end: int}> $ranges
     * @return array<int, array<string, mixed>>
     */
    private function extractFallbackSymbols(array $ranges, string $source): array
    {
        $symbols = [];

        foreach ($ranges as $libraryName => $range) {
            $signatureJson = $this->encodeJson(['type' => 'library']);
            $symbols[] = [
                'language' => 'drupal_libraries',
                'symbol_type' => SymbolType::DrupalLibrary->value,
                'fqn' => $libraryName,
                'name' => $libraryName,
                'signature_hash' => hash('sha256', SymbolType::DrupalLibrary->value . "|{$libraryName}|{$signatureJson}"),
                'signature_json' => $signatureJson,
                'metadata_json' => $this->encodeJson([
                    'file_kind' => SymbolType::DrupalLibrary->value,
                    'asset_paths' => [],
                    'javascript_assets' => [],
                    'css_assets' => [],
                    'dependency_libraries' => [],
                    'dependency_owners' => [],
                ]),
                'source_text' => $this->encodeJson([$libraryName => ['type' => 'library']]),
                'line_start' => $range['line_start'],
                'line_end' => $range['line_end'],
                'byte_start' => $range['byte_start'],
                'byte_end' => $range['byte_end'],
            ];
        }

        return $symbols;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    private function extractAssetEntries(array $definition, string $filePath): array
    {
        $entries = [];

        if (isset($definition['js']) && is_array($definition['js'])) {
            $entries = array_merge($entries, $this->collectAssetEntries($definition['js'], $filePath, 'javascript'));
        }

        if (isset($definition['css']) && is_array($definition['css'])) {
            $entries = array_merge($entries, $this->collectAssetEntries($definition['css'], $filePath, 'css'));
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function collectAssetEntries(array $node, string $filePath, string $assetType, ?string $group = null): array
    {
        $entries = [];

        foreach ($node as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if ($this->looksLikeAssetPath($key, $assetType)) {
                $entries[] = $this->buildAssetEntry($assetType, $group, $key, $value, $filePath);
                continue;
            }

            if (is_array($value)) {
                $entries = array_merge($entries, $this->collectAssetEntries($value, $filePath, $assetType, $key));
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAssetEntry(string $assetType, ?string $group, string $sourcePath, mixed $options, string $filePath): array
    {
        $resolvedPath = $this->resolveAssetPath($filePath, $sourcePath);
        $entry = [
            'asset_type' => $assetType,
            'group' => $group,
            'source_path' => trim($sourcePath),
            'resolved_path' => $resolvedPath ?? trim($sourcePath),
            'internal' => $resolvedPath !== null,
        ];

        if (is_array($options)) {
            foreach (['weight', 'minified', 'preprocess'] as $optionKey) {
                if (array_key_exists($optionKey, $options)) {
                    $entry[$optionKey] = $options[$optionKey];
                }
            }
        }

        return $entry;
    }

    private function looksLikeAssetPath(string $key, string $assetType): bool
    {
        return str_ends_with(strtolower($key), $assetType === 'css' ? '.css' : '.js')
            || str_ends_with(strtolower($key), $assetType === 'css' ? '.css.map' : '.js.map')
            || str_starts_with($key, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $key) === 1;
    }

    private function resolveAssetPath(string $filePath, string $assetPath): ?string
    {
        $assetPath = trim(str_replace('\\', '/', $assetPath));
        if ($assetPath === '' || $this->isExternalAssetPath($assetPath)) {
            return null;
        }

        if (str_starts_with($assetPath, '/')) {
            return $this->normalizeRelativePath($assetPath);
        }

        $directory = dirname(str_replace('\\', '/', $filePath));
        $combined = ($directory === '.' || $directory === '') ? $assetPath : $directory . '/' . $assetPath;

        return $this->normalizeRelativePath($combined);
    }

    private function isExternalAssetPath(string $assetPath): bool
    {
        return str_starts_with($assetPath, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $assetPath) === 1;
    }

    private function normalizeRelativePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function resolveDeprecationMessage(array $definition): ?string
    {
        $deprecated = $this->stringOrNull($definition['deprecated'] ?? null);
        if ($deprecated !== null) {
            return $deprecated;
        }

        $movedFiles = $definition['moved_files'] ?? null;
        if (is_array($movedFiles) && $movedFiles !== []) {
            return 'Library moved or has moved files: ' . $this->encodeJson($this->normalizeValue($movedFiles));
        }

        return null;
    }

    /**
     * @param array<int, string> $dependencies
     * @return array<int, string>
     */
    private function extractLibraryOwners(array $dependencies): array
    {
        $owners = [];

        foreach ($dependencies as $dependency) {
            if (!str_contains($dependency, '/')) {
                continue;
            }

            $owners[] = strtok($dependency, '/');
        }

        return $this->uniqueStrings($owners);
    }

    /**
     * @return array<int, string>
     */
    private function extractStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $string = trim((string) $item);
            if ($string !== '') {
                $items[] = $string;
            }
        }

        return $this->uniqueStrings($items);
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function uniqueStrings(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
        sort($values);

        return $values;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $normalized = [];
                foreach ($value as $item) {
                    $normalized[] = $this->normalizeValue($item);
                }

                return $normalized;
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                if (!is_string($key) && !is_int($key)) {
                    continue;
                }

                $normalized[(string) $key] = $this->normalizeValue($item);
            }

            ksort($normalized);

            return $normalized;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function resolveLibraryOwner(string $filePath): string
    {
        return basename(basename(str_replace('\\', '/', $filePath), '.libraries.yml'));
    }

    private function getQuery(Node $root): Query
    {
        if ($this->query !== null) {
            return $this->query;
        }

        $lang = $this->registry->loadLanguage('drupal_libraries');
        $pattern = '(document (block_node (block_mapping (block_mapping_pair key: (flow_node) @key value: (_) @value))))';

        $this->query = new Query($root->binding(), $pattern, $lang);

        return $this->query;
    }
}
