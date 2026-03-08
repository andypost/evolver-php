<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

/**
 * Classifies change severity, confidence, and generates migration hints.
 *
 * Part of Phase 1.5 - Upgrade-focused regression reporting.
 */
class ChangeSeverityClassifier
{
    /**
     * Classify a change by severity, confidence, and generate migration hints.
     *
     * @param array{change_type: string, old_fqn: ?string, new_fqn: ?string, language: string, diff_json: ?string, old_symbol: ?array, new_symbol: ?array} $change
     * @return array{severity: string, confidence: float, migration_hint: string, risk_reason: string}
     */
    public function classify(array $change): array
    {
        $changeType = $change['change_type'] ?? '';
        $language = $change['language'] ?? '';
        $oldFqn = $change['old_fqn'] ?? '';
        $newFqn = $change['new_fqn'] ?? '';
        $diff = $change['diff_json'] ? json_decode($change['diff_json'], true) : null;

        return match (true) {
            // BREAKING - Code will fail
            str_ends_with($changeType, '_removed') => $this->classifyRemoved($changeType, $language, $oldFqn),
            $changeType === 'signature_changed' => $this->classifySignatureChanged($diff, $oldFqn),
            $changeType === 'inheritance_impact' => $this->classifyInheritanceImpact($diff, $oldFqn),
            $changeType === 'service_removed' => $this->classifyServiceRemoved($oldFqn),
            $changeType === 'service_renamed' => $this->classifyServiceRenamed($oldFqn, $newFqn),

            // HIGH RISK - Likely to break at runtime
            str_ends_with($changeType, '_renamed') => $this->classifyRenamed($changeType, $language, $oldFqn, $newFqn),
            $changeType === 'event_removed' => $this->classifyEventRemoved($oldFqn),
            $changeType === 'hook_removed' => $this->classifyHookRemoved($oldFqn),

            // MEDIUM RISK - May break with specific usage
            str_contains($changeType, 'deprecated') => $this->classifyDeprecated($changeType, $language, $oldFqn, $change['deprecation_version'] ?? null),
            $changeType === 'config_removed' => $this->classifyConfigRemoved($oldFqn),
            $changeType === 'config_schema_removed' => $this->classifyConfigSchemaRemoved($oldFqn),

            // LOW RISK - Modernization opportunities
            str_contains($changeType, 'to_attribute') => $this->classifyToAttribute($changeType),
            str_contains($changeType, 'annotation_to_attribute') => $this->classifyAnnotationToAttribute($changeType),
            $changeType === 'procedural_to_attribute' => $this->classifyProceduralToAttribute($oldFqn),

            // UNKNOWN
            default => [
                'severity' => 'unknown',
                'confidence' => 0.5,
                'migration_hint' => "Unknown change type: {$changeType}",
                'risk_reason' => "Change type '{$changeType}' needs manual review.",
            ],
        };
    }

    private function classifyRemoved(string $changeType, string $language, string $oldFqn): array
    {
        $symbolType = str_replace('_removed', '', $changeType);
        $riskReason = "The {$symbolType} '{$oldFqn}' has been completely removed.";


        return [
            'severity' => 'breaking',
            'confidence' => 1.0,
            'migration_hint' => "Remove all usage of {$oldFqn}. No direct replacement available.",
            'risk_reason' => $riskReason,
        ];
    }

