<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

/**
 * Value object for YAML signature diff details.
 */
final class YamlDiffDetails
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data,
    ) {}

    /**
     * Create service diff details.
     */
    public static function service(
        ?string $oldClass = null,
        ?string $newClass = null,
        ?array $oldArguments = null,
        ?array $newArguments = null,
        ?array $oldTags = null,
        ?array $newTags = null,
    ): self {
        return new self([
            'old_class' => $oldClass,
            'new_class' => $newClass,
            'old_arguments' => $oldArguments,
            'new_arguments' => $newArguments,
            'old_tags' => $oldTags,
            'new_tags' => $newTags,
        ]);
    }

    /**
     * Create route diff details.
     */
    public static function route(
        ?string $oldPath = null,
        ?string $newPath = null,
        ?string $oldController = null,
        ?string $newController = null,
    ): self {
        return new self([
            'old_path' => $oldPath,
            'new_path' => $newPath,
            'old_controller' => $oldController,
            'new_controller' => $newController,
        ]);
    }

    /**
     * Create info.yml diff details.
     */
    public static function info(
        array $changedKeys = [],
        ?array $oldDependencies = null,
        ?array $newDependencies = null,
        array $addedDependencies = [],
        array $removedDependencies = [],
        ?string $oldConfigure = null,
        ?string $newConfigure = null,
        ?string $oldBaseTheme = null,
        ?string $newBaseTheme = null,
    ): self {
        return new self([
            'changed_keys' => $changedKeys,
            'old_dependencies' => $oldDependencies,
            'new_dependencies' => $newDependencies,
            'added_dependencies' => $addedDependencies,
            'removed_dependencies' => $removedDependencies,
            'old_configure' => $oldConfigure,
            'new_configure' => $newConfigure,
            'old_base_theme' => $oldBaseTheme,
            'new_base_theme' => $newBaseTheme,
        ]);
    }

    /**
     * Create config diff details.
     */
    public static function config(
        array $addedKeys = [],
        array $removedKeys = [],
        array $changedKeys = [],
        ?array $oldDependencies = null,
        ?array $newDependencies = null,
    ): self {
        return new self([
            'added_top_level_keys' => $addedKeys,
            'removed_top_level_keys' => $removedKeys,
            'changed_top_level_keys' => $changedKeys,
            'old_dependencies' => $oldDependencies,
            'new_dependencies' => $newDependencies,
        ]);
    }

    /**
     * Create recipe diff details.
     */
    public static function recipe(
        array $changedKeys = [],
        array $addedInstall = [],
        array $removedInstall = [],
        array $addedRecipes = [],
        array $removedRecipes = [],
    ): self {
        return new self([
            'changed_keys' => $changedKeys,
            'added_install' => $addedInstall,
            'removed_install' => $removedInstall,
            'added_recipes' => $addedRecipes,
            'removed_recipes' => $removedRecipes,
        ]);
    }

    /**
     * Convert to array for JSON encoding.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Check if there are any changes.
     */
    public function hasChanges(): bool
    {
        foreach ($this->data as $value) {
            if (is_array($value)) {
                if (!empty($value)) {
                    return true;
                }
            } elseif ($value !== null) {
                return true;
            }
        }

        return false;
    }
}
