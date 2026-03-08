<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Integration;

use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

final class ExtensionImpactTest extends TestCase
{
    public function testExtensionGraphImpactCalculation(): void
    {
        $api = new DatabaseApi(':memory:');
        
        $fromId = $api->versions()->create('1.0.0', 1, 0, 0);
        $toId = $api->versions()->create('1.1.0', 1, 1, 0);

        // 1. Setup Extensions
        // my_module (module)
        $this->assertGreaterThan(0, $api->db()->execute(
            'INSERT INTO extensions (version_id, machine_name, extension_type, label, dependencies, file_path)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$fromId, 'my_module', 'module', 'My Module', '[]', 'modules/my_module/my_module.info.yml']
        ));
        $this->assertGreaterThan(0, $api->db()->execute(
            'INSERT INTO extensions (version_id, machine_name, extension_type, label, dependencies, file_path)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$toId, 'my_module', 'module', 'My Module', '[]', 'modules/my_module/my_module.info.yml']
        ));

        // my_theme (theme) - depends on my_module
        $this->assertGreaterThan(0, $api->db()->execute(
            'INSERT INTO extensions (version_id, machine_name, extension_type, label, dependencies, file_path)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$fromId, 'my_theme', 'theme', 'My Theme', '["my_module"]', 'themes/my_theme/my_theme.info.yml']
        ));
        $this->assertGreaterThan(0, $api->db()->execute(
            'INSERT INTO extensions (version_id, machine_name, extension_type, label, dependencies, file_path)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$toId, 'my_theme', 'theme', 'My Theme', '["my_module"]', 'themes/my_theme/my_theme.info.yml']
        ));

        // 2. Setup Changes in my_module
        $fileId = $api->files()->create($fromId, 'modules/my_module/src/Func.php', 'php', 'h1', null, null, 10, 100);
        
        // Change 1: removed function
        $sym1 = $api->symbols()->create([
            'version_id' => $fromId, 'file_id' => $fileId, 'language' => 'php',
            'symbol_type' => 'function', 'fqn' => 'my_module_deprecated', 'name' => 'my_module_deprecated'
        ]);
        $this->assertGreaterThan(0, $api->changes()->create([
            'from_version_id' => $fromId, 'to_version_id' => $toId, 'language' => 'php',
            'change_type' => 'function_removed', 'severity' => 'breaking',
            'old_symbol_id' => $sym1, 'old_fqn' => 'my_module_deprecated'
        ]));

        // Change 2: another removed function
        $sym2 = $api->symbols()->create([
            'version_id' => $fromId, 'file_id' => $fileId, 'language' => 'php',
            'symbol_type' => 'function', 'fqn' => 'my_module_old', 'name' => 'my_module_old'
        ]);
        $this->assertGreaterThan(0, $api->changes()->create([
            'from_version_id' => $fromId, 'to_version_id' => $toId, 'language' => 'php',
            'change_type' => 'function_removed', 'severity' => 'breaking',
            'old_symbol_id' => $sym2, 'old_fqn' => 'my_module_old'
        ]));

        // 3. Analyze Impact
        $graph = $api->getExtensionImpactGraph($fromId, $toId);

        $this->assertCount(2, $graph);
        
        // Find my_theme in graph
        $themeNode = null;
        foreach ($graph as $node) {
            if ($node['machine_name'] === 'my_theme') {
                $themeNode = $node;
                break;
            }
        }

        $this->assertNotNull($themeNode);
        $this->assertEquals(2, $themeNode['dependency_impact'], 'my_theme should have impact 2 because its dependency my_module has 2 changes');
        $this->assertCount(1, $themeNode['impact_details']);
        $this->assertEquals('my_module', $themeNode['impact_details'][0]['extension']);
        $this->assertEquals(2, $themeNode['impact_details'][0]['count']);
    }
}
