<?php

declare(strict_types=1);

namespace DrupalEvolver\Adapter;

use DrupalEvolver\Scanner\ProjectTypeDetector;
use DrupalEvolver\Scanner\VersionDetector;

final class DrupalCoreAdapter implements EcosystemAdapterInterface
{
    private const PHP_EXTENSIONS = ['.php', '.module', '.inc', '.install', '.profile', '.theme', '.engine'];
    private const HOOK_EXTENSIONS = ['.module', '.install', '.profile', '.theme'];
    private const SEMANTIC_FIELDS = [
        'fqn', 'name', 'label', 'class', 'path', 'controller',
        'configure_route', 'base_theme', 'route_name', 'base_route', 'parent_id',
        'mentioned_extensions', 'dependency_targets', 'route_refs',
        'install', 'recipes', 'dependencies',
    ];

    public function ecosystem(): string
    {
        return 'drupal';
    }

    public function detectProjectType(string $projectPath): ?string
    {
        $detector = new ProjectTypeDetector();
        return $detector->detect($projectPath);
    }

    public function detectVersion(string $projectPath): ?string
    {
        $detector = new VersionDetector();
        return $detector->detect($projectPath);
    }

    public function phpExtensions(): array
    {
        return self::PHP_EXTENSIONS;
    }

    public function semanticSearchFields(): array
    {
        return self::SEMANTIC_FIELDS;
    }

    public function isHookFile(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array('.' . $ext, self::HOOK_EXTENSIONS, true);
    }

    public function extractHookName(string $functionName, string $filePath): ?string
    {
        // Match pattern: module_hookname or theme_hookname
        // Extract module/theme name from file basename
        $basename = basename($filePath, '.' . pathinfo($filePath, PATHINFO_EXTENSION));
        $prefix = $basename;

        if (str_starts_with($functionName, $prefix . '_')) {
            $hookName = substr($functionName, strlen($prefix) + 1);
            // Exclude generic preprocess/process hooks
            if (in_array($hookName, ['preprocess', 'process'], true)) {
                return null;
            }
            return $hookName;
        }

        return null;
    }
}
