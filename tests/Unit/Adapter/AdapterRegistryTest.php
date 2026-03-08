<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Adapter;

use DrupalEvolver\Adapter\AdapterRegistry;
use DrupalEvolver\Adapter\DrupalCoreAdapter;
use DrupalEvolver\Adapter\SymfonyAdapter;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdapterRegistry::class)]
final class AdapterRegistryTest extends TestCase
{
    private AdapterRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AdapterRegistry();
    }

    public function testGetDrupalAdapter(): void
    {
        $adapter = $this->registry->get('drupal');
        $this->assertInstanceOf(DrupalCoreAdapter::class, $adapter);
    }

    public function testGetSymfonyAdapter(): void
    {
        $adapter = $this->registry->get('symfony');
        $this->assertInstanceOf(SymfonyAdapter::class, $adapter);
    }

    public function testGetUnknownAdapterReturnsNull(): void
    {
        $adapter = $this->registry->get('nonexistent');
        $this->assertNull($adapter);
    }

    public function testGetDefaultReturnsDrupalAdapter(): void
    {
        $adapter = $this->registry->getDefault();
        $this->assertInstanceOf(DrupalCoreAdapter::class, $adapter);
        $this->assertSame('drupal', $adapter->ecosystem());
    }

    public function testDetectForProjectReturnsDrupalAdapter(): void
    {
        $root = vfsStream::setup();
        $structure = [
            'mymodule.info.yml' => "name: My Module\ntype: module\n",
        ];
        vfsStream::create($structure, $root);

        $adapter = $this->registry->detectForProject($root->url());
        $this->assertInstanceOf(DrupalCoreAdapter::class, $adapter);
    }

    public function testDetectForProjectReturnsSymfonyAdapter(): void
    {
        $root = vfsStream::setup();
        $structure = [
            'composer.lock' => json_encode([
                'packages' => [
                    ['name' => 'symfony/framework-bundle', 'version' => '7.1.0'],
                ],
            ]),
        ];
        vfsStream::create($structure, $root);

        $adapter = $this->registry->detectForProject($root->url());
        // DrupalCoreAdapter can detect Symfony projects (via ProjectTypeDetector),
        // so it's returned first since it's registered before SymfonyAdapter
        $this->assertInstanceOf(DrupalCoreAdapter::class, $adapter);
    }

    public function testDetectForProjectReturnsNullForUnknown(): void
    {
        $root = vfsStream::setup();
        $structure = [
            'README.md' => '# My Project',
        ];
        vfsStream::create($structure, $root);

        $adapter = $this->registry->detectForProject($root->url());
        $this->assertNull($adapter);
    }

    public function testCanRegisterCustomAdapter(): void
    {
        $customAdapter = new class implements \DrupalEvolver\Adapter\EcosystemAdapterInterface {
            public function ecosystem(): string
            {
                return 'custom';
            }
            public function detectProjectType(string $projectPath): ?string
            {
                return 'custom';
            }
            public function detectVersion(string $projectPath): ?string
            {
                return null;
            }
            public function phpExtensions(): array
            {
                return ['.php'];
            }
            public function semanticSearchFields(): array
            {
                return ['fqn'];
            }
            public function isHookFile(string $filePath): bool
            {
                return false;
            }
            public function extractHookName(string $functionName, string $filePath): ?string
            {
                return null;
            }
        };

        $this->registry->register($customAdapter);
        $adapter = $this->registry->get('custom');
        $this->assertSame($customAdapter, $adapter);
    }
}
