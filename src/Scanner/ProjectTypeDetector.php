<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

final class ProjectTypeDetector
{
    public const TYPE_DRUPAL_MODULE = 'drupal-module';
    public const TYPE_DRUPAL_THEME = 'drupal-theme';
    public const TYPE_DRUPAL_PROFILE = 'drupal-profile';
    public const TYPE_DRUPAL_CORE = 'drupal-core';
    public const TYPE_DRUPAL_SITE = 'drupal-site';
    public const TYPE_SYMFONY = 'symfony';
    public const TYPE_GENERIC = 'generic';

    /**
     * Detect project type from filesystem patterns.
     * Returns null if type cannot be confidently determined.
     */
    public function detect(string $projectPath): ?string
    {
        $projectPath = rtrim($projectPath, '/');

        // 1. Check for Drupal core (core/lib/Drupal.php or core/composer.json)
        if ($this->isDrupalCore($projectPath)) {
            return self::TYPE_DRUPAL_CORE;
        }

        // 2. Check for Drupal site (sites/default/settings.php)
        if ($this->isDrupalSite($projectPath)) {
            return self::TYPE_DRUPAL_SITE;
        }

        // 3. Check for Drupal module, theme, profile
        $infoResult = $this->scanInfoYaml($projectPath);
        if (($infoResult['type'] ?? null) !== null) {
            return $infoResult['type'];
        }

        // 4. Check by file extension patterns
        $type = $this->detectByFilePattern($projectPath);
        if ($type !== null) {
            return $type;
        }

        // 5. Check for Symfony
        if ($this->isSymfonyProject($projectPath)) {
            return self::TYPE_SYMFONY;
        }

        return null;
    }

    /**
     * Check if path is Drupal core.
     */
    private function isDrupalCore(string $projectPath): bool
    {
        $drupalPhp = $projectPath . '/core/lib/Drupal.php';
        $coreComposer = $projectPath . '/core/composer.json';

        if (file_exists($drupalPhp)) {
            return true;
        }

        if (file_exists($coreComposer)) {
            $composer = json_decode(file_get_contents($coreComposer), true);
            return isset($composer['name']) && $composer['name'] === 'drupal/core';
        }

        return false;
    }

    /**
     * Check if path is a Drupal site.
     */
    private function isDrupalSite(string $projectPath): bool
    {
        $settingsPhp = $projectPath . '/sites/default/settings.php';

        // Don't detect sites/default inside a core directory
        if (file_exists($projectPath . '/core/lib/Drupal.php')) {
            return false;
        }

        return file_exists($settingsPhp);
    }

    /**
     * Check if path is a Symfony project.
     */
    private function isSymfonyProject(string $projectPath): bool
    {
        $lockFile = $projectPath . '/composer.lock';
        if (!file_exists($lockFile)) {
            return false;
        }

        $lock = json_decode(file_get_contents($lockFile), true);
        if (!$lock) {
            return false;
        }

        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
        foreach ($packages as $package) {
            if (($package['name'] ?? '') === 'symfony/framework-bundle') {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan for *.info.yml files and extract type field.
     * @return array{type: ?string, name: ?string}
     */
    private function scanInfoYaml(string $projectPath): array
    {
        if (!is_dir($projectPath)) {
            return ['type' => null, 'name' => null];
        }

        $iterator = new \DirectoryIterator($projectPath);

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            if (str_ends_with($fileInfo->getFilename(), '.info.yml')) {
                $content = file_get_contents($fileInfo->getPathname());
                if ($content === false) {
                    continue;
                }

                // Extract type field using simple regex
                if (preg_match('/^\s*type\s*:\s*(\w+)/m', $content, $matches)) {
                    $type = $matches[1];
                    if (in_array($type, ['module', 'theme', 'profile'], true)) {
                        // Extract name field
                        $name = null;
                        if (preg_match('/^\s*name\s*:\s*(.+)$/m', $content, $nameMatches)) {
                            $name = trim($nameMatches[1], '"\'');
                        }

                        return [
                            'type' => 'drupal-' . $type,
                            'name' => $name,
                        ];
                    }
                }
            }
        }

        return ['type' => null, 'name' => null];
    }

    /**
     * Detect project type by file extension patterns.
     */
    private function detectByFilePattern(string $projectPath): ?string
    {
        if (!is_dir($projectPath)) {
            return null;
        }

        $iterator = new \DirectoryIterator($projectPath);

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $extension = $fileInfo->getExtension();

            if ($extension === 'module') {
                return self::TYPE_DRUPAL_MODULE;
            }

            if ($extension === 'theme') {
                return self::TYPE_DRUPAL_THEME;
            }

            if ($extension === 'profile') {
                return self::TYPE_DRUPAL_PROFILE;
            }
        }

        return null;
    }
}
