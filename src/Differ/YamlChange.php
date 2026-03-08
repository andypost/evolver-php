<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

/**
 * Value object representing a YAML change.
 */
final class YamlChange
{
    public function __construct(
        public readonly string $changeType,
        public readonly string $severity,
        public readonly float $confidence,
        public readonly ?array $oldSymbol = null,
        public readonly ?array $newSymbol = null,
        public readonly ?array $diffDetails = null,
        public readonly ?string $migrationHint = null,
    ) {}

    /**
     * Create a breaking change.
     */
    public static function breaking(
        string $changeType,
        ?array $oldSymbol = null,
        ?array $newSymbol = null,
        ?array $diffDetails = null,
        float $confidence = 1.0,
    ): self {
        return new self($changeType, 'breaking', $confidence, $oldSymbol, $newSymbol, $diffDetails);
    }

    /**
     * Create a deprecation change.
     */
    public static function deprecation(
        string $changeType,
        ?array $oldSymbol = null,
        string $migrationHint = '',
        float $confidence = 1.0,
    ): self {
        return new self($changeType, 'deprecation', $confidence, $oldSymbol, null, null, $migrationHint);
    }

    /**
     * Convert to array for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'change_type' => $this->changeType,
            'severity' => $this->severity,
            'confidence' => $this->confidence,
            'old' => $this->oldSymbol,
            'new' => $this->newSymbol,
            'diff_json' => $this->diffDetails !== null ? json_encode($this->diffDetails) : null,
            'migration_hint' => $this->migrationHint,
        ];
    }

    /**
     * Check if this is a breaking change.
     */
    public function isBreaking(): bool
    {
        return $this->severity === 'breaking';
    }

    /**
     * Check if this is a rename.
     */
    public function isRename(): bool
    {
        return str_contains($this->changeType, '_renamed');
    }

    /**
     * Check if this has both old and new symbols (for renames/changes).
     */
    public function hasBothSymbols(): bool
    {
        return $this->oldSymbol !== null && $this->newSymbol !== null;
    }
}
