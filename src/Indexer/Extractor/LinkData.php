<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\Symbol\SymbolType;

/**
 * Value object for link definition data (menu links, task links, action links, contextual links).
 */
final class LinkData
{
    public function __construct(
        public readonly SymbolType $type,
        public readonly string $linkId,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $routeName = null,
        public readonly ?string $baseRoute = null,
        public readonly ?string $parent = null,
        public readonly ?string $parentId = null,
        public readonly array $appearsOn = [],
        public readonly ?string $group = null,
        public readonly ?int $weight = null,
        public readonly array $options = [],
    ) {}

    /**
     * Create from parsed YAML data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(SymbolType $type, string $linkId, array $data): self
    {
        return new self(
            type: $type,
            linkId: $linkId,
            title: $data['title'] ?? null,
            description: $data['description'] ?? null,
            routeName: $data['route_name'] ?? null,
            baseRoute: $data['base_route'] ?? null,
            parent: $data['parent'] ?? null,
            parentId: $data['parent_id'] ?? null,
            appearsOn: self::extractStringList($data['appears_on'] ?? []),
            group: $data['group'] ?? null,
            weight: isset($data['weight']) ? (int) $data['weight'] : null,
            options: $data['options'] ?? [],
        );
    }

    /**
     * Extract a list of strings.
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
     * Convert to array for metadata storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file_kind' => $this->type->value,
            'link_id' => $this->linkId,
            'title' => $this->title,
            'description' => $this->description,
            'route_name' => $this->routeName,
            'base_route' => $this->baseRoute,
            'parent' => $this->parent,
            'parent_id' => $this->parentId,
            'appears_on' => $this->appearsOn,
            'group' => $this->group,
            'weight' => $this->weight,
            'options' => $this->options,
        ];
    }

    /**
     * Check if this is a menu link.
     */
    public function isMenuLink(): bool
    {
        return $this->type === SymbolType::LinkMenu;
    }

    /**
     * Check if this is a task link (local task).
     */
    public function isTaskLink(): bool
    {
        return $this->type === SymbolType::LinkTask;
    }

    /**
     * Check if this is an action link.
     */
    public function isActionLink(): bool
    {
        return $this->type === SymbolType::LinkAction;
    }

    /**
     * Check if this is a contextual link.
     */
    public function isContextualLink(): bool
    {
        return $this->type === SymbolType::LinkContextual;
    }

    /**
     * Check if link has a parent.
     */
    public function hasParent(): bool
    {
        return $this->parent !== null || $this->parentId !== null;
    }

    /**
     * Check if link appears on specific routes.
     */
    public function hasAppearances(): bool
    {
        return !empty($this->appearsOn);
    }

    /**
     * Get all related routes (route_name, base_route, appears_on).
     *
     * @return array<int, string>
     */
    public function getRelatedRoutes(): array
    {
        $routes = [];
        
        if ($this->routeName !== null) {
            $routes[] = $this->routeName;
        }
        
        if ($this->baseRoute !== null) {
            $routes[] = $this->baseRoute;
        }
        
        return array_merge($routes, $this->appearsOn);
    }
}
