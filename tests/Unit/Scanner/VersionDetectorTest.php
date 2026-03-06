<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Scanner;

use DrupalEvolver\Scanner\VersionDetector;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class VersionDetectorTest extends TestCase
{
    private VersionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new VersionDetector();
    }

    public function testDetectVersionFromComposerLock(): void
    {
        $root = vfsStream::setup('project');
        $lockContent = json_encode([
            'packages' => [
                ['name' => 'drupal/core', 'version' => '10.3.5'],
                ['name' => 'symfony/console', 'version' => 'v7.1.0'],
            ],
        ]);
        vfsStream::newFile('composer.lock')->at($root)->setContent($lockContent);

        $version = $this->detector->detect(vfsStream::url('project'));
        $this->assertSame('10.3.5', $version);
    }

    public function testDetectVersionWithVPrefix(): void
    {
        $root = vfsStream::setup('project');
        $lockContent = json_encode([
            'packages' => [
                ['name' => 'drupal/core', 'version' => 'v10.2.0'],
            ],
        ]);
        vfsStream::newFile('composer.lock')->at($root)->setContent($lockContent);

        $version = $this->detector->detect(vfsStream::url('project'));
        $this->assertSame('10.2.0', $version);
    }

    public function testNoComposerLock(): void
    {
        $root = vfsStream::setup('project');
        $this->assertNull($this->detector->detect(vfsStream::url('project')));
    }

    public function testNoDrupalCore(): void
    {
        $root = vfsStream::setup('project');
        $lockContent = json_encode([
            'packages' => [
                ['name' => 'symfony/console', 'version' => 'v7.1.0'],
            ],
        ]);
        vfsStream::newFile('composer.lock')->at($root)->setContent($lockContent);

        $this->assertNull($this->detector->detect(vfsStream::url('project')));
    }
}
