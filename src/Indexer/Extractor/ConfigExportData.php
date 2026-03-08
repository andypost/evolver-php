<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\Symbol\SymbolType;

/**
 * Value object for config export data.
 */
final class ConfigExportData
{
    public function __construct(
        public readonly string $configName,
        public readonly array $topLevelKeys = [],
        public readonly array $dependencies = [],
        public readonly ?string $uuid = null,
        public readonly ?string $langcode = null,
        public readonly ?string $core = null,
    ) {}

    /**
     * Create from parsed YAML data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $filePath, array $data): self
    {
        $configName = pathinfo($filePath, PATHINFO_FILENAME);
        
        // Extract dependencies from various formats
        $dependencies = [];
        if (isset($data['dependencies']) && is_array($data['dependencies'])) {
            $dependencies = self::extractDependencies($data['dependencies']);
        }

        return new self(
            configName: $configName,
            topLevelKeys: array_keys($data),
            dependencies: $dependencies,
            uuid: $data['uuid'] ?? null,
            langcode: $data['langcode'] ?? null,
            core: $data['core'] ?? null,
        );
    }

    /**
     * Extract dependencies from config export format.
     *
     * @param array<string, mixed> $deps
     * @return array<int, string>
     */
    private static function extractDependencies(array $deps): array
    {
        $result = [];
        
        // Config export format:
        // dependencies:
        //   module:
        //     - block
        //   config:
        //     - system.site
        
        foreach ($deps as $type => $items) {
            if (!is_array($items)) {
                continue;
            }
            
            foreach ($items as $item) {
                if (is_string($item) && $item !== '') {
                    $result[] = "{$type}:{$item}";
                }
            }
        }
        
        return $result;
    }

    /**
     * Convert to array for metadata storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file_kind' => SymbolType::ConfigExport->value,
            'config_name' => $this->configName,
            'top_level_keys' => $this->topLevelKeys,
            'dependencies' => $this->dependencies,
            'uuid' => $this->uuid,
            'langcode' => $this->langcode,
            'core' => $this->core,
        ];
    }

    /**
     * Get dependency modules.
     *
     * @return array<int, string>
     */
    public function getDependencyModules(): array
    {
        $modules = [];
        
        foreach ($this->dependencies as $dep) {
            if (str_starts_with($dep, 'module:')) {
                $modules[] = substr($dep, 7);
            }
        }
        
        return $modules;
    }

    /**
     * Get dependency config.
     *
     * @return array<int, string>
     */
    public function getDependencyConfig(): array
    {
        $configs = [];
        
        foreach ($this->dependencies as $dep) {
            if (str_starts_with($dep, 'config:')) {
                $configs[] = substr($dep, 7);
            }
        }
        
        return $configs;
    }

    /**
     * Check if depends on a specific module.
     */
    public function dependsOnModule(string $moduleName): bool
    {
        return in_array("module:{$moduleName}", $this->dependencies, true);
    }
}
