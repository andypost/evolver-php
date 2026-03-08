<?php

declare(strict_types=1);

namespace DrupalEvolver\Adapter;

use DrupalEvolver\Scanner\VersionDetector;

final class SymfonyAdapter implements EcosystemAdapterInterface
{
    private const PHP_EXTENSIONS = ['.php'];
    private const SEMANTIC_FIELDS = ['fqn', 'name', 'class'];

    public function ecosystem(): string
    {
        return 'symfony';
    }

    public function detectProjectType(string $projectPath): ?string
    {
        $lockFile = rtrim($projectPath, '/') . '/composer.lock';
        if (!file_exists($lockFile)) {
            return null;
        }

        $lock = json_decode(file_get_contents($lockFile), true);
        if (!$lock) {
            return null;
        }

        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
        foreach ($packages as $package) {
            if (($package['name'] ?? '') === 'symfony/framework-bundle') {
                return 'symfony';
            }
        }

        return null;
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
        return false;
    }

    public function extractHookName(string $functionName, string $filePath): ?string
    {
        return null;
    }
}
