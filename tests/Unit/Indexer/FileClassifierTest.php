<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Adapter\DrupalCoreAdapter;
use DrupalEvolver\Indexer\FileClassifier;
use PHPUnit\Framework\TestCase;

class FileClassifierTest extends TestCase
{
    private FileClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new FileClassifier(new DrupalCoreAdapter());
    }

    public function testPhpExtensions(): void
    {
        $this->assertSame('php', $this->classifier->classify('module.php'));
        $this->assertSame('php', $this->classifier->classify('mymodule.module'));
        $this->assertSame('php', $this->classifier->classify('mymodule.inc'));
        $this->assertSame('php', $this->classifier->classify('mymodule.install'));
        $this->assertSame('php', $this->classifier->classify('mymodule.profile'));
        $this->assertSame('php', $this->classifier->classify('mymodule.theme'));
        $this->assertSame('php', $this->classifier->classify('mymodule.engine'));
    }

    public function testYamlExtensions(): void
    {
        $this->assertSame('yaml', $this->classifier->classify('services.yml'));
        $this->assertSame('yaml', $this->classifier->classify('config.yaml'));
    }

    public function testNewExtensions(): void
    {
        $this->assertSame('javascript', $this->classifier->classify('script.js'));
        $this->assertSame('javascript', $this->classifier->classify('script.mjs'));
        $this->assertSame('css', $this->classifier->classify('style.css'));
        $this->assertSame('drupal_libraries', $this->classifier->classify('core.libraries.yml'));
        $this->assertSame('twig', $this->classifier->classify('template.twig'));
        $this->assertSame('twig', $this->classifier->classify('template.html.twig'));
        $this->assertSame('twig', $this->classifier->classify('component.html.twig'));
    }

    public function testUnknownExtensions(): void
    {
        $this->assertNull($this->classifier->classify('file.txt'));
        $this->assertNull($this->classifier->classify('README.md'));
        $this->assertNull($this->classifier->classify('package.json'));
    }

    public function testFullPaths(): void
    {
        $this->assertSame('php', $this->classifier->classify('/var/www/drupal/core/lib/Drupal.php'));
        $this->assertSame('yaml', $this->classifier->classify('/var/www/drupal/core/core.services.yml'));
        $this->assertSame('drupal_libraries', $this->classifier->classify('/var/www/drupal/core/core.libraries.yml'));
        $this->assertNull($this->classifier->classify('/var/www/drupal/core/README.txt'));
    }
}
