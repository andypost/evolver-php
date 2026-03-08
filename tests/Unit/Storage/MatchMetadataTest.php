<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Storage;

use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

final class MatchMetadataTest extends TestCase
{
    public function testSavesAndRetrievesVirtualMatchMetadata(): void
    {
        $api = new DatabaseApi(':memory:');
        $projectId = $api->projects()->save('demo', '/tmp/demo', 'module', null, 'local_path');
        $runId = $api->scanRuns()->create($projectId, 'demo', null, '/tmp/demo', '1.0.0', '1.1.0');

        $fromId = $api->versions()->create('1.0.0', 1, 0, 0);
        $toId = $api->versions()->create('1.1.0', 1, 1, 0);
        
        $changeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'dummy',
        ]);

        $matchData = [
            'project_id' => $projectId,
            'scan_run_id' => $runId,
            'scope_key' => 'run:' . $runId,
            'change_id' => $changeId,
            'file_path' => 'src/Example.php',
            'line_start' => 10,
            'line_end' => 10,
            'matched_source' => 'hook_demo',
            'change_type' => 'procedural_to_attribute',
            'severity' => 'info',
            'old_fqn' => 'hook_demo',
            'migration_hint' => 'Use #[Hook] instead',
            'status' => 'pending',
        ];

        $savedId = $api->matches()->save($matchData);
        $this->assertGreaterThan(0, $savedId);

        $matches = $api->matches()->findByRun($runId);
        $this->assertCount(1, $matches);
        
        $match = $matches[0];
        $this->assertSame($changeId, (int) $match['change_id']);
        $this->assertSame('procedural_to_attribute', $match['change_type']);
        $this->assertSame('info', $match['severity']);
        $this->assertSame('hook_demo', $match['old_fqn']);
        $this->assertSame('Use #[Hook] instead', $match['migration_hint']);
    }
}
