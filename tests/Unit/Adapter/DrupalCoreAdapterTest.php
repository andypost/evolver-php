<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Adapter;

use DrupalEvolver\Adapter\DrupalCoreAdapter;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DrupalCoreAdapter::class)]
final class DrupalCoreAdapterTest extends TestCase
{
    private DrupalCoreAdapter $adapter;
    private vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->adapter = new DrupalCoreAdapter();
        $this->root = vfsStream::setup();
    }

    public function testEcosystem(): void
    {
        $this->assertSame('drupal', $this->adapter->ecosystem());
    }

    public function testPhpExtensions(): void
    {
        $extensions = $this->adapter->phpExtensions();
        $expected = ['.php', '.module', '.inc', '.install', '.profile', '.theme', '.engine'];
        $this->assertSame($expected, $extensions);
    }

    public function testSemanticSearchFields(): void
    {
        $fields = $this->adapter->semanticSearchFields();
        $expected = [
            'fqn', 'name', 'label', 'class', 'path', 'controller',
            'configure_route', 'base_theme', 'route_name', 'base_route', 'parent_id',
            'mentioned_extensions', 'dependency_targets', 'route_refs',
            'install', 'recipes', 'dependencies',
        ];
        $this->assertSame($expected, $fields);
    }

    public function testIsHookFileForModule(): void
    {
        $this->assertTrue($this->adapter->isHookFile('mymodule.module'));
    }

    public function testIsHookFileForInstall(): void
    {
        $this->assertTrue($this->adapter->isHookFile('mymodule.install'));
    }

    public function testIsHookFileForProfile(): void
    {
        $this->assertTrue($this->adapter->isHookFile('myprofile.profile'));
    }

    public function testIsHookFileForTheme(): void
    {
        $this->assertTrue($this->adapter->isHookFile('mytheme.theme'));
    }

    public function testIsHookFileForPhp(): void
    {
        $this->assertFalse($this->adapter->isHookFile('src/Controller.php'));
    }

    public function testExtractHookName(): void
    {
        $hookName = $this->adapter->extractHookName('mymodule_menu', 'mymodule.module');
        $this->assertSame('menu', $hookName);
    }

    public function testExtractHookNameForTheme(): void
    {
        $hookName = $this->adapter->extractHookName('mytheme_preprocess_page', 'mytheme.theme');
        $this->assertSame('preprocess_page', $hookName);
    }

    public function testExtractHookNameReturnsNullForPreprocessOnly(): void
    {
        $hookName = $this->adapter->extractHookName('mytheme_preprocess', 'mytheme.theme');
        $this->assertNull($hookName);
    }

    public function testExtractHookNameReturnsNullForProcessOnly(): void
    {
        $hookName = $this->adapter->extractHookName('mytheme_process', 'mytheme.theme');
        $this->assertNull($hookName);
    }

    public function testExtractHookNameReturnsNullForNonHook(): void
    {
        $hookName = $this->adapter->extractHookName('my_helper_function', 'mymodule.module');
        $this->assertNull($hookName);
    }

    public function testDetectProjectType(): void
    {
        $structure = [
            'mymodule.info.yml' => "name: My Module\ntype: module\n",
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->adapter->detectProjectType($this->root->url());
        $this->assertSame('drupal-module', $type);
    }

    public function testDetectVersion(): void
    {
        $structure = [
            'composer.lock' => json_encode([
                'packages' => [
                    ['name' => 'drupal/core', 'version' => '10.3.0'],
                ],
            ]),
        ];
        vfsStream::create($structure, $this->root);

        $version = $this->adapter->detectVersion($this->root->url());
        $this->assertSame('10.3.0', $version);
    }

    public function testDetectVersionReturnsNullWithoutComposerLock(): void
    {
        $version = $this->adapter->detectVersion($this->root->url());
        $this->assertNull($version);
    }
}
