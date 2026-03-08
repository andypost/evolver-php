<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\Symbol\SymbolType;

/**
 * Value object for SDC (Single Directory Components) component data.
 */
final class SdcComponentData
{
    public function __construct(
        public readonly string $componentId,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly string $status = 'stable',
        public readonly bool $hasTwig = false,
        public readonly bool $hasCss = false,
        public readonly bool $hasJs = false,
        public readonly array $props = [],
        public readonly array $slots = [],
        public readonly ?string $library = null,
        public readonly ?string $directory = null,
    ) {}

    /**
     * Create from parsed YAML data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $filePath, array $data): self
    {
        $componentId = basename($filePath, '.component.yml');
        $directory = dirname($filePath);

        return new self(
            componentId: $componentId,
            label: $data['name'] ?? null,
            description: $data['description'] ?? null,
            status: $data['status'] ?? 'stable',
            hasTwig: file_exists($directory . '/' . $componentId . '.twig'),
            hasCss: file_exists($directory . '/' . $componentId . '.css'),
            hasJs: file_exists($directory . '/' . $componentId . '.js'),
            props: array_keys($data['props'] ?? []),
            slots: array_keys($data['slots'] ?? []),
            library: $data['library'] ?? null,
            directory: $directory,
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
            'file_kind' => SymbolType::SdcComponent->value,
            'component_id' => $this->componentId,
            'label' => $this->label,
            'description' => $this->description,
            'status' => $this->status,
            'has_twig' => $this->hasTwig,
            'has_css' => $this->hasCss,
            'has_js' => $this->hasJs,
            'props' => $this->props,
            'slots' => $this->slots,
            'library' => $this->library,
        ];
    }

    /**
     * Check if component has any assets (twig/css/js).
     */
    public function hasAssets(): bool
    {
        return $this->hasTwig || $this->hasCss || $this->hasJs;
    }

    /**
     * Check if component has props.
     */
    public function hasProps(): bool
    {
        return !empty($this->props);
    }

    /**
     * Check if component has slots.
     */
    public function hasSlots(): bool
    {
        return !empty($this->slots);
    }

    /**
     * Get the component library reference.
     */
    public function getLibrary(): ?string
    {
        return $this->library;
    }

    /**
     * Check if component is stable (not experimental/deprecated).
     */
    public function isStable(): bool
    {
        return $this->status === 'stable';
    }
}
