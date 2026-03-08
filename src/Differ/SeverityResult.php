<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

/**
 * Value object representing the result of severity classification.
 */
final class SeverityResult
{
    public function __construct(
        public readonly string $severity,
        public readonly float $confidence,
        public readonly string $migrationHint,
        public readonly string $riskReason,
    ) {}

    /**
     * Create a new severity result.
     */
    public static function create(
        string $severity,
        float $confidence,
        string $migrationHint = '',
        string $riskReason = '',
    ): self {
        return new self($severity, $confidence, $migrationHint, $riskReason);
    }

    /**
     * Create a breaking change result.
     */
    public static function breaking(string $riskReason = '', float $confidence = 1.0): self
    {
        return new self('breaking', $confidence, '', $riskReason);
    }

    /**
     * Create a deprecation result.
     */
    public static function deprecation(string $migrationHint = '', float $confidence = 1.0): self
    {
        return new self('deprecation', $confidence, $migrationHint, '');
    }

    /**
     * Create an info result.
     */
    public static function info(string $migrationHint = '', float $confidence = 1.0): self
    {
        return new self('info', $confidence, $migrationHint, '');
    }

    /**
     * Convert to array for database storage.
     *
     * @return array{severity: string, confidence: float, migration_hint: string, risk_reason: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'confidence' => $this->confidence,
            'migration_hint' => $this->migrationHint,
            'risk_reason' => $this->riskReason,
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
     * Check if this is a deprecation.
     */
    public function isDeprecation(): bool
    {
        return $this->severity === 'deprecation';
    }
}
