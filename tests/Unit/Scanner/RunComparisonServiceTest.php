<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Scanner;

use DrupalEvolver\Scanner\RunComparisonService;
use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

final class RunComparisonServiceTest extends TestCase
{
    public function testCompareSplitsIntroducedResolvedAndPersistingFindings(): void
    {
        $api = new DatabaseApi(':memory:');
        [$projectId, $changeIds] = $this->seedComparisonFixture($api);

        $baseRunId = $api->scanRuns()->create($projectId, 'main', 'base-sha', '/tmp/base', '10.2.0', '10.3.0', 'completed');
        $headRunId = $api->scanRuns()->create($projectId, 'main', 'head-sha', '/tmp/head', '10.2.0', '10.3.0', 'completed');

        (void) $api->matches()->save([
            'scan_run_id' => $baseRunId,
            'change_id' => $changeIds['persisting'],
            'file_path' => 'src/A.php',
            'line_start' => 10,
            'matched_source' => 'persisting_old()',
            'fix_method' => 'template',
        ]);
        (void) $api->matches()->save([
            'scan_run_id' => $baseRunId,
            'change_id' => $changeIds['resolved'],
            'file_path' => 'src/B.php',
            'line_start' => 12,
            'matched_source' => 'resolved_old()',
            'fix_method' => 'manual',
        ]);
        (void) $api->matches()->save([
            'scan_run_id' => $headRunId,
            'change_id' => $changeIds['persisting'],
            'file_path' => 'src/A.php',
            'line_start' => 10,
            'matched_source' => 'persisting_old()',
            'fix_method' => 'template',
        ]);
        (void) $api->matches()->save([
            'scan_run_id' => $headRunId,
            'change_id' => $changeIds['introduced'],
            'file_path' => 'src/C.php',
            'line_start' => 14,
            'matched_source' => 'introduced_old()',
            'fix_method' => 'template',
        ]);

        $comparison = (new RunComparisonService($api))->compare($baseRunId, $headRunId);

        $this->assertCount(1, $comparison['introduced']);
        $this->assertCount(1, $comparison['resolved']);
        $this->assertCount(1, $comparison['persisting']);
        $this->assertSame(1, $comparison['summary']['introduced']['count']);
        $this->assertSame(1, $comparison['summary']['resolved']['count']);
        $this->assertSame(1, $comparison['summary']['persisting']['count']);
        $this->assertSame('src/C.php', $comparison['introduced'][0]['file_path']);
        $this->assertSame('src/B.php', $comparison['resolved'][0]['file_path']);
        $this->assertSame('src/A.php', $comparison['persisting'][0]['file_path']);

        $headRunMatches = $api->matches()->findByRun($headRunId);
        $this->assertCount(2, $headRunMatches);
        foreach ($headRunMatches as $row) {
            $this->assertSame($projectId, (int) $row['project_id']);
        }
    }

    public function testCompareRejectsDifferentUpgradePaths(): void
    {
        $api = new DatabaseApi(':memory:');
        [$projectId] = $this->seedComparisonFixture($api);

        $baseRunId = $api->scanRuns()->create($projectId, 'main', null, '/tmp/base', '10.2.0', '10.3.0', 'completed');
        $headRunId = $api->scanRuns()->create($projectId, 'main', null, '/tmp/head', '10.3.0', '11.0.0', 'completed');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same upgrade path');

        (void) (new RunComparisonService($api))->compare($baseRunId, $headRunId);
    }

    /**
     * @return array{int, array{persisting: int, resolved: int, introduced: int}}
     */
    private function seedComparisonFixture(DatabaseApi $api): array
    {
        $projectId = $api->projects()->save('comparison-demo', '/tmp/comparison-demo', 'module', '10.2.0');
        $fromId = $api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $api->versions()->create('10.3.0', 10, 3, 0);

        $persistingChangeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'persisting_old',
        ]);
        $resolvedChangeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'resolved_old',
        ]);
        $introducedChangeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'service_renamed',
            'severity' => 'deprecation',
            'old_fqn' => 'introduced_old',
        ]);

        return [$projectId, [
            'persisting' => $persistingChangeId,
            'resolved' => $resolvedChangeId,
            'introduced' => $introducedChangeId,
        ]];
    }
}
