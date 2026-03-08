<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\Symbol\SymbolType;

/**
 * Value object for .info.yml file data.
 */
final class InfoYamlData
{
    public function __construct(
        public readonly SymbolType $type,
        public readonly string $machineName,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $package = null,
        public readonly array $dependencies = [],
        public readonly ?string $configure = null,
        public readonly ?string $baseTheme = null,
        public readonly ?string $version = null,
        public readonly ?string $core = null,
        public readonly array $testDependencies = [],
        public readonly array $hidden = [],
        public readonly ?string $project = null,
        public readonly ?string $datestamp = null,
    ) {}

    /**
     * Create from parsed YAML data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $filePath, array $data): self
    {
        $type = self::resolveType($filePath, $data);
        $machineName = basename(dirname($filePath));
        
        // Handle special case for theme .info.yml
        if ($type === SymbolType::ThemeInfo && isset($data['base theme'])) {
            // Theme with base theme
        }

        return new self(
            type: $type,
            machineName: $machineName,
            name: $data['name'] ?? null,
            description: $data['description'] ?? null,
            package: $data['package'] ?? null,
            dependencies: self::extractDependencies($data),
            configure: $data['configure'] ?? null,
            baseTheme: $data['base theme'] ?? null,
            version: $data['version'] ?? null,
            core: $data['core'] ?? null,
            testDependencies: $data['test_dependencies'] ?? [],
            hidden: $data['hidden'] ?? [],
            project: $data['project'] ?? null,
            datestamp: $data['datestamp'] ?? null,
        );
    }

    /**
     * Resolve the symbol type from file path and data.
     *
     * @param array<string, mixed> $data
     */
    private static function resolveType(string $filePath, array $data): SymbolType
    {
        $basename = basename($filePath);
        
        // Check file path patterns first
        if (preg_match('/\.profile\.info\.yml$/', $basename)) {
            return SymbolType::ProfileInfo;
        }
        
        if (preg_match('/\.theme\.info\.yml$/', $basename)) {
            return SymbolType::ThemeInfo;
        }
        
        if (preg_match('/\.engine\.info\.yml$/', $basename)) {
            return SymbolType::ThemeEngineInfo;
        }
        
        // Check type field in data
        $typeValue = $data['type'] ?? 'module';
        
        return match ($typeValue) {
            'module' => SymbolType::ModuleInfo,
            'theme' => SymbolType::ThemeInfo,
            'profile' => SymbolType::ProfileInfo,
            default => SymbolType::ModuleInfo,
        };
    }

    /**
     * Extract dependencies from various formats.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private static function extractDependencies(array $data): array
    {
        $deps = $data['dependencies'] ?? [];
        
        if (!is_array($deps)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', $deps),
            static fn($dep): bool => $dep !== ''
        ));
    }

    /**
     * Convert to array for metadata storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file_kind' => $this->type->value,
            'machine_name' => $this->machineName,
            'name' => $this->name,
            'description' => $this->description,
            'package' => $this->package,
            'dependencies' => $this->dependencies,
            'configure' => $this->configure,
            'base_theme' => $this->baseTheme,
            'version' => $this->version,
            'core' => $this->core,
            'test_dependencies' => $this->testDependencies,
            'hidden' => $this->hidden,
            'project' => $this->project,
            'datestamp' => $this->datestamp,
        ];
    }

    /**
     * Check if this is a module.
     */
    public function isModule(): bool
    {
        return $this->type === SymbolType::ModuleInfo;
    }

    /**
     * Check if this is a theme.
     */
    public function isTheme(): bool
    {
        return $this->type === SymbolType::ThemeInfo || $this->type === SymbolType::ThemeEngineInfo;
    }

    /**
     * Check if this is a profile.
     */
    public function isProfile(): bool
    {
        return $this->type === SymbolType::ProfileInfo;
    }

    /**
     * Get dependency owners (module/theme names).
     *
     * @return array<int, string>
     */
    public function getDependencyOwners(): array
    {
        $owners = [];
        
        foreach ($this->dependencies as $dep) {
            // "drupal:block" → "drupal"
            // "block" → "block"
            if (str_contains($dep, ':')) {
                $parts = explode(':', $dep);
                $owners[] = $parts[0];
            } else {
                $owners[] = $dep;
            }
        }
        
        return array_values(array_unique($owners));
    }

    /**
     * Check if depends on a specific module.
     */
    public function dependsOn(string $moduleName): bool
    {
        foreach ($this->dependencies as $dep) {
            if (str_contains($dep, ':')) {
                $parts = explode(':', $dep);
                if ($parts[1] === $moduleName) {
                    return true;
                }
            } elseif ($dep === $moduleName) {
                return true;
            }
        }
        
        return false;
    }
}
