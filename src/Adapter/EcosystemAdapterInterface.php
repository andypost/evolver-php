<?php

declare(strict_types=1);

namespace DrupalEvolver\Adapter;

interface EcosystemAdapterInterface
{
    /**
     * Get the ecosystem name (e.g., 'drupal', 'symfony').
     */
    public function ecosystem(): string;

    /**
     * Detect project type from filesystem.
     * Returns null if type cannot be confidently determined.
     */
    public function detectProjectType(string $projectPath): ?string;

    /**
     * Detect core/framework version.
     * Returns null if version cannot be detected.
     */
    public function detectVersion(string $projectPath): ?string;

    /**
     * Get file extensions for this ecosystem's PHP-like files.
     * @return list<string>
     */
    public function phpExtensions(): array;

    /**
     * Get semantic metadata fields to search in YAML symbols.
     * @return list<string>
     */
    public function semanticSearchFields(): array;

    /**
     * Should this file be treated as a hook implementation?
     */
    public function isHookFile(string $filePath): bool;

    /**
     * Extract hook name from function name.
     * Returns null if not a hook.
     */
    public function extractHookName(string $functionName, string $filePath): ?string;
}
