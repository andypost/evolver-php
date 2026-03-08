<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Differ;

use DrupalEvolver\Differ\YAMLDiffer;
use PHPUnit\Framework\TestCase;

class YAMLDifferTest extends TestCase
{
    private YAMLDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new YAMLDiffer();
    }

    public function testFindRemovedChanges(): void
    {
        $removed = [
            ['id' => 1, 'language' => 'yaml', 'symbol_type' => 'service', 'fqn' => 'old.service'],
            ['id' => 2, 'language' => 'yaml', 'symbol_type' => 'route', 'fqn' => 'old.route'],
            ['id' => 3, 'language' => 'yaml', 'symbol_type' => 'permission', 'fqn' => 'old permission'],
            ['id' => 4, 'language' => 'yaml', 'symbol_type' => 'config_schema', 'fqn' => 'mod.settings'],
            ['id' => 5, 'language' => 'yaml', 'symbol_type' => 'library', 'fqn' => 'mod/base'],
            ['id' => 6, 'language' => 'yaml', 'symbol_type' => 'module_info', 'fqn' => 'example'],
            ['id' => 7, 'language' => 'yaml', 'symbol_type' => 'config_export', 'fqn' => 'system.site'],
        ];

        $changes = $this->differ->findRemovedChanges($removed);

        $this->assertCount(6, $changes);
        $this->assertSame('service_removed', $changes[0]->changeType);
        $this->assertSame('route_removed', $changes[1]->changeType);
        $this->assertSame('permission_removed', $changes[2]->changeType);
        $this->assertSame('config_key_removed', $changes[3]->changeType);
        $this->assertSame('module_info_removed', $changes[4]->changeType);
        $this->assertSame('config_object_removed', $changes[5]->changeType);
    }

    public function testFindRenameChangesForService(): void
    {
        $removed = [[
            'id' => 10,
            'language' => 'yaml',
            'symbol_type' => 'service',
            'fqn' => 'old.service',
            'name' => 'old.service',
            'signature_json' => '{"class":"Drupal\\\\Core\\\\OldService","arguments":["@logger.factory"],"tags":[{"name":"foo"}]}',
            'source_text' => "old.service:\n  class: Drupal\\Core\\OldService\n  arguments: ['@logger.factory']",
        ]];

        $added = [[
            'id' => 20,
            'language' => 'yaml',
            'symbol_type' => 'service',
            'fqn' => 'new.service',
            'name' => 'new.service',
            'signature_json' => '{"class":"Drupal\\\\Core\\\\OldService","arguments":["@logger.factory"],"tags":[{"name":"foo"}]}',
            'source_text' => "new.service:\n  class: Drupal\\Core\\OldService\n  arguments: ['@logger.factory']",
        ]];

        $changes = $this->differ->findRenameChanges($removed, $added);

        $this->assertCount(1, $changes);
        $this->assertSame('service_renamed', $changes[0]->changeType);
        $this->assertSame('old.service', $changes[0]->oldSymbol['fqn']);
        $this->assertSame('new.service', $changes[0]->newSymbol['fqn']);
        $this->assertGreaterThanOrEqual(0.78, $changes[0]->confidence);
    }

    public function testFindChangedChangesForServiceClassChange(): void
    {
        $changed = [[
            'old' => [
                'id' => 11,
                'language' => 'yaml',
                'symbol_type' => 'service',
                'fqn' => 'my.service',
                'signature_json' => '{"class":"Drupal\\\\Core\\\\OldClass","arguments":["@logger.factory"]}',
            ],
            'new' => [
                'id' => 22,
                'language' => 'yaml',
                'symbol_type' => 'service',
                'fqn' => 'my.service',
                'signature_json' => '{"class":"Drupal\\\\Core\\\\NewClass","arguments":["@logger.factory"]}',
            ],
        ]];

        $changes = $this->differ->findChangedChanges($changed);

        $this->assertCount(1, $changes);
        $this->assertSame('service_class_changed', $changes[0]->changeType);
        $this->assertNotNull($changes[0]->diffDetails);
        $details = $changes[0]->diffDetails;
        $this->assertSame('Drupal\\Core\\OldClass', $details['old_class']);
        $this->assertSame('Drupal\\Core\\NewClass', $details['new_class']);
    }

    public function testFindChangedChangesForRoute(): void
    {
        $changed = [[
            'old' => [
                'id' => 31,
                'language' => 'yaml',
                'symbol_type' => 'route',
                'fqn' => 'mymodule.route',
                'signature_json' => '{"path":"/old","controller":"Drupal\\\\M\\\\Controller\\\\Old::view"}',
            ],
            'new' => [
                'id' => 32,
                'language' => 'yaml',
                'symbol_type' => 'route',
                'fqn' => 'mymodule.route',
                'signature_json' => '{"path":"/new","controller":"Drupal\\\\M\\\\Controller\\\\New::view"}',
            ],
        ]];

        $changes = $this->differ->findChangedChanges($changed);

        $this->assertCount(1, $changes);
        $this->assertSame('route_changed', $changes[0]->changeType);
        $details = $changes[0]->diffDetails;
        $this->assertSame('/old', $details['old_path']);
        $this->assertSame('/new', $details['new_path']);
    }

    public function testFindChangedChangesForModuleDependencyChange(): void
    {
        $changed = [[
            'old' => [
                'id' => 41,
                'language' => 'yaml',
                'symbol_type' => 'module_info',
                'fqn' => 'example',
                'signature_json' => '{"dependencies":["drupal:block","drupal:node"],"configure":"example.settings"}',
            ],
            'new' => [
                'id' => 42,
                'language' => 'yaml',
                'symbol_type' => 'module_info',
                'fqn' => 'example',
                'signature_json' => '{"dependencies":["drupal:block","drupal:views"],"configure":"example.settings"}',
            ],
        ]];

        $changes = $this->differ->findChangedChanges($changed);

        $this->assertCount(1, $changes);
        $this->assertSame('module_dependencies_changed', $changes[0]->changeType);
        $details = $changes[0]->diffDetails;
        $this->assertSame(['drupal:views'], $details['added_dependencies']);
        $this->assertSame(['drupal:node'], $details['removed_dependencies']);
    }

    public function testFindChangedChangesForConfigExport(): void
    {
        $changed = [[
            'old' => [
                'id' => 51,
                'language' => 'yaml',
                'symbol_type' => 'config_export',
                'fqn' => 'system.site',
                'signature_json' => '{"status":true,"name":"Old"}',
            ],
            'new' => [
                'id' => 52,
                'language' => 'yaml',
                'symbol_type' => 'config_export',
                'fqn' => 'system.site',
                'signature_json' => '{"status":false,"name":"Old","mail":"admin@example.com"}',
            ],
        ]];

        $changes = $this->differ->findChangedChanges($changed);

        $this->assertCount(1, $changes);
        $this->assertSame('config_object_changed', $changes[0]->changeType);
        $details = $changes[0]->diffDetails;
        $this->assertSame(['mail'], $details['added_top_level_keys']);
        $this->assertContains('status', $details['changed_top_level_keys']);
    }

    public function testFindChangedChangesForRecipeInstallChange(): void
    {
        $changed = [[
            'old' => [
                'id' => 61,
                'language' => 'yaml',
                'symbol_type' => 'recipe_manifest',
                'fqn' => 'standard',
                'signature_json' => '{"install":["block"],"recipes":["basic_html_format"]}',
            ],
            'new' => [
                'id' => 62,
                'language' => 'yaml',
                'symbol_type' => 'recipe_manifest',
                'fqn' => 'standard',
                'signature_json' => '{"install":["block","node"],"recipes":["basic_html_format"]}',
            ],
        ]];

        $changes = $this->differ->findChangedChanges($changed);

        $this->assertCount(1, $changes);
        $this->assertSame('recipe_install_changed', $changes[0]->changeType);
        $details = $changes[0]->diffDetails;
        $this->assertSame(['node'], $details['added_install']);
        $this->assertSame([], $details['removed_install']);
    }
}
