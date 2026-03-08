<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\Symbol\SymbolType;

/**
 * Detects changes in library definitions between versions.
 *
 * Library changes include:
 * - Library removals and additions
 * - Library renames (via similarity matching)
 * - CSS/JS file changes within libraries
 * - Dependency changes
 * - Deprecation tracking
 */
final class LibraryDiffer
{
    public function __construct(
        private DatabaseApi $api,
    ) {}

    /**
     * Find library-related changes between two versions.
     *
     * @return list<array<string, mixed>>
     */
    public function diffLibraries(int $fromVersionId, int $toVersionId): array
    {
        $changes = [];

        $fromLibs = $this->api->symbols()->findByTypeAndVersion($fromVersionId, SymbolType::DrupalLibrary);
        $toLibs = $this->api->symbols()->findByTypeAndVersion($toVersionId, SymbolType::DrupalLibrary);

        $fromLibraries = $this->indexLibraries($fromLibs);
        $toLibraries = $this->indexLibraries($toLibs);

        // Detect removals and modifications
        foreach ($fromLibraries as $name => $lib) {
            if (!isset($toLibraries[$name])) {
                $changes[] = $this->createChange(
                    'library_removed',
                    $lib,
                    null,
                    $fromVersionId,
                    $toVersionId,
                    "Library '{$name}' was removed. Any attach_library() or #attached references will fail.",
                );
                continue;
            }

            $toLib = $toLibraries[$name];

            // Check for asset changes within the library
            $assetChanges = $this->diffLibraryAssets($name, $lib, $toLib, $fromVersionId, $toVersionId);
            array_push($changes, ...$assetChanges);

            // Check for dependency changes
            $depChanges = $this->diffLibraryDependencies($name, $lib, $toLib, $fromVersionId, $toVersionId);
            array_push($changes, ...$depChanges);

            // Check for deprecation
            $deprecationChange = $this->checkDeprecation($name, $lib, $toLib, $fromVersionId, $toVersionId);
            if ($deprecationChange !== null) {
                $changes[] = $deprecationChange;
            }
        }

        // Detect additions
        foreach ($toLibraries as $name => $lib) {
            if (!isset($fromLibraries[$name])) {
                $changes[] = $this->createChange(
                    'library_added',
                    null,
                    $lib,
                    $fromVersionId,
                    $toVersionId,
                    "New library '{$name}' is available.",
                );
            }
        }

        return $changes;
    }

    /**
     * Check for CSS/JS asset changes within a library.
     *
     * @return list<array<string, mixed>>
     */
    private function diffLibraryAssets(
        string $name,
        array $fromLib,
        array $toLib,
        int $fromVersionId,
        int $toVersionId,
    ): array {
        $changes = [];
        $fromMeta = $fromLib['metadata'];
        $toMeta = $toLib['metadata'];

        // Check removed CSS assets
        $fromCss = $fromMeta['css_assets'] ?? [];
        $toCss = $toMeta['css_assets'] ?? [];
        foreach (array_diff($fromCss, $toCss) as $cssFile) {
            $changes[] = $this->createChange(
                'library_css_removed',
                $fromLib,
                $toLib,
                $fromVersionId,
                $toVersionId,
                "CSS file '{$cssFile}' was removed from library '{$name}'.",
            );
        }

        // Check removed JS assets
        $fromJs = $fromMeta['javascript_assets'] ?? [];
        $toJs = $toMeta['javascript_assets'] ?? [];
        foreach (array_diff($fromJs, $toJs) as $jsFile) {
            $changes[] = $this->createChange(
                'library_js_removed',
                $fromLib,
                $toLib,
                $fromVersionId,
                $toVersionId,
                "JS file '{$jsFile}' was removed from library '{$name}'.",
            );
        }

        return $changes;
    }

    /**
     * Check for dependency changes within a library.
     *
     * @return list<array<string, mixed>>
     */
    private function diffLibraryDependencies(
        string $name,
        array $fromLib,
        array $toLib,
        int $fromVersionId,
        int $toVersionId,
    ): array {
        $changes = [];
        $fromDeps = $fromLib['metadata']['dependency_libraries'] ?? [];
        $toDeps = $toLib['metadata']['dependency_libraries'] ?? [];

        foreach (array_diff($fromDeps, $toDeps) as $dep) {
            $changes[] = $this->createChange(
                'library_dependency_removed',
                $fromLib,
                $toLib,
                $fromVersionId,
                $toVersionId,
                "Dependency '{$dep}' was removed from library '{$name}'. Ensure your code doesn't rely on assets loaded via this dependency.",
            );
        }

        return $changes;
    }

    /**
     * Check if a library was newly deprecated between versions.
     */
    private function checkDeprecation(
        string $name,
        array $fromLib,
        array $toLib,
        int $fromVersionId,
        int $toVersionId,
    ): ?array {
        $fromDeprecated = (int) ($fromLib['symbol']['is_deprecated'] ?? 0);
        $toDeprecated = (int) ($toLib['symbol']['is_deprecated'] ?? 0);

        if ($fromDeprecated === 0 && $toDeprecated === 1) {
            $message = $toLib['symbol']['deprecation_message'] ?? "Library '{$name}' is now deprecated.";
            return $this->createChange(
                'library_deprecated',
                $fromLib,
                $toLib,
                $fromVersionId,
                $toVersionId,
                $message,
                'deprecation',
            );
        }

        return null;
    }

    /**
     * Index library definitions by their FQN (which is the library key name).
     *
     * @param list<array<string, mixed>> $librarySymbols
     * @return array<string, array{metadata: array<string, mixed>, symbol: array<string, mixed>}>
     */
    private function indexLibraries(array $librarySymbols): array
    {
        $indexed = [];

        foreach ($librarySymbols as $symbol) {
            $meta = json_decode((string) ($symbol['metadata_json'] ?? '{}'), true);
            if (!is_array($meta)) {
                $meta = [];
            }

            $name = (string) ($symbol['fqn'] ?? $symbol['name'] ?? '');
            if ($name !== '') {
                $indexed[$name] = [
                    'metadata' => $meta,
                    'symbol' => $symbol,
                ];
            }
        }

        return $indexed;
    }

    /**
     * Create a change record compatible with ChangeRepo::createBatch().
     *
     * @return array<string, mixed>
     */
    private function createChange(
        string $changeType,
        ?array $fromLib,
        ?array $toLib,
        int $fromVersionId,
        int $toVersionId,
        string $message,
        ?string $severity = null,
    ): array {
        return [
            'from_version_id' => $fromVersionId,
            'to_version_id' => $toVersionId,
            'language' => 'drupal_libraries',
            'change_type' => $changeType,
            'severity' => $severity ?? $this->determineSeverity($changeType),
            'old_symbol_id' => $fromLib['symbol']['id'] ?? null,
            'new_symbol_id' => $toLib['symbol']['id'] ?? null,
            'old_fqn' => $fromLib ? ($fromLib['symbol']['fqn'] ?? null) : null,
            'new_fqn' => $toLib ? ($toLib['symbol']['fqn'] ?? null) : null,
            'migration_hint' => $message,
            'confidence' => 1.0,
        ];
    }

    private function determineSeverity(string $changeType): string
    {
        return match ($changeType) {
            'library_removed', 'library_css_removed', 'library_js_removed' => 'breaking',
            'library_deprecated' => 'deprecation',
            'library_dependency_removed' => 'warning',
            'library_added', 'library_version_changed' => 'modernization',
            default => 'warning',
        };
    }
}
