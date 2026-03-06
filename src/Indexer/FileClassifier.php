<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer;

class FileClassifier
{
    private const PHP_EXTENSIONS = ['.php', '.module', '.inc', '.install', '.profile', '.theme', '.engine'];
    private const YAML_EXTENSIONS = ['.yml', '.yaml'];
    private const JS_EXTENSIONS = ['.js', '.mjs'];
    private const CSS_EXTENSIONS = ['.css'];

    public function classify(string $filePath): ?string
    {
        $fileName = basename($filePath);
        if (str_ends_with($fileName, '.libraries.yml')) {
            return 'drupal_libraries';
        }

        $ext = '.' . strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($ext, self::PHP_EXTENSIONS, true)) {
            return 'php';
        }

        if (in_array($ext, self::YAML_EXTENSIONS, true)) {
            return 'yaml';
        }

        if (in_array($ext, self::JS_EXTENSIONS, true)) {
            return 'javascript';
        }

        if (in_array($ext, self::CSS_EXTENSIONS, true)) {
            return 'css';
        }

        return null;
    }
}
