<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

use DrupalEvolver\Project\GitProjectManager;
use DrupalEvolver\Queue\JobQueue;
use DrupalEvolver\Storage\DatabaseApi;
use Symfony\Component\Console\Output\OutputInterface;

final class ScanRunService
{
    public function __construct(
        private DatabaseApi $api,
        private ProjectScanner $scanner,
        private JobQueue $queue,
        private GitProjectManager $gitProjectManager,
    ) {}

    #[\NoDiscard]
    public function queueBranchScan(
        int $projectId,
        string $branchName,
        string $targetCoreVersion,
        ?string $fromCoreVersion = null,
        int $workers = 1,
    ): int {
        $project = $this->api->projects()->findById($projectId);
        if ($project === null) {
            throw new \InvalidArgumentException(sprintf('Unknown project id: %d', $projectId));
        }

        $branch = $this->api->projectBranches()->findByProjectAndName($projectId, $branchName);
        if ($branch === null) {
            throw new \InvalidArgumentException(sprintf('Unknown branch "%s" for project %d', $branchName, $projectId));
        }

        $targetVersion = $this->api->versions()->findByTag($targetCoreVersion);
        if ($targetVersion === null) {
            throw new \InvalidArgumentException(sprintf('Target core version is not indexed: %s', $targetCoreVersion));
        }

        if ($fromCoreVersion !== null) {
            $fromVersion = $this->api->versions()->findByTag($fromCoreVersion);
            if ($fromVersion === null) {
                throw new \InvalidArgumentException(sprintf('Source core version is not indexed: %s', $fromCoreVersion));
            }

            $changes = $this->api->changes()->findForUpgradePath((int) $fromVersion['id'], (int) $targetVersion['id']);
            if ($changes === []) {
                throw new \InvalidArgumentException(sprintf(
                    'No stored changes exist for the upgrade path %s -> %s.',
                    $fromCoreVersion,
                    $targetCoreVersion
                ));
            }
        }

        $runId = $this->api->scanRuns()->create(
            $projectId,
            $branchName,
            null,
            null,
            $fromCoreVersion,
            $targetCoreVersion
        );
        $jobId = $this->queue->enqueue('scan_branch', [
            'scan_run_id' => $runId,
            'project_id' => $projectId,
            'branch_name' => $branchName,
            'target_core_version' => $targetCoreVersion,
            'from_core_version' => $fromCoreVersion,
            'workers' => max(1, $workers),
        ]);

        $this->api->scanRuns()->attachJob($runId, $jobId);
        $this->queue->log(
            $jobId,
            'info',
            sprintf('Queued branch scan for %s → %s', $branchName, $targetCoreVersion)
        );

        return $runId;
    }

    public function executeQueuedJob(array $job, ?OutputInterface $output = null): void
    {
        $payload = $job['payload'] ?? [];
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            throw new \InvalidArgumentException('Queued job is missing its id.');
        }

        if (($job['kind'] ?? '') !== 'scan_branch') {
            throw new \InvalidArgumentException(sprintf('Unsupported queued job kind: %s', (string) ($job['kind'] ?? '')));
        }

        $runId = (int) ($payload['scan_run_id'] ?? 0);
        $projectId = (int) ($payload['project_id'] ?? 0);
        $branchName = (string) ($payload['branch_name'] ?? '');
        $targetCoreVersion = (string) ($payload['target_core_version'] ?? '');
        $fromCoreVersion = isset($payload['from_core_version']) && $payload['from_core_version'] !== ''
            ? (string) $payload['from_core_version']
            : null;
        $workers = max(1, (int) ($payload['workers'] ?? 1));

        $project = $this->api->projects()->findById($projectId);
        if ($project === null) {
            throw new \RuntimeException(sprintf('Project %d no longer exists.', $projectId));
        }

        $branch = $this->api->projectBranches()->findByProjectAndName($projectId, $branchName);
        if ($branch === null) {
            throw new \RuntimeException(sprintf('Branch %s no longer exists.', $branchName));
        }

        try {
            $this->queue->progress($jobId, 0, 1, 'Preparing source tree');
            $materialized = $this->gitProjectManager->materializeBranch(
                $project,
                $branchName,
                function (string $level, string $message) use ($jobId, $output): void {
                    $this->queue->log($jobId, $level, $message);
                    $output?->writeln(sprintf('[%s] %s', strtoupper($level), $message));
                }
            );

            $sourcePath = $materialized['source_path'];
            $commitSha = $materialized['commit_sha'] ?? null;
            if ($commitSha !== null) {
                $this->api->projectBranches()->markScanned((int) $branch['id'], $commitSha);
            }

            if ($fromCoreVersion === null) {
                $detector = new VersionDetector();
                $fromCoreVersion = $detector->detect($sourcePath);
            }

            if ($fromCoreVersion === null || $fromCoreVersion === '') {
                throw new \RuntimeException('Unable to detect the source core version for this branch.');
            }

            $this->api->scanRuns()->markRunning($runId, $commitSha, $sourcePath, $fromCoreVersion);
            $this->queue->log(
                $jobId,
                'info',
                sprintf('Scanning %s from %s to %s', $branchName, $fromCoreVersion, $targetCoreVersion)
            );

            $this->scanner->setWorkerCount($workers);
            (void) $this->scanner->scanIntoProject(
                $projectId,
                $runId,
                $sourcePath,
                $targetCoreVersion,
                $fromCoreVersion,
                $output,
                function (int $current, int $total, string $message) use ($jobId): void {
                    $this->queue->progress($jobId, $current, $total, $message);
                }
            );

            $this->queue->progress($jobId, 1, 1, 'Completed');
            $this->queue->log($jobId, 'info', 'Scan completed successfully');
            $this->queue->complete($jobId);
        } catch (\Throwable $e) {
            $this->api->scanRuns()->markFailed($runId, $e->getMessage());
            $this->queue->log($jobId, 'error', $e->getMessage());
            $this->queue->fail($jobId, $e->getMessage());
            throw $e;
        }
    }
}
