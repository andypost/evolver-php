<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

/**
 * Value object for project metadata detection result.
 */
final class ProjectMetadata
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $packageName = null,
        public readonly ?string $rootName = null,
    ) {}

    /**
     * Create from detected values.
     */
    public static function create(
        ?string $type = null,
        ?string $packageName = null,
        ?string $rootName = null,
    ): self {
        return new self($type, $packageName, $rootName);
    }

    /**
     * Create from array (e.g., from info.yml parsing).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? null,
            packageName: $data['package_name'] ?? null,
            rootName: $data['root_name'] ?? null,
        );
    }

    /**
     * Convert to array for database storage.
     *
     * @return array{type: ?string, package_name: ?string, root_name: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'package_name' => $this->packageName,
            'root_name' => $this->rootName,
        ];
    }

    /**
     * Check if type is detected.
     */
    public function hasType(): bool
    {
        return $this->type !== null;
    }

    /**
     * Check if package name is detected.
     */
    public function hasPackageName(): bool
    {
        return $this->packageName !== null;
    }

    /**
     * Merge with another metadata object (this takes precedence).
     */
    public function merge(self $other): self
    {
        return new self(
            type: $this->type ?? $other->type,
            packageName: $this->packageName ?? $other->packageName,
            rootName: $this->rootName ?? $other->rootName,
        );
    }
}
