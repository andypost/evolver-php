<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Storage;

use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

class DatabaseApiPlanTest extends TestCase
{
    private DatabaseApi $api;

    protected function setUp(): void
    {
        $this->api = new DatabaseApi(':memory:');
    }

    public function testGetProjectUpgradePlanReturnsTopologicalSort(): void
    {
        // 1. Setup project
        $projectId = $this->api->projects()->save('custom_proj', '/app/web', 'drupal-site', '10.0.0');
        $runId = $this->api->scanRuns()->create($projectId, 'main', null, '/app/web', '10.0.0', '11.0.0');

        // 2. Setup extensions with dependencies
        // custom_core_api (no dependencies)
        $this->api->projectExtensions()->save(
            $projectId,
            'custom_core_api',
            'module',
            'Core API',
            [],
            'modules/custom/custom_core_api/custom_core_api.info.yml'
        );

        // custom_ecommerce (depends on custom_core_api)
        $this->api->projectExtensions()->save(
            $projectId,
            'custom_ecommerce',
            'module',
            'Ecommerce',
            ['custom_core_api'],
            'modules/custom/custom_ecommerce/custom_ecommerce.info.yml'
        );

        // custom_theme (depends on custom_ecommerce and commerce)
        // commerce is an external dependency and should be ignored by the graph
        $this->api->projectExtensions()->save(
            $projectId,
            'custom_theme',
            'theme',
            'My Theme',
            ['custom_ecommerce', 'commerce'],
            'themes/custom/custom_theme/custom_theme.info.yml'
        );

        // 3. Insert some fake matches to simulate scan results
        // 10 matches for custom_core_api
        for ($i = 0; $i < 10; $i++) {
            $_ = $this->api->db()->execute(
                "INSERT INTO code_matches (project_id, scan_run_id, scope_key, file_path, byte_start, byte_end, severity) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$projectId, $runId, "run:{$runId}", "modules/custom/custom_core_api/src/Foo{$i}.php", $i, $i+10, 'breaking']
            );
        }

        // 4 matches for custom_ecommerce
        for ($i = 0; $i < 4; $i++) {
            $_ = $this->api->db()->execute(
                "INSERT INTO code_matches (project_id, scan_run_id, scope_key, file_path, byte_start, byte_end, severity) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$projectId, $runId, "run:{$runId}", "modules/custom/custom_ecommerce/src/Bar{$i}.php", $i, $i+10, 'warning']
            );
        }

        // 0 matches for custom_theme

        // 4. Get the plan
        $plan = $this->api->getProjectUpgradePlan($runId, $projectId);

        // Assert order (independent first)
        $this->assertCount(3, $plan);
        $this->assertSame('custom_core_api', $plan[0]['machine_name']);
        $this->assertSame('custom_ecommerce', $plan[1]['machine_name']);
        $this->assertSame('custom_theme', $plan[2]['machine_name']);

        // Assert stats
        $this->assertSame(10, $plan[0]['match_count']);
        $this->assertSame(10, $plan[0]['by_severity']['breaking']);
        $this->assertSame(100, $plan[0]['score']); // 10 breaking * 10 points

        $this->assertSame(4, $plan[1]['match_count']);
        $this->assertSame(4, $plan[1]['by_severity']['warning']);
        $this->assertSame(12, $plan[1]['score']); // 4 warning * 3 points

        $this->assertSame(0, $plan[2]['match_count']);
        $this->assertSame(0, $plan[2]['score']);
        
        // Assert dependency filtering (external 'commerce' is removed)
        $this->assertSame(['custom_ecommerce'], $plan[2]['dependencies']);
        
        // Assert dependents logic
        $this->assertSame(['custom_ecommerce'], $plan[0]['dependents']);
        $this->assertSame(['custom_theme'], $plan[1]['dependents']);
        $this->assertSame([], $plan[2]['dependents']);
    }
    
    public function testGetProjectUpgradePlanHandlesCyclesGracefully(): void
    {
        $projectId = $this->api->projects()->save('cycle_proj', '/app/web', 'drupal-site', '10.0.0');
        $runId = $this->api->scanRuns()->create($projectId, 'main', null, '/app/web', '10.0.0', '11.0.0');

        $this->api->projectExtensions()->save($projectId, 'mod_a', 'module', 'A', ['mod_b'], 'mod_a.info.yml');
        $this->api->projectExtensions()->save($projectId, 'mod_b', 'module', 'B', ['mod_a'], 'mod_b.info.yml');
        
        $plan = $this->api->getProjectUpgradePlan($runId, $projectId);
        
        $this->assertCount(2, $plan);
        // It shouldn't crash, and should return both extensions.
        $names = array_column($plan, 'machine_name');
        $this->assertContains('mod_a', $names);
        $this->assertContains('mod_b', $names);
    }

