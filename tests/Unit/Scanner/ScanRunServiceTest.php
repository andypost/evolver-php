<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Scanner;

use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Project\GitProjectManager;
use DrupalEvolver\Queue\JobQueue;
use DrupalEvolver\Scanner\MatchCollector;
use DrupalEvolver\Scanner\ProjectScanner;
use DrupalEvolver\Scanner\ScanRunService;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

final class ScanRunServiceTest extends TestCase
{
    public function testQueueBranchScanValidatesStoredUpgradePath(): void
    {
        $api = new DatabaseApi(':memory:');
        $projectId = $api->projects()->save('demo', '/tmp/demo', 'module', null, 'local_path');
        $_ = $api->projectBranches()->save($projectId, 'main', true);
        $_ = $api->versions()->create('10.2.0', 10, 2, 0);

        $scanner = $this->createScannerOrSkip($api);
        $service = new ScanRunService($api, $scanner, new JobQueue($api), new GitProjectManager());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target core version is not indexed');

        $_ = $service->queueBranchScan($projectId, 'main', '10.3.0', '10.2.0', 1);
    }

    public function testExecuteQueuedJobScansLocalProjectAndCompletesRun(): void
    {
        $api = new DatabaseApi(':memory:');
        $projectDir = $this->createTempDir('evolver-queued-scan-');

        file_put_contents($projectDir . '/module.php', "<?php\nold_func(1);\nold_func(2);\n");

        try {
            $queryGenerator = new QueryGenerator();
            $fromId = $api->versions()->create('1.0.0', 1, 0, 0);
            $toId = $api->versions()->create('1.1.0', 1, 1, 0);
            $fileId = $api->files()->create($fromId, 'core/lib/old.php', 'php', 'old-hash', null, null, 5, 100);

            $oldSymbolId = $api->symbols()->create([
                'version_id' => $fromId,
                'file_id' => $fileId,
                'language' => 'php',
                'symbol_type' => 'function',
                'fqn' => 'old_func',
                'name' => 'old_func',
                'signature_hash' => 'old-func-hash',
                'signature_json' => '{"params":[{"name":"$a","type":"int"}],"return_type":"int"}',
                'source_text' => 'function old_func(int $a): int { return $a + 1; }',
            ]);

            $_ = $api->changes()->create([
                'from_version_id' => $fromId,
                'to_version_id' => $toId,
                'language' => 'php',
                'change_type' => 'function_removed',
                'severity' => 'breaking',
                'old_symbol_id' => $oldSymbolId,
                'old_fqn' => 'old_func',
                'ts_query' => $queryGenerator->generate('function_removed', [
                    'id' => $oldSymbolId,
                    'symbol_type' => 'function',
                    'fqn' => 'old_func',
                    'name' => 'old_func',
                ]),
            ]);

            $projectId = $api->projects()->save('demo', $projectDir, 'module', null, 'local_path');
            $_ = $api->projectBranches()->save($projectId, 'main', true);

            $scanner = $this->createScannerOrSkip($api);
            $queue = new JobQueue($api);
            $service = new ScanRunService($api, $scanner, $queue, new GitProjectManager());

            $runId = $service->queueBranchScan($projectId, 'main', '1.1.0', '1.0.0', 1);
            $this->assertGreaterThan(0, $runId);

            $run = $api->scanRuns()->findById($runId);
            $this->assertNotNull($run);
            $this->assertSame('queued', $run['status']);
            $this->assertNotNull($run['job_id']);

            $job = $queue->claimNext();
            $this->assertNotNull($job);
            $service->executeQueuedJob($job);

            $completedRun = $api->scanRuns()->findById($runId);
            $this->assertNotNull($completedRun);
            $this->assertSame('completed', $completedRun['status']);
            $this->assertSame('1.0.0', $completedRun['from_core_version']);
            $this->assertSame('1.1.0', $completedRun['target_core_version']);
            $this->assertSame($projectDir, $completedRun['source_path']);
            $this->assertSame(2, (int) $completedRun['match_count']);

            $matches = $api->matches()->findByRun($runId);
            $this->assertCount(2, $matches);
            foreach ($matches as $match) {
                $this->assertSame($projectId, (int) $match['project_id']);
                $this->assertSame($runId, (int) $match['scan_run_id']);
            }

            $jobRow = $api->jobs()->findById((int) $run['job_id']);
            $this->assertNotNull($jobRow);
            $this->assertSame('completed', $jobRow['status']);

            $logs = $api->jobLogs()->findByJob((int) $run['job_id']);
            $this->assertNotEmpty($logs);
            $messages = array_column($logs, 'message');
            $this->assertContains('Scan completed successfully', $messages);
        } finally {
            $this->removeDir($projectDir);
        }
    }

    private function createScannerOrSkip(DatabaseApi $api): ProjectScanner
    {
        try {
            $parser = new Parser();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Tree-sitter parser unavailable: ' . $e->getMessage());
        }

        $binding = $parser->binding();
        if ($binding === null) {
            $this->markTestSkipped('FFI parser binding is unavailable.');
        }

        return new ProjectScanner($parser, $api, new MatchCollector($binding, $parser->registry()));
    }

    private function createTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        $dir = $base . '/' . $prefix . uniqid('', true);
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
