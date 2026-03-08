<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Integration;

use DrupalEvolver\Adapter\DrupalCoreAdapter;
use DrupalEvolver\Adapter\SymfonyAdapter;
use DrupalEvolver\Indexer\FileClassifier;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ecosystem adapters.
 *
 * Tests that adapters work correctly with FileClassifier and other
 * components that depend on them.
 */
final class AdapterIntegrationTest extends TestCase
{
    private vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup();
    }

    public function testDrupalCoreAdapterClassifiesDrupalFiles(): void
    {
        $adapter = new DrupalCoreAdapter();
        $classifier = new FileClassifier($adapter);

        // Test all Drupal-specific PHP extensions
        $this->assertSame('php', $classifier->classify('mymodule.module'));
        $this->assertSame('php', $classifier->classify('mytheme.theme'));
        $this->assertSame('php', $classifier->classify('myprofile.profile'));
        $this->assertSame('php', $classifier->classify('my.install'));
        $this->assertSame('php', $classifier->classify('my.inc'));
        $this->assertSame('php', $classifier->classify('my.engine'));
        $this->assertSame('php', $classifier->classify('Class.php'));
    }

    public function testSymfonyAdapterClassifiesOnlyPhpFiles(): void
    {
        $adapter = new SymfonyAdapter();
        $classifier = new FileClassifier($adapter);

        // Only .php files should be classified as PHP
        $this->assertSame('php', $classifier->classify('Controller.php'));

        // Drupal-specific extensions should NOT be classified as PHP by Symfony adapter
        $this->assertNull($classifier->classify('mymodule.module'));
        $this->assertNull($classifier->classify('mytheme.theme'));
        $this->assertNull($classifier->classify('myprofile.profile'));
        $this->assertNull($classifier->classify('my.install'));
        $this->assertNull($classifier->classify('my.inc'));
        $this->assertNull($classifier->classify('my.engine'));
    }

    public function testDrupalAdapterDetectsAllProjectTypes(): void
    {
        $adapter = new DrupalCoreAdapter();

        // Test Drupal module
        $root1 = vfsStream::setup('module');
        vfsStream::create(['mymodule.info.yml' => "type: module\n"], $root1);
        $this->assertSame('drupal-module', $adapter->detectProjectType($root1->url()));

        // Test Drupal theme
        $root2 = vfsStream::setup('theme');
        vfsStream::create(['mytheme.info.yml' => "type: theme\n"], $root2);
        $this->assertSame('drupal-theme', $adapter->detectProjectType($root2->url()));

        // Test Drupal profile
        $root3 = vfsStream::setup('profile');
        vfsStream::create(['myprofile.info.yml' => "type: profile\n"], $root3);
        $this->assertSame('drupal-profile', $adapter->detectProjectType($root3->url()));

        // Test Drupal core
        $root4 = vfsStream::setup('core');
        vfsStream::create(['core' => ['lib' => ['Drupal.php' => '<?php']]], $root4);
        $this->assertSame('drupal-core', $adapter->detectProjectType($root4->url()));

        // Test Drupal site
        $root5 = vfsStream::setup('site');
        vfsStream::create(['sites' => ['default' => ['settings.php' => '<?php']]], $root5);
        $this->assertSame('drupal-site', $adapter->detectProjectType($root5->url()));
    }

    public function testSymfonyAdapterDetectsSymfonyProject(): void
    {
        $adapter = new SymfonyAdapter();

        // Create a composer.lock with symfony/framework-bundle
        $composerLock = json_encode([
            'packages' => [
                ['name' => 'symfony/framework-bundle', 'version' => '7.1.0'],
                ['name' => 'symfony/console', 'version' => '7.1.0'],
            ],
        ]);

        $root = vfsStream::setup('symfony');
        vfsStream::create(['composer.lock' => $composerLock], $root);
        $this->assertSame('symfony', $adapter->detectProjectType($root->url()));
    }

    public function testSymfonyAdapterReturnsNullForNonSymfonyProjects(): void
    {
        $adapter = new SymfonyAdapter();

        // Create a Drupal module
        $root1 = vfsStream::setup('drupal-module');
        vfsStream::create(['mymodule.info.yml' => "type: module\n"], $root1);
        $this->assertNull($adapter->detectProjectType($root1->url()));

        // Empty directory
        $root2 = vfsStream::setup('empty');
        $this->assertNull($adapter->detectProjectType($root2->url()));
    }

    public function testDrupalAdapterHookDetection(): void
    {
        $adapter = new DrupalCoreAdapter();

        // Module hooks
        $this->assertTrue($adapter->isHookFile('mymodule.module'));
        $this->assertSame('menu', $adapter->extractHookName('mymodule_menu', 'mymodule.module'));
        $this->assertSame('preprocess_page', $adapter->extractHookName('mymodule_preprocess_page', 'mymodule.module'));

        // Theme hooks
        $this->assertTrue($adapter->isHookFile('mytheme.theme'));
        $this->assertSame('preprocess_page', $adapter->extractHookName('mytheme_preprocess_page', 'mytheme.theme'));

        // Install hooks
        $this->assertTrue($adapter->isHookFile('mymodule.install'));
        $this->assertSame('uninstall', $adapter->extractHookName('mymodule_uninstall', 'mymodule.install'));

        // Profile hooks
        $this->assertTrue($adapter->isHookFile('myprofile.profile'));

        // Non-hook files
        $this->assertFalse($adapter->isHookFile('src/Controller.php'));
        $this->assertFalse($adapter->isHookFile('services.yml'));

        // Edge cases for preprocess/process
        $this->assertNull($adapter->extractHookName('mytheme_preprocess', 'mytheme.theme'));
        $this->assertNull($adapter->extractHookName('mytheme_process', 'mytheme.theme'));
    }

    public function testSymfonyAdapterHasNoHooks(): void
    {
        $adapter = new SymfonyAdapter();

        // Symfony adapter doesn't support hooks
        $this->assertFalse($adapter->isHookFile('Controller.php'));
        $this->assertFalse($adapter->isHookFile('any.file'));
        $this->assertNull($adapter->extractHookName('some_function', 'any.php'));
    }

    public function testDrupalAdapterSemanticSearchFields(): void
    {
        $adapter = new DrupalCoreAdapter();
        $fields = $adapter->semanticSearchFields();

        $expectedFields = [
            'fqn', 'name', 'label', 'class', 'path', 'controller',
            'configure_route', 'base_theme', 'route_name', 'base_route', 'parent_id',
            'mentioned_extensions', 'dependency_targets', 'route_refs',
            'install', 'recipes', 'dependencies',
        ];

        $this->assertSame($expectedFields, $fields);
    }

    public function testSymfonyAdapterSemanticSearchFields(): void
    {
        $adapter = new SymfonyAdapter();
        $fields = $adapter->semanticSearchFields();

        $expectedFields = ['fqn', 'name', 'class'];

        $this->assertSame($expectedFields, $fields);
    }

    public function testAdaptersHaveDifferentPhpExtensions(): void
    {
        $drupalAdapter = new DrupalCoreAdapter();
        $symfonyAdapter = new SymfonyAdapter();

        $drupalExtensions = $drupalAdapter->phpExtensions();
        $symfonyExtensions = $symfonyAdapter->phpExtensions();

        // Drupal has more extensions
        $this->assertCount(7, $drupalExtensions);
        $this->assertContains('.module', $drupalExtensions);
        $this->assertContains('.theme', $drupalExtensions);
        $this->assertContains('.install', $drupalExtensions);

        // Symfony only has .php
        $this->assertSame(['.php'], $symfonyExtensions);
    }

    public function testBothAdaptersCanDetectVersions(): void
    {
        $drupalAdapter = new DrupalCoreAdapter();
        $symfonyAdapter = new SymfonyAdapter();

        // Drupal version detection
        $drupalLock = json_encode([
            'packages' => [
                ['name' => 'drupal/core', 'version' => '10.3.0'],
            ],
        ]);

        $root1 = vfsStream::setup('drupal-version');
        vfsStream::create(['composer.lock' => $drupalLock], $root1);
        $this->assertSame('10.3.0', $drupalAdapter->detectVersion($root1->url()));

        // Symfony version detection
        $symfonyLock = json_encode([
            'packages' => [
                ['name' => 'symfony/framework-bundle', 'version' => '7.1.0'],
            ],
        ]);

        $root2 = vfsStream::setup('symfony-version');
        vfsStream::create(['composer.lock' => $symfonyLock], $root2);
        $this->assertSame('7.1.0', $symfonyAdapter->detectVersion($root2->url()));
    }

    public function testEcosystemNames(): void
    {
        $drupalAdapter = new DrupalCoreAdapter();
        $symfonyAdapter = new SymfonyAdapter();

        $this->assertSame('drupal', $drupalAdapter->ecosystem());
        $this->assertSame('symfony', $symfonyAdapter->ecosystem());
    }
}
