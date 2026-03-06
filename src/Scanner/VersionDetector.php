<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

class VersionDetector
{
    public function detect(string $projectPath): ?string
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
            if ($package['name'] === 'drupal/core') {
                return ltrim($package['version'] ?? '', 'v');
            }
            if ($package['name'] === 'symfony/framework-bundle') {
                return ltrim($package['version'] ?? '', 'v');
            }
        }

        return null;
    }
}
