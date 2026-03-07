<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

final class DrupalYamlSemanticExtractor
{
    public function extract(string $source, string $filePath): ?array
    {
        if (!$this->supports($filePath)) {
            return null;
        }

        try {
            $data = Yaml::parse($source, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException) {
            return null;
        }

        if (!is_array($data)) {
            $data = [];
        }

        $symbolType = $this->resolveSymbolType($filePath, $data);

        return match ($symbolType) {
            'module_info', 'theme_info', 'profile_info', 'theme_engine_info' => [
                $this->buildInfoSymbol($symbolType, $data, $source, $filePath),
            ],
            'link_menu', 'link_task', 'link_action', 'link_contextual' => $this->buildLinkSymbols($symbolType, $data, $source),
            'config_export' => [
                $this->buildConfigExportSymbol($data, $source, $filePath),
            ],
            'recipe_manifest' => [
                $this->buildRecipeManifestSymbol($data, $source, $filePath),
            ],
            default => null,
        };
    }

    private function supports(string $filePath): bool
    {
        $path = $this->normalizePath($filePath);
        $basename = basename($path);

        if (str_ends_with($basename, '.info.yml')) {
            return true;
        }

        if (preg_match('/\.links\.(menu|task|action|contextual)\.yml$/', $basename)) {
            return true;
        }

        if ($basename === 'recipe.yml') {
            return true;
        }

        return $this->isConfigExportPath($path);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveSymbolType(string $filePath, array $data): ?string
    {
        $path = $this->normalizePath($filePath);
        $basename = basename($path);

        if (str_ends_with($basename, '.info.yml')) {
            return match (strtolower((string) ($data['type'] ?? 'module'))) {
                'theme' => 'theme_info',
                'profile' => 'profile_info',
                'theme_engine' => 'theme_engine_info',
                default => 'module_info',
            };
        }

        if (preg_match('/\.links\.(menu|task|action|contextual)\.yml$/', $basename, $matches)) {
            return 'link_' . $matches[1];
        }

        if ($basename === 'recipe.yml') {
            return 'recipe_manifest';
        }

        if ($this->isConfigExportPath($path)) {
            return 'config_export';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildInfoSymbol(string $symbolType, array $data, string $source, string $filePath): array
    {
        $extensionId = basename(basename($filePath), '.info.yml');
        $normalized = $this->normalizeValue($data);
        $dependencies = $this->extractStringList($data['dependencies'] ?? []);
        $dependencyTargets = $this->extractDependencyTargets($dependencies);
        $metadata = [
            'file_kind' => $symbolType,
            'label' => $this->stringOrNull($data['name'] ?? null),
            'declared_type' => strtolower((string) ($data['type'] ?? 'module')),
            'dependencies' => $dependencies,
            'dependency_targets' => $dependencyTargets,
            'configure_route' => $this->stringOrNull($data['configure'] ?? null),
            'base_theme' => $this->stringOrNull($data['base theme'] ?? null),
            'mentioned_extensions' => $this->collectInfoMentionedExtensions($data, $dependencyTargets),
        ];

        return $this->buildSymbol($symbolType, $extensionId, $source, $normalized, $metadata);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function buildLinkSymbols(string $symbolType, array $data, string $source): array
    {
        $symbols = [];

        foreach ($data as $name => $entry) {
            if (!is_string($name)) {
                continue;
            }

            $normalized = $this->normalizeValue(is_array($entry) ? $entry : ['value' => $entry]);
            $entryArray = is_array($entry) ? $entry : [];
            $metadata = [
                'file_kind' => $symbolType,
                'title' => $this->stringOrNull($entryArray['title'] ?? null),
                'description' => $this->stringOrNull($entryArray['description'] ?? null),
                'route_name' => $this->stringOrNull($entryArray['route_name'] ?? null),
                'base_route' => $this->stringOrNull($entryArray['base_route'] ?? null),
                'parent' => $this->stringOrNull($entryArray['parent'] ?? null),
                'parent_id' => $this->stringOrNull($entryArray['parent_id'] ?? null),
                'appears_on' => $this->extractStringList($entryArray['appears_on'] ?? []),
                'group' => $this->stringOrNull($entryArray['group'] ?? null),
                'route_refs' => $this->uniqueStrings(array_merge(
                    $this->extractStringList([$entryArray['route_name'] ?? null, $entryArray['base_route'] ?? null]),
                    $this->extractStringList($entryArray['appears_on'] ?? [])
                )),
            ];

            $symbols[] = $this->buildSymbol($symbolType, $name, $source, $normalized, $metadata);
        }

        return $symbols;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildConfigExportSymbol(array $data, string $source, string $filePath): array
    {
        $configName = basename(basename($filePath), '.yml');
        $normalized = $this->normalizeConfigExportData($data);
        $dependencies = $normalized['dependencies'] ?? [];
        $metadata = [
            'file_kind' => 'config_export',
            'top_level_keys' => array_keys($normalized),
            'dependency_modules' => $this->extractStringList($dependencies['module'] ?? []),
            'dependency_themes' => $this->extractStringList($dependencies['theme'] ?? []),
            'dependency_config' => $this->extractStringList($dependencies['config'] ?? []),
            'skipped_keys' => ['uuid', 'langcode', '_core.default_config_hash'],
        ];

        return $this->buildSymbol('config_export', $configName, $source, $normalized, $metadata);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildRecipeManifestSymbol(array $data, string $source, string $filePath): array
    {
        $recipeId = basename(dirname($this->normalizePath($filePath)));
        if ($recipeId === '.' || $recipeId === '') {
            $recipeId = $this->slugify($this->stringOrNull($data['name'] ?? null) ?? 'recipe');
        }
        $normalized = $this->normalizeValue($data);
        $install = $this->extractStringList($data['install'] ?? []);
        $recipes = $this->extractStringList($data['recipes'] ?? []);
        $metadata = [
            'file_kind' => 'recipe_manifest',
            'label' => $this->stringOrNull($data['name'] ?? null),
            'recipe_type' => $this->stringOrNull($data['type'] ?? null),
            'install' => $install,
            'recipes' => $recipes,
            'mentioned_extensions' => $this->uniqueStrings($install),
        ];

        return $this->buildSymbol('recipe_manifest', $recipeId, $source, $normalized, $metadata);
    }

    /**
     * @param array<string, mixed> $signature
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function buildSymbol(string $symbolType, string $name, string $source, array $signature, array $metadata): array
    {
        $signatureJson = $this->encodeJson($signature);
        $metadataJson = $this->encodeJson($metadata);

        return [
            'language' => 'yaml',
            'symbol_type' => $symbolType,
            'fqn' => $name,
            'name' => $name,
            'signature_hash' => hash('sha256', "{$symbolType}|{$name}|{$signatureJson}"),
            'signature_json' => $signatureJson,
            'metadata_json' => $metadataJson,
            'source_text' => $this->encodeJson([$name => $signature]),
            'line_start' => 1,
            'line_end' => substr_count($source, "\n") + 1,
            'byte_start' => 0,
            'byte_end' => strlen($source),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeConfigExportData(array $data): array
    {
        unset($data['uuid'], $data['langcode']);

        if (isset($data['_core']) && is_array($data['_core'])) {
            unset($data['_core']['default_config_hash']);
            if ($data['_core'] === []) {
                unset($data['_core']);
            }
        }

        $normalized = $this->normalizeValue($data);

        return is_array($normalized) ? $normalized : [];
    }

    private function normalizeValue(mixed $value, array $path = []): mixed
    {
        if ($value instanceof TaggedValue) {
            return [
                'tag' => $value->getTag(),
                'value' => $this->normalizeValue($value->getValue(), [...$path, '@tag']),
            ];
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $normalized = [];
                foreach ($value as $item) {
                    $normalized[] = $this->normalizeValue($item, $path);
                }

                if ($this->shouldSortList($path, $normalized)) {
                    usort($normalized, static fn(mixed $a, mixed $b): int => strcmp((string) $a, (string) $b));
                }

                return $normalized;
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                if (!is_string($key) && !is_int($key)) {
                    continue;
                }

                $normalized[(string) $key] = $this->normalizeValue($item, [...$path, (string) $key]);
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

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function shouldSortList(array $path, array $values): bool
    {
        if ($values === [] || !$this->isScalarList($values)) {
            return false;
        }

        $pathKey = implode('.', $path);

        return in_array($pathKey, [
            'dependencies',
            'test_dependencies',
            'libraries',
            'install',
            'recipes',
            'appears_on',
            'permissions',
            'dependencies.module',
            'dependencies.theme',
            'dependencies.config',
        ], true) || str_starts_with($pathKey, 'libraries-extend.');
    }

    /**
     * @param array<int, mixed> $values
     */
    private function isScalarList(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_scalar($value) && $value !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $dependencyTargets
     * @return array<int, string>
     */
    private function collectInfoMentionedExtensions(array $data, array $dependencyTargets): array
    {
        $mentioned = $dependencyTargets;
        $baseTheme = $this->stringOrNull($data['base theme'] ?? null);
        if ($baseTheme !== null) {
            $mentioned[] = $baseTheme;
        }

        foreach (['libraries', 'libraries-extend', 'libraries-override'] as $key) {
            foreach ($this->extractLibraryOwners($data[$key] ?? []) as $owner) {
                $mentioned[] = $owner;
            }
        }

        return $this->uniqueStrings($mentioned);
    }

    /**
     * @return array<int, string>
     */
    private function extractLibraryOwners(mixed $value): array
    {
        $items = [];

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && str_contains($key, '/')) {
                    $items[] = strtok($key, '/');
                }

                if (is_string($item) && str_contains($item, '/')) {
                    $items[] = strtok($item, '/');
                }

                if (is_array($item)) {
                    $items = array_merge($items, $this->extractLibraryOwners($item));
                }
            }
        }

        return $this->uniqueStrings($items);
    }

    /**
     * @param array<int, string> $dependencies
     * @return array<int, string>
     */
    private function extractDependencyTargets(array $dependencies): array
    {
        $targets = [];

        foreach ($dependencies as $dependency) {
            $name = preg_replace('/\s*\(.*$/', '', trim($dependency)) ?? trim($dependency);
            if (str_contains($name, ':')) {
                $name = substr($name, strpos($name, ':') + 1);
            }

            $name = trim($name);
            if ($name !== '') {
                $targets[] = $name;
            }
        }

        return $this->uniqueStrings($targets);
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

    private function isConfigExportPath(string $filePath): bool
    {
        return (bool) preg_match('#(^|/)(db/config|config/sync)/[^/]+\.ya?ml$#', $filePath);
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? $slug;
        $slug = trim($slug, '_');

        return $slug === '' ? 'recipe' : $slug;
    }

    private function normalizePath(string $filePath): string
    {
        return str_replace('\\', '/', $filePath);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
