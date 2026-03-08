<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer;

use DrupalEvolver\Adapter\EcosystemAdapterInterface;

class FileClassifier
{
    private const YAML_EXTENSIONS = ['.yml', '.yaml'];
    private const JS_EXTENSIONS = ['.js', '.mjs'];
    private const CSS_EXTENSIONS = ['.css'];

    public function __construct(
        private EcosystemAdapterInterface $adapter,
    ) {}

    public function classify(string $filePath): ?string
    {
        $fileName = strtolower(basename($filePath));
        
        if (str_ends_with($fileName, '.libraries.yml')) {
            return 'drupal_libraries';
        }

        if (str_ends_with($fileName, '.twig')) {
            return 'twig';
        }

        $ext = '.' . strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($ext, $this->adapter->phpExtensions(), true)) {
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