    private function classifySignatureChanged(?array $diff, string $oldFqn): array
    {
        if (!$diff || empty($diff['changes'] ?? [])) {
            return [
                'severity' => 'unknown',
                'confidence' => 0.5,
                'migration_hint' => "Method signature of {$oldFqn} changed.",
                'risk_reason' => "Method signature changed but no details available.",
            ];
        }

        $hasAddedParams = false;
        $hasRemovedParams = false;
        $hasTypeChanges = false;

        foreach ($diff['changes'] as $c) {
            if ($c['type'] === 'parameter_added') $hasAddedParams = true;
            if ($c['type'] === 'parameter_removed') $hasRemovedParams = true;
            if ($c['type'] === 'parameter_type_changed') $hasTypeChanges = true;
        }

        if ($hasRemovedParams) {
            return [
                'severity' => 'breaking',
                'confidence' => 0.95,
                'migration_hint' => "Method signature of {$oldFqn} has parameters removed. Update all call sites.",
                'risk_reason' => 'Parameters removed from method signature - this will break any code passing those arguments.',
            ];
        }

        if ($hasAddedParams) {
            return [
                'severity' => 'safe',
                'confidence' => 0.8,
                'migration_hint' => "Method signature of {$oldFqn} has new optional parameters added. Existing calls remain compatible.",
                'risk_reason' => 'New optional parameters added - existing code will continue to work.',
            ];
        }

        if ($hasTypeChanges) {
            return [
                'severity' => 'breaking',
                'confidence' => 0.7,
                'migration_hint' => "Method signature of {$oldFqn} parameter types changed. Update all call sites to match.",
                'risk_reason' => 'Parameter types changed - may cause type mismatches or require explicit casting.',
            ];
        }

        return [
            'severity' => 'unknown',
            'confidence' => 0.6,
            'migration_hint' => "Method signature of {$oldFqn} changed. Review the diff for details.",
            'risk_reason' => 'Method signature changed but the exact change is unclear.',
        ];
    }

    private function classifyInheritanceImpact(?array $diff, string $oldFqn): array
    {
        if (!$diff || empty($diff['changes'] ?? [])) {
            return [
                'severity' => 'breaking',
                'confidence' => 0.7,
                'migration_hint' => "Class {$oldFqn} inheritance hierarchy changed.",
                'risk_reason' => 'Inheritance changed - this may affect type checking and method resolution.',
            ];
        }

        $parentRemoved = false;
        $parentChanged = false;

        foreach ($diff['changes'] as $c) {
            if ($c['type'] === 'parent_removed') $parentRemoved = true;
            if ($c['type'] === 'parent_changed') $parentChanged = true;
        }

        if ($parentRemoved) {
            return [
                'severity' => 'breaking',
                'confidence' => 1.0,
                'migration_hint' => "Class {$oldFqn} no longer extends a known parent. Check for namespace moves or API changes.",
                'risk_reason' => 'Parent class/interface removed - this will break any code expecting the original inheritance.',
            ];
        }

        if ($parentChanged) {
            return [
                'severity' => 'breaking',
                'confidence' => 0.8,
                'migration_hint' => "Class {$oldFqn} parent class changed. Check if the new parent provides equivalent functionality.",
                'risk_reason' => 'Parent class changed - may affect method availability or type compatibility.',
            ];
        }

        return [
            'severity' => 'breaking',
            'confidence' => 0.6,
            'migration_hint' => "Class {$oldFqn} inheritance changed. Review the impact.",
            'risk_reason' => 'Inheritance changed without clear details.',
        ];
    }

    private function classifyRenamed(string $changeType, string $language, string $oldFqn, string $newFqn): array
    {
        $symbolType = str_replace('_renamed', '', $changeType);
        $migrationHint = "The {$symbolType} '{$oldFqn}' was renamed to '{$newFqn}'. Update all references.";

        // Determine if this is a namespace move vs simple rename
        $isNamespaceMove = $this->isNamespaceMove($oldFqn, $newFqn);

        if ($isNamespaceMove) {
            return [
                'severity' => 'breaking',
                'confidence' => 0.9,
                'migration_hint' => $migrationHint . " This appears to be a namespace move - use automated refactoring tools.",
                'risk_reason' => 'Symbol moved to a different namespace - requires updating all references.',
            ];
        }

        if ($language === 'yaml' && str_contains($oldFqn, 'service.')) {
            return [
                'severity' => 'breaking',
                'confidence' => 0.95,
                'migration_hint' => $migrationHint . " Service renamed - update service definitions and dependency injection containers.",
                'risk_reason' => 'Service renamed - this will break all dependency injection references unless updated.',
            ];
        }

        return [
            'severity' => 'breaking',
            'confidence' => 0.85,
            'migration_hint' => $migrationHint,
            'risk_reason' => "Symbol renamed - all references must be updated.",
        ];
    }

    private function classifyEventRemoved(string $oldFqn): array
    {
        return [
            'severity' => 'breaking',
            'confidence' => 0.9,
            'migration_hint' => "Event '{$oldFqn}' was removed. Find alternative events or refactor to use a different dispatch mechanism.",
            'risk_reason' => 'Event removed - any listeners for this event will no longer be triggered.',
        ];
    }

