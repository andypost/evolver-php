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
        ];

        $changes = $this->differ->findRemovedChanges($removed);

        $this->assertCount(4, $changes);
        $this->assertSame('service_removed', $changes[0]['change_type']);
        $this->assertSame('route_removed', $changes[1]['change_type']);
        $this->assertSame('permission_removed', $changes[2]['change_type']);
        $this->assertSame('config_key_removed', $changes[3]['change_type']);
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
        $this->assertSame('service_renamed', $changes[0]['change_type']);
        $this->assertSame('old.service', $changes[0]['old']['fqn']);
        $this->assertSame('new.service', $changes[0]['new']['fqn']);
        $this->assertGreaterThanOrEqual(0.78, $changes[0]['confidence']);
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
        $this->assertSame('service_class_changed', $changes[0]['change_type']);
        $this->assertNotNull($changes[0]['diff_json']);
        $details = json_decode((string) $changes[0]['diff_json'], true);
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
        $this->assertSame('route_changed', $changes[0]['change_type']);
        $details = json_decode((string) $changes[0]['diff_json'], true);
        $this->assertSame('/old', $details['old_path']);
        $this->assertSame('/new', $details['new_path']);
    }
}
