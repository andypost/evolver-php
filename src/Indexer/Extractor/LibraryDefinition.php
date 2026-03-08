<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

/**
 * Value object representing a Drupal library definition.
 */
final class LibraryDefinition
{
    /**
     * @param LibraryAssetEntry[] $assetEntries
     * @param string[] $dependencies
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $owner = null,
        public readonly array $assetEntries = [],
        public readonly array $dependencies = [],
        public readonly ?string $deprecationMessage = null,
        public readonly ?string $remote = null,
        public readonly ?string $version = null,
        public readonly ?string $license = null,
    ) {}

    /**
     * Create from parsed YAML data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $name, array $data, ?string $owner = null): self
    {
        $assetEntries = [];
        $dependencies = [];

        // Extract JavaScript assets
        if (isset($data['js']) && is_array($data['js'])) {
            foreach ($data['js'] as $path => $options) {
                $assetEntries[] = self::createAssetEntry($path, $options, 'javascript', $owner);
            }
        }

        // Extract CSS assets
        if (isset($data['css']) && is_array($data['css'])) {
            foreach ($data['css'] as $category => $files) {
                if (is_array($files)) {
                    foreach ($files as $path => $options) {
                        $assetEntries[] = self::createAssetEntry($path, $options, 'css', $owner, $category);
                    }
                }
            }
        }

        // Extract dependencies
        if (isset($data['dependencies']) && is_array($data['dependencies'])) {
            $dependencies = array_values(array_filter(
                array_map('trim', $data['dependencies']),
                static fn($dep): bool => $dep !== ''
            ));
        }

        return new self(
            name: $name,
            owner: $owner,
            assetEntries: $assetEntries,
            dependencies: $dependencies,
            deprecationMessage: $data['deprecated'] ?? null,
            remote: $data['remote'] ?? null,
            version: $data['version'] ?? null,
            license: $data['license'] ?? null,
        );
    }

    /**
     * Create an asset entry from YAML data.
     *
     * @param string|int $key
     * @param array<string, mixed>|string|int|float|bool|null $options
     */
    private static function createAssetEntry(
        string|int $key,
        array|string|int|float|bool|null $options,
        string $assetType,
        ?string $owner,
        ?string $cssCategory = null,
    ): LibraryAssetEntry {
        $path = is_string($key) ? $key : (string) $key;
        
        // Handle simple string path (no options)
        if (!is_array($options)) {
            return new LibraryAssetEntry(
                sourcePath: $path,
                resolvedPath: self::resolvePath($path, $owner),
                assetType: $assetType,
                internal: self::isInternal($path),
                options: $cssCategory !== null ? ['category' => $cssCategory] : null,
            );
        }

        // Handle array with options
        $internal = !str_starts_with($path, 'http://') && !str_starts_with($path, 'https://') && !str_starts_with($path, '//');
        
        return new LibraryAssetEntry(
            sourcePath: $path,
            resolvedPath: $internal ? self::resolvePath($path, $owner) : null,
            assetType: $assetType,
            internal: $internal,
            options: array_merge($options, $cssCategory !== null ? ['category' => $cssCategory] : []),
            minifiedPath: $options['minified'] ?? null,
            basePath: $options['base_path'] ?? null,
        );
    }

    /**
     * Resolve the path for an internal asset.
     */
    private static function resolvePath(string $path, ?string $owner): ?string
    {
        if ($owner === null || $owner === '') {
            return null;
        }

        // Remove leading slash if present
        $path = ltrim($path, '/');
        
        // For core modules, construct the full path
        // Example: owner="block", path="css/block.admin.css" → "core/modules/block/css/block.admin.css"
        if (str_contains($path, 'core/')) {
            // Already has core/ prefix
            return $path;
        }
        
        // Try to detect from common patterns based on owner name
        // owner is typically the module/theme name from the file path
        return "core/modules/{$owner}/{$path}";
    }

    /**
     * Check if a path is internal (not external CDN).
     */
    private static function isInternal(string $path): bool
    {
        return !str_starts_with($path, 'http://') 
            && !str_starts_with($path, 'https://') 
            && !str_starts_with($path, '//');
    }

    /**
     * Convert to array for metadata storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'owner' => $this->owner,
            'asset_entries' => array_map(fn($e) => $e->toArray(), $this->assetEntries),
            'dependencies' => $this->dependencies,
            'deprecation_message' => $this->deprecationMessage,
            'remote' => $this->remote,
            'version' => $this->version,
            'license' => $this->license,
        ];
    }

    /**
     * Get all JavaScript asset entries.
     *
     * @return LibraryAssetEntry[]
     */
    public function getJavaScriptAssets(): array
    {
        return array_values(array_filter(
            $this->assetEntries,
            static fn($e): bool => $e->isJavaScript()
        ));
    }

    /**
     * Get all CSS asset entries.
     *
     * @return LibraryAssetEntry[]
     */
    public function getCssAssets(): array
    {
        return array_values(array_filter(
            $this->assetEntries,
            static fn($e): bool => $e->isCss()
        ));
    }

    /**
     * Get all internal asset entries.
     *
     * @return LibraryAssetEntry[]
     */
    public function getInternalAssets(): array
    {
        return array_values(array_filter(
            $this->assetEntries,
            static fn($e): bool => $e->internal
        ));
    }

    /**
     * Get all external asset entries.
     *
     * @return LibraryAssetEntry[]
     */
    public function getExternalAssets(): array
    {
        return array_values(array_filter(
            $this->assetEntries,
            static fn($e): bool => !$e->internal
        ));
    }

    /**
     * Check if this library is deprecated.
     */
    public function isDeprecated(): bool
    {
        return $this->deprecationMessage !== null;
    }

    /**
     * Get dependency owners (extracted from dependency strings).
     *
     * @return string[]
     */
    public function getDependencyOwners(): array
    {
        $owners = [];
        
        foreach ($this->dependencies as $dependency) {
            if (str_contains($dependency, '/')) {
                // "core/drupal" → "core"
                $parts = explode('/', $dependency);
                $owners[] = strtolower($parts[0]);
            } else {
                // Just the owner name
                $owners[] = strtolower($dependency);
            }
        }
        
        return array_values(array_unique($owners));
    }
}
