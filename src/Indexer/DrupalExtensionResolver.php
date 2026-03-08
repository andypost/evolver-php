<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer;

/**
 * Resolves Drupal extension name (module/theme) from a file path.
 */
class DrupalExtensionResolver
{
    private array $cache = [];

    public function resolve(string $filePath): ?string
    {
        $directory = dirname($filePath);
        if (isset($this->cache[$directory])) {
            return $this->cache[$directory];
        }

        $current = $directory;
        while ($current !== '/' && $current !== '.') {
            // Optimization: check for .info.yml files in the directory
            $infos = glob($current . '/*.info.yml');
            if (!empty($infos)) {
                $extension = basename($infos[0], '.info.yml');
                return $this->cache[$directory] = $extension;
            }
            $parent = dirname($current);
            if ($parent === $current) break;
            $current = $parent;
        }

        return $this->cache[$directory] = null;
    }
}
