<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Scanner;

use DrupalEvolver\Scanner\ProjectTypeDetector;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectTypeDetector::class)]
final class ProjectTypeDetectorTest extends TestCase
{
    private ProjectTypeDetector $detector;
    private vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->detector = new ProjectTypeDetector();
        $this->root = vfsStream::setup();
    }

    public function testDetectDrupalCore(): void
    {
        $structure = [
            'core' => [
                'lib' => [
                    'Drupal.php' => '<?php namespace Drupal;',
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_CORE, $type);
    }

    public function testDetectDrupalCoreByComposer(): void
    {
        $structure = [
            'core' => [
                'composer.json' => json_encode(['name' => 'drupal/core']),
            ],
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_CORE, $type);
    }

    public function testDetectDrupalSite(): void
    {
        $structure = [
            'sites' => [
                'default' => [
                    'settings.php' => '<?php',
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_SITE, $type);
    }

    public function testDoNotDetectSiteInsideCore(): void
    {
        $structure = [
            'core' => [
                'lib' => [
                    'Drupal.php' => '<?php',
                ],
                'sites' => [
                    'default' => [
                        'settings.php' => '<?php',
                    ],
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_CORE, $type);
    }

    public function testDetectDrupalModuleByInfoYml(): void
    {
        $structure = [
            'mymodule.info.yml' => "name: My Module\ntype: module\n",
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_MODULE, $type);
    }

    public function testDetectDrupalThemeByInfoYml(): void
    {
        $structure = [
            'mytheme.info.yml' => "name: My Theme\ntype: theme\n",
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_THEME, $type);
    }

    public function testDetectDrupalProfileByInfoYml(): void
    {
        $structure = [
            'myprofile.info.yml' => "name: My Profile\ntype: profile\n",
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_PROFILE, $type);
    }

    public function testDetectDrupalModuleByExtension(): void
    {
        $structure = [
            'mymodule.module' => '<?php',
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_MODULE, $type);
    }

    public function testDetectDrupalThemeByExtension(): void
    {
        $structure = [
            'mytheme.theme' => '<?php',
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_THEME, $type);
    }

    public function testDetectDrupalProfileByExtension(): void
    {
        $structure = [
            'myprofile.profile' => '<?php',
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_PROFILE, $type);
    }

    public function testDetectSymfonyProject(): void
    {
        $structure = [
            'composer.lock' => json_encode([
                'packages' => [
                    ['name' => 'symfony/framework-bundle', 'version' => '7.1.0'],
                ],
            ]),
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_SYMFONY, $type);
    }

    public function testReturnNullForUnknownProject(): void
    {
        $structure = [
            'README.md' => '# My Project',
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertNull($type);
    }

    public function testReturnNullForNonExistentPath(): void
    {
        $type = $this->detector->detect('/nonexistent/path');
        $this->assertNull($type);
    }

    public function testDrupalCoreTakesPrecedence(): void
    {
        $structure = [
            'core' => [
                'lib' => [
                    'Drupal.php' => '<?php',
                ],
            ],
            'mymodule.module' => '<?php',
        ];
        vfsStream::create($structure, $this->root);

        $type = $this->detector->detect($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_CORE, $type);
    }

    public function testDetectMetadataForDrupalCore(): void
    {
        $structure = [
            'core' => [
                'lib' => [
                    'Drupal.php' => '<?php namespace Drupal;',
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        $metadata = $this->detector->detectMetadata($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_CORE, $metadata['type']);
        $this->assertSame('drupal/core', $metadata['package_name']);
        $this->assertNull($metadata['root_name']);
    }

    public function testDetectMetadataForDrupalSite(): void
    {
        $structure = [
            'sites' => [
                'default' => [
                    'settings.php' => '<?php',
                ],
            ],
            'composer.json' => json_encode(['name' => 'drupal/custom-site']),
        ];
        vfsStream::create($structure, $this->root);

        $metadata = $this->detector->detectMetadata($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_SITE, $metadata['type']);
        $this->assertSame('drupal/custom-site', $metadata['package_name']);
        $this->assertNull($metadata['root_name']);
    }

    public function testDetectMetadataForDrupalModule(): void
    {
        $structure = [
            'my_module.info.yml' => "name: My Cool Module\ntype: module\n",
            'composer.json' => json_encode(['name' => 'drupal/my_custom_module']),
        ];
        vfsStream::create($structure, $this->root);

        $metadata = $this->detector->detectMetadata($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_MODULE, $metadata['type']);
        $this->assertSame('drupal/my_custom_module', $metadata['package_name']);
        $this->assertSame('My Cool Module', $metadata['root_name']);
    }

    public function testDetectMetadataForDrupalTheme(): void
    {
        $structure = [
            'my_theme.info.yml' => "name: My Awesome Theme\ntype: theme\n",
            'composer.json' => json_encode(['name' => 'drupal/my_custom_theme']),
        ];
        vfsStream::create($structure, $this->root);

        $metadata = $this->detector->detectMetadata($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_THEME, $metadata['type']);
        $this->assertSame('drupal/my_custom_theme', $metadata['package_name']);
        $this->assertSame('My Awesome Theme', $metadata['root_name']);
    }

    public function testDetectMetadataForSymfonyProject(): void
    {
        $structure = [
            'composer.lock' => json_encode([
                'packages' => [
                    ['name' => 'symfony/framework-bundle', 'version' => '7.1.0'],
                ],
            ]),
            'composer.json' => json_encode(['name' => 'my/app']),
        ];
        vfsStream::create($structure, $this->root);

        $metadata = $this->detector->detectMetadata($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_SYMFONY, $metadata['type']);
        $this->assertSame('my/app', $metadata['package_name']);
        $this->assertNull($metadata['root_name']);
    }

    public function testDetectMetadataForUnknownProject(): void
    {
        $structure = [
            'README.md' => '# My Project',
        ];
        vfsStream::create($structure, $this->root);

        $metadata = $this->detector->detectMetadata($this->root->url());
        $this->assertNull($metadata['type']);
        $this->assertNull($metadata['package_name']);
        $this->assertNull($metadata['root_name']);
    }

    public function testDetectMetadataHandlesMissingComposerJson(): void
    {
        $structure = [
            'my_module.info.yml' => "name: My Module\ntype: module\n",
        ];
        vfsStream::create($structure, $this->root);

        $metadata = $this->detector->detectMetadata($this->root->url());
        $this->assertSame(ProjectTypeDetector::TYPE_DRUPAL_MODULE, $metadata['type']);
        $this->assertNull($metadata['package_name']);
        $this->assertSame('My Module', $metadata['root_name']);
    }
}
