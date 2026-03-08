<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

/**
 * Value object for signature change hints.
 */
final class SignatureHint
{
    public function __construct(
        public readonly int $oldCount,
        public readonly int $newCount,
    ) {}

    /**
     * Create from parameter counts.
     */
    public static function create(int $oldCount, int $newCount): self
    {
        return new self($oldCount, $newCount);
    }

    /**
     * Check if parameters were added.
     */
    public function parametersAdded(): bool
    {
        return $this->newCount > $this->oldCount;
    }

    /**
     * Check if parameters were removed.
     */
    public function parametersRemoved(): bool
    {
        return $this->newCount < $this->oldCount;
    }

    /**
     * Get the count difference (positive = added, negative = removed).
     */
    public function countDifference(): int
    {
        return $this->newCount - $this->oldCount;
    }

    /**
     * Convert to array.
     *
     * @return array{old_count: int, new_count: int}
     */
    public function toArray(): array
    {
        return [
            'old_count' => $this->oldCount,
            'new_count' => $this->newCount,
        ];
    }
}
