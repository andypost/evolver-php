<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

final class ScanRunRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function create(
        int $projectId,
        string $branchName,
        ?string $commitSha,
        ?string $sourcePath,
        ?string $fromCoreVersion,
        string $targetCoreVersion,
        string $status = 'queued',
        ?int $jobId = null,
    ): int {
        $written = $this->db->execute(
            'INSERT INTO scan_runs (
                project_id, branch_name, commit_sha, source_path, from_core_version,
                target_core_version, status, job_id
             ) VALUES (
                :project_id, :branch_name, :commit_sha, :source_path, :from_core_version,
                :target_core_version, :status, :job_id
             )',
            [
                'project_id' => $projectId,
                'branch_name' => $branchName,
                'commit_sha' => $commitSha,
                'source_path' => $sourcePath,
                'from_core_version' => $fromCoreVersion,
                'target_core_version' => $targetCoreVersion,
                'status' => $status,
                'job_id' => $jobId,
            ]
        );

        if ($written !== 1) {
            throw new \LogicException('Failed to create scan run.');
        }

        return $this->db->lastInsertId();
    }

    #[\NoDiscard]
    public function findById(int $id): ?array
    {
        $row = $this->db->query('SELECT * FROM scan_runs WHERE id = :id', ['id' => $id])->fetch();
        return $row ?: null;
    }

    #[\NoDiscard]
    public function findByJobId(int $jobId): ?array
    {
        $row = $this->db->query('SELECT * FROM scan_runs WHERE job_id = :job_id', ['job_id' => $jobId])->fetch();
        return $row ?: null;
    }

    #[\NoDiscard]
    public function findByProject(int $projectId, int $limit = 50): array
    {
        $limit = max(1, $limit);
        return $this->db->query(
            sprintf('SELECT * FROM scan_runs WHERE project_id = :project_id ORDER BY id DESC LIMIT %d', $limit),
            ['project_id' => $projectId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findLatestByProject(int $projectId, ?string $status = null): ?array
    {
        $sql = 'SELECT * FROM scan_runs WHERE project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $row = $this->db->query($sql, $params)->fetch();
        return $row ?: null;
    }

    public function attachJob(int $id, int $jobId): int
    {
        return $this->db->execute(
            'UPDATE scan_runs SET job_id = :job_id WHERE id = :id',
            ['id' => $id, 'job_id' => $jobId]
        );
    }

    public function markRunning(
        int $id,
        ?string $commitSha = null,
        ?string $sourcePath = null,
        ?string $fromCoreVersion = null,
        ?int $fileCount = null,
    ): int {
        return $this->db->execute(
            "UPDATE scan_runs
             SET status = 'running',
                 commit_sha = COALESCE(:commit_sha, commit_sha),
                 source_path = COALESCE(:source_path, source_path),
                 from_core_version = COALESCE(:from_core_version, from_core_version),
                 file_count = COALESCE(:file_count, file_count),
                 started_at = COALESCE(started_at, datetime('now')),
                 error_message = NULL
             WHERE id = :id",
            [
                'id' => $id,
                'commit_sha' => $commitSha,
                'source_path' => $sourcePath,
                'from_core_version' => $fromCoreVersion,
                'file_count' => $fileCount,
            ]
        );
    }

    public function updateProgress(int $id, int $scannedFileCount, ?int $fileCount = null): int
    {
        return $this->db->execute(
            'UPDATE scan_runs
             SET scanned_file_count = :scanned_file_count,
                 file_count = COALESCE(:file_count, file_count)
             WHERE id = :id',
            [
                'id' => $id,
                'scanned_file_count' => $scannedFileCount,
                'file_count' => $fileCount,
            ]
        );
    }

    public function markCompleted(int $id, int $matchCount, int $autoFixableCount, array $summary): int
    {
        return $this->db->execute(
            "UPDATE scan_runs
             SET status = 'completed',
                 match_count = :match_count,
                 auto_fixable_count = :auto_fixable_count,
                 summary_json = :summary_json,
                 finished_at = datetime('now'),
                 error_message = NULL
             WHERE id = :id",
            [
                'id' => $id,
                'match_count' => $matchCount,
                'auto_fixable_count' => $autoFixableCount,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function markFailed(int $id, string $errorMessage): int
    {
        return $this->db->execute(
            "UPDATE scan_runs
             SET status = 'failed',
                 error_message = :error_message,
                 finished_at = datetime('now')
             WHERE id = :id",
            ['id' => $id, 'error_message' => $errorMessage]
        );
    }
}
