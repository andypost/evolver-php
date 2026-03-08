<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

class VersionDetector
{
    public function detect(string $projectPath): ?string
    {
        $projectPath = rtrim($projectPath, '/');

        // 1. Try composer.lock
        $lockFile = $projectPath . '/composer.lock';
        if (file_exists($lockFile)) {
            $lock = json_decode(file_get_contents($lockFile), true);
            if ($lock) {
                $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
                foreach ($packages as $package) {
                    if ($package['name'] === 'drupal/core') {
                        return ltrim($package['version'] ?? '', 'v');
                    }
                    if ($package['name'] === 'symfony/framework-bundle') {
                        return ltrim($package['version'] ?? '', 'v');
                    }
                }
            }
        }

        // 2. Try composer.json (less reliable as it is usually a range)
        $composerFile = $projectPath . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);
            if ($composer) {
                $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
                if (isset($require['drupal/core'])) {
                    $version = $this->normalizeRequirement($require['drupal/core']);
                    if ($version) return $version;
                }
            }
        }

        // 3. Try *.info.yml or *.info
        if (is_dir($projectPath)) {
            $iterator = new \DirectoryIterator($projectPath);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    $filename = $fileInfo->getFilename();
                    $content = file_get_contents($fileInfo->getPathname());
                    if (!$content) continue;

                    if (str_ends_with($filename, '.info.yml')) {
                        if (preg_match('/^\s*core_version_requirement\s*:\s*(.+)$/m', $content, $matches)) {
                            $version = $this->normalizeRequirement(trim($matches[1], '"\' '));
                            if ($version) return $version;
                        }
                        if (preg_match('/^\s*core\s*:\s*(\d+\.x)/m', $content, $matches)) {
                            return str_replace('.x', '.0.0', $matches[1]);
                        }
                    } elseif (str_ends_with($filename, '.info')) {
                        // Drupal 7 style .info files
                        if (preg_match('/^\s*core\s*=\s*(\d+)\.x/m', $content, $matches)) {
                            return $matches[1] . '.0.0';
                        }
                    }
                }
            }
        }

        return null;
    }

    private function normalizeRequirement(string $requirement): ?string
    {
        // If it looks like a single version, return it
        if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $requirement, $matches)) {
            return $matches[1];
        }

        // Handle common ranges like ^10, ~10.3, >11.3
        // For Evolver, we often just need *some* base version to start from.
        // We'll pick a conservative "base" version for these ranges.
        if (preg_match('/[\^~>](\d+)(\.\d+)?(\.\d+)?/', $requirement, $matches)) {
            $major = $matches[1];
            $minor = isset($matches[2]) ? ltrim($matches[2], '.') : '0';
            $patch = isset($matches[3]) ? ltrim($matches[3], '.') : '0';
            return "{$major}.{$minor}.{$patch}";
        }

        return null;
    }
}