    public function testGetProjectExtensionGraphCalculatesTransitiveHotspots(): void
    {
        $projectId = $this->api->projects()->save('graph_proj', '/app/web', 'drupal-site', '10.0.0');
        $runId = $this->api->scanRuns()->create($projectId, 'main', null, '/app/web', '10.0.0', '11.0.0');

        $this->api->projectExtensions()->save(
            $projectId,
            'custom_core_api',
            'module',
            'Core API',
            [],
            'modules/custom/custom_core_api/custom_core_api.info.yml'
        );
        $this->api->projectExtensions()->save(
            $projectId,
            'custom_ecommerce',
            'module',
            'Ecommerce',
            ['custom_core_api'],
            'modules/custom/custom_ecommerce/custom_ecommerce.info.yml'
        );
        $this->api->projectExtensions()->save(
            $projectId,
            'custom_theme',
            'theme',
            'My Theme',
            ['custom_ecommerce', 'commerce'],
            'themes/custom/custom_theme/custom_theme.info.yml'
        );

        for ($i = 0; $i < 10; $i++) {
            $_ = $this->api->db()->execute(
                'INSERT INTO code_matches (project_id, scan_run_id, scope_key, file_path, byte_start, byte_end, severity)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$projectId, $runId, "run:{$runId}", "modules/custom/custom_core_api/src/Foo{$i}.php", $i, $i + 10, 'breaking']
            );
        }

        for ($i = 0; $i < 4; $i++) {
            $_ = $this->api->db()->execute(
                'INSERT INTO code_matches (project_id, scan_run_id, scope_key, file_path, byte_start, byte_end, severity)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$projectId, $runId, "run:{$runId}", "modules/custom/custom_ecommerce/src/Bar{$i}.php", $i, $i + 10, 'warning']
            );
        }

        $graph = $this->api->getProjectExtensionGraph($runId, $projectId);
        $indexed = [];
        foreach ($graph as $node) {
            $indexed[$node['machine_name']] = $node;
        }

        $this->assertCount(3, $indexed);

        $this->assertSame(100, $indexed['custom_core_api']['score']);
        $this->assertSame(0, $indexed['custom_core_api']['dependency_match_count']);
        $this->assertSame(0, $indexed['custom_core_api']['dependency_score']);
        $this->assertSame(100, $indexed['custom_core_api']['hotspot_score']);

        $this->assertSame(['custom_core_api'], $indexed['custom_ecommerce']['dependencies']);
        $this->assertSame(['custom_theme'], $indexed['custom_ecommerce']['dependents']);
        $this->assertSame(['custom_core_api'], $indexed['custom_ecommerce']['transitive_dependencies']);
        $this->assertSame(4, $indexed['custom_ecommerce']['match_count']);
        $this->assertSame(12, $indexed['custom_ecommerce']['score']);
        $this->assertSame(10, $indexed['custom_ecommerce']['dependency_match_count']);
        $this->assertSame(100, $indexed['custom_ecommerce']['dependency_score']);
        $this->assertSame(112, $indexed['custom_ecommerce']['hotspot_score']);

        $this->assertSame(['custom_ecommerce'], $indexed['custom_theme']['dependencies']);
        $this->assertSame([], $indexed['custom_theme']['dependents']);
        $this->assertSame(['custom_ecommerce', 'custom_core_api'], $indexed['custom_theme']['transitive_dependencies']);
        $this->assertSame(14, $indexed['custom_theme']['dependency_match_count']);
        $this->assertSame(112, $indexed['custom_theme']['dependency_score']);
        $this->assertSame(112, $indexed['custom_theme']['hotspot_score']);
        $this->assertCount(2, $indexed['custom_theme']['impact_details']);
        $this->assertSame('custom_core_api', $indexed['custom_theme']['impact_details'][0]['extension']);
        $this->assertSame('custom_ecommerce', $indexed['custom_theme']['impact_details'][1]['extension']);
    }
}
