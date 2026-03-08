<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

/**
 * Value object representing a single asset entry in a library definition.
 */
final class LibraryAssetEntry
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly ?string $resolvedPath = null,
        public readonly string $assetType = 'javascript',
        public readonly bool $internal = true,
        public readonly ?array $options = null,
        public readonly ?string $minifiedPath = null,
        public readonly ?string $basePath = null,
    ) {}

    /**
     * Create from array (YAML parsed data).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $filePath, string $assetType): self
    {
        $sourcePath = $data['source_path'] ?? $data['path'] ?? '';
        $resolvedPath = $data['resolved_path'] ?? null;
        $internal = $data['internal'] ?? true;
        $options = $data['options'] ?? null;
        $minifiedPath = $data['minified'] ?? null;
        $basePath = $data['base_path'] ?? null;

        return new self(
            sourcePath: $sourcePath,
            resolvedPath: $resolvedPath,
            assetType: $assetType,
            internal: $internal,
            options: $options,
            minifiedPath: $minifiedPath,
            basePath: $basePath,
        );
    }

    /**
     * Convert to array for metadata storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'resolved_path' => $this->resolvedPath,
            'asset_type' => $this->assetType,
            'internal' => $this->internal,
            'options' => $this->options,
            'minified' => $this->minifiedPath,
            'base_path' => $this->basePath,
        ];
    }

    /**
     * Check if this is a JavaScript asset.
     */
    public function isJavaScript(): bool
    {
        return $this->assetType === 'javascript';
    }

    /**
     * Check if this is a CSS asset.
     */
    public function isCss(): bool
    {
        return $this->assetType === 'css';
    }

    /**
     * Check if this is an external asset (CDN, third-party).
     */
    public function isExternal(): bool
    {
        return !$this->internal;
    }

    /**
     * Get the full resolved path including base path.
     */
    public function getFullPath(): ?string
    {
        if ($this->resolvedPath === null) {
            return null;
        }

        return $this->basePath !== null
            ? $this->basePath . '/' . ltrim($this->resolvedPath, '/')
            : $this->resolvedPath;
    }
}
