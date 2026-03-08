<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\Symbol\SymbolType;

/**
 * Value object for recipe.yml manifest data.
 */
final class RecipeData
{
    public function __construct(
        public readonly string $recipeId,
        public readonly ?string $label = null,
        public readonly ?string $type = null,
        public readonly ?string $description = null,
        public readonly array $install = [],
        public readonly array $recipes = [],
        public readonly array $config = [],
        public readonly ?string $project = null,
    ) {}

    /**
     * Create from parsed YAML data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $filePath, array $data): self
    {
        $recipeId = pathinfo($filePath, PATHINFO_FILENAME);
        
        if ($recipeId === 'recipe') {
            // If filename is just "recipe.yml", use the name field or directory name
            $recipeId = $data['name'] ?? basename(dirname($filePath));
        }

        return new self(
            recipeId: $recipeId,
            label: $data['name'] ?? null,
            type: $data['type'] ?? null,
            description: $data['description'] ?? null,
            install: self::extractStringList($data['install'] ?? []),
            recipes: self::extractStringList($data['recipes'] ?? []),
            config: self::extractConfig($data['config'] ?? []),
            project: $data['project'] ?? null,
        );
    }

    /**
     * Extract a list of strings from various formats.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private static function extractStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', $value),
            static fn($item): bool => is_string($item) && $item !== ''
        ));
    }

    /**
     * Extract config operations.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function extractConfig(array $config): array
    {
        $result = [];
        
        // Config format:
        // config:
        //   delete:
        //     - system.site
        //   update:
        //   - system.site:
        //       name: 'My Site'
        
        foreach ($config as $operation => $items) {
            if (is_array($items)) {
                $result[$operation] = $items;
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
            'file_kind' => SymbolType::RecipeManifest->value,
            'recipe_id' => $this->recipeId,
            'label' => $this->label,
            'type' => $this->type,
            'description' => $this->description,
            'install' => $this->install,
            'recipes' => $this->recipes,
            'config' => $this->config,
            'project' => $this->project,
        ];
    }

    /**
     * Check if recipe installs modules.
     */
    public function installsModules(): bool
    {
        return !empty($this->install);
    }

    /**
     * Check if recipe includes other recipes.
     */
    public function includesRecipes(): bool
    {
        return !empty($this->recipes);
    }

    /**
     * Check if recipe has config operations.
     */
    public function hasConfigOperations(): bool
    {
        return !empty($this->config);
    }

    /**
     * Get modules to install.
     *
     * @return array<int, string>
     */
    public function getModules(): array
    {
        return $this->install;
    }

    /**
     * Get recipes to include.
     *
     * @return array<int, string>
     */
    public function getRecipes(): array
    {
        return $this->recipes;
    }
}
