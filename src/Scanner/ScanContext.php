<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

use DrupalEvolver\TreeSitter\Node;

/**
 * Context object for scan operations.
 * 
 * Encapsulates all data needed for matching changes against source code.
 */
final class ScanContext
{
    /**
     * @param array<string, mixed> $change The change definition from database
     */
    public function __construct(
        public readonly Node $root,
        public readonly string $source,
        public readonly string $language,
        public readonly array $change,
    ) {}

    /**
     * Get the short name from change's old_fqn for quick text filtering.
     */
    public function getShortName(): ?string
    {
        $oldFqn = $this->change['old_fqn'] ?? '';
        if ($oldFqn === '') {
            return null;
        }

        $parts = preg_split('/[\\\\:]/', $oldFqn);
        return is_array($parts) ? end($parts) : null;
    }

    /**
     * Check if the source contains the change's symbol name.
     */
    public function containsSymbol(): bool
    {
        $shortName = $this->getShortName();
        return $shortName === null || str_contains($this->source, $shortName);
    }

    /**
     * Get the tree-sitter query pattern from the change.
     */
    public function getQuery(): ?string
    {
        $query = $this->change['ts_query'] ?? null;
        
        if ($query instanceof \DrupalEvolver\Pattern\QueryPattern) {
            return $query->pattern;
        }
        
        return is_string($query) ? $query : null;
    }

    /**
     * Get the change type.
     */
    public function getChangeType(): string
    {
        return $this->change['change_type'] ?? '';
    }

    /**
     * Get the change ID.
     */
    public function getChangeId(): ?int
    {
        $id = $this->change['id'] ?? null;
        return $id > 0 ? $id : null;
    }

    /**
     * Get metadata from the change.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->change['metadata'] ?? [];
    }

    /**
     * Get the diff_json decoded.
     *
     * @return array<string, mixed>|null
     */
    public function getDiff(): ?array
    {
        $diffJson = $this->change['diff_json'] ?? '';
        if ($diffJson === '') {
            return null;
        }

        $decoded = json_decode($diffJson, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Check if this change has a fix template.
     */
    public function hasFixTemplate(): bool
    {
        return !empty($this->change['fix_template']);
    }
}