    private function classifyHookRemoved(string $oldFqn): array
    {
        return [
            'severity' => 'breaking',
            'confidence' => 0.95,
            'migration_hint' => "Hook '{$oldFqn}' was removed. Replace with an event listener or attribute-based hook.",
            'risk_reason' => 'Procedural hook removed - this implementation will no longer be called by Drupal.',
        ];
    }

    private function classifyServiceRemoved(string $oldFqn): array
    {
        return [
            'severity' => 'breaking',
            'confidence' => 1.0,
            'migration_hint' => "Service '{$oldFqn}' was removed. Find replacement service or implement the functionality differently.",
            'risk_reason' => 'Service removed - all dependency injection and direct service calls will fail.',
        ];
    }

    private function classifyServiceRenamed(string $oldFqn, string $newFqn): array
    {
        return [
            'severity' => 'breaking',
            'confidence' => 0.95,
            'migration_hint' => "Service renamed from '{$oldFqn}' to '{$newFqn}'. Update service YAML definitions and all dependency injection references.",
            'risk_reason' => 'Service renamed - this will break all service references unless updated.',
        ];
    }

    private function classifyDeprecated(string $changeType, string $language, string $oldFqn, ?string $deprecationVersion): array
    {
        if ($deprecationVersion) {
            return [
                'severity' => 'warning',
                'confidence' => 1.0,
                'migration_hint' => "{$oldFqn} is deprecated since {$deprecationVersion}. Plan to migrate before it's removed.",
                'risk_reason' => "Deprecated in version {$deprecationVersion} - will be removed in a future Drupal version.",
            ];
        }

        return [
            'severity' => 'warning',
            'confidence' => 0.8,
            'migration_hint' => "{$oldFqn} is deprecated. Review migration options.",
            'risk_reason' => 'Deprecated - will break in a future version but works now.',
        ];
    }

    private function classifyConfigRemoved(string $oldFqn): array
    {
        return [
            'severity' => 'breaking',
            'confidence' => 0.9,
            'migration_hint' => "Configuration '{$oldFqn}' was removed. Remove usage from module info files or configuration forms.",
            'risk_reason' => 'Configuration removed - this may cause module installation or runtime errors.',
        ];
    }

    private function classifyConfigSchemaRemoved(string $oldFqn): array
    {
        return [
            'severity' => 'warning',
            'confidence' => 0.7,
            'migration_hint' => "Config schema '{$oldFqn}' was removed. Review any dependencies on this configuration.",
            'risk_reason' => 'Config schema removed - may affect form validation and data integrity.',
        ];
    }

    private function classifyToAttribute(string $changeType): array
    {
        return [
            'severity' => 'info',
            'confidence' => 0.9,
            'migration_hint' => "Modernize to use PHP 8 attributes instead of docblock annotations.",
            'risk_reason' => 'Modernization opportunity - not urgent but improves code quality.',
        ];
    }

    private function classifyAnnotationToAttribute(string $changeType): array
    {
        return [
            'severity' => 'info',
            'confidence' => 0.95,
            'migration_hint' => "Convert annotation to native PHP 8 attribute for better type safety and IDE support.",
            'risk_reason' => 'Modernization opportunity - recommended for better developer experience.',
        ];
    }

    private function classifyProceduralToAttribute(string $oldFqn): array
    {
        return [
            'severity' => 'info',
            'confidence' => 0.8,
            'migration_hint' => "Convert procedural hook to #[Hook] attribute for better discoverability.",
            'risk_reason' => 'Modernization opportunity - procedural hooks are harder to discover.',
        ];
    }

    private function isNamespaceMove(string $oldFqn, string $newFqn): bool
    {
        $oldParts = explode('\\', $oldFqn);
        $newParts = explode('\\', $newFqn);

        // Check if the last part (short name) stayed the same
        $oldShortName = end($oldParts);
        $newShortName = end($newParts);

        if ($oldShortName !== $newShortName) {
            return false;
        }

        // Check if namespace portion changed
        array_pop($oldParts);
        array_pop($newParts);

        return implode('\\', $oldParts) !== implode('\\', $newParts);
    }
}
