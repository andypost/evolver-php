<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Web;

use DrupalEvolver\Web\WebServer;
use PHPUnit\Framework\TestCase;

final class PreviewPathResolutionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = rtrim(sys_get_temp_dir(), '/') . '/preview-path-' . uniqid('', true);
        mkdir($this->tempDir . '/project/src', 0777, true);
        file_put_contents($this->tempDir . '/project/src/Demo.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        putenv('EVOLVER_SOURCE_PATH_ALIASES');
        $this->removeDir($this->tempDir);
    }

    public function testResolveProjectFilePathUsesSourceAliases(): void
    {
        putenv('EVOLVER_SOURCE_PATH_ALIASES=/mnt/project=' . $this->tempDir);

        $server = (new \ReflectionClass(WebServer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(WebServer::class, 'resolveProjectFilePath');

        $resolved = $method->invoke(
            $server,
            ['/mnt/project/project', '/unused/project'],
            'src/Demo.php'
        );

        $this->assertSame(realpath($this->tempDir . '/project/src/Demo.php'), $resolved);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
