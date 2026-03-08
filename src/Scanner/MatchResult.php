<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

/**
 * Value object representing a match found during scanning.
 */
final class MatchResult
{
    /**
     * @param int<0, max> $lineStart
     * @param int<0, max> $lineEnd
     * @param int<0, max> $byteStart
     * @param int<0, max> $byteEnd
     */
    public function __construct(
        public readonly ?int $changeId,
        public readonly int $lineStart,
        public readonly int $lineEnd,
        public readonly int $byteStart,
        public readonly int $byteEnd,
        public readonly string $matchedSource,
        public readonly string $fixMethod,
        public readonly ?string $changeType,
        public readonly ?string $severity,
        public readonly ?string $oldFqn,
        public readonly ?string $migrationHint,
        public readonly ?string $suggestedFix = null,
        public readonly string $status = 'pending',
    ) {}

    /**
     * Create a match from a scan context and match node.
     */
    public static function fromContext(
        ScanContext $context,
        \DrupalEvolver\TreeSitter\Node $matchNode,
        ?string $suggestedFix = null,
    ): self {
        $metadata = $context->getMetadata();

        return new self(
            changeId: $context->getChangeId(),
            lineStart: $matchNode->startPoint()['row'] + 1,
            lineEnd: $matchNode->endPoint()['row'] + 1,
            byteStart: $matchNode->startByte(),
            byteEnd: $matchNode->endByte(),
            matchedSource: $matchNode->text(),
            fixMethod: $context->hasFixTemplate() ? 'template' : 'manual',
            changeType: $metadata['change_type'] ?? null,
            severity: $metadata['severity'] ?? null,
            oldFqn: $metadata['old_fqn'] ?? null,
            migrationHint: $metadata['migration_hint'] ?? null,
            suggestedFix: $suggestedFix,
        );
    }

    /**
     * Convert to array for database insertion.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'change_id' => $this->changeId,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'byte_start' => $this->byteStart,
            'byte_end' => $this->byteEnd,
            'matched_source' => $this->matchedSource,
            'fix_method' => $this->fixMethod,
            'suggested_fix' => $this->suggestedFix,
            'status' => $this->status,
            'change_type' => $this->changeType,
            'severity' => $this->severity,
            'old_fqn' => $this->oldFqn,
            'migration_hint' => $this->migrationHint,
        ];
    }
}
