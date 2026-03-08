<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use PHPUnit\Framework\TestCase;

abstract class BaseExtractorTestCase extends TestCase
{
    protected function getFixture(string $path): string
    {
        $fullPath = __DIR__ . '/../../Fixtures/' . ltrim($path, '/');
        if (!file_exists($fullPath)) {
            throw new \InvalidArgumentException("Fixture not found: $fullPath");
        }
        return file_get_contents($fullPath);
    }
}
