<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

final class ProjectBranchRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function save(int $projectId, string $branchName, bool $isDefault = false, ?string $lastCommitSha = null): int
    {
        $_ = $this->db->transaction(function () use ($projectId, $branchName, $isDefault, $lastCommitSha): void {
            $_ = $this->db->execute(
                "INSERT INTO project_branches (project_id, branch_name, is_default, last_commit_sha, updated_at)
                 VALUES (:project_id, :branch_name, :is_default, :last_commit_sha, datetime('now'))
                 ON CONFLICT(project_id, branch_name) DO UPDATE SET
                    is_default = excluded.is_default,
                    last_commit_sha = COALESCE(excluded.last_commit_sha, project_branches.last_commit_sha),
                    updated_at = datetime('now')",
                [
                    'project_id' => $projectId,
                    'branch_name' => $branchName,
                    'is_default' => $isDefault ? 1 : 0,
                    'last_commit_sha' => $lastCommitSha,
                ]
            );

            if ($isDefault) {
                $_ = $this->db->execute(
                    'UPDATE project_branches
                     SET is_default = 0, updated_at = datetime(\'now\')
                     WHERE project_id = :project_id AND branch_name != :branch_name',
                    ['project_id' => $projectId, 'branch_name' => $branchName]
                );
            }
        });

        $branch = $this->findByProjectAndName($projectId, $branchName);
        if ($branch === null) {
            throw new \LogicException(sprintf('Failed to persist branch "%s".', $branchName));
        }

        return (int) $branch['id'];
    }

    #[\NoDiscard]
    public function findById(int $id): ?array
    {
        $row = $this->db->query('SELECT * FROM project_branches WHERE id = :id', ['id' => $id])->fetch();
        return $row ?: null;
    }

    #[\NoDiscard]
    public function findByProject(int $projectId): array
    {
        return $this->db->query(
            'SELECT * FROM project_branches WHERE project_id = :project_id ORDER BY is_default DESC, branch_name, id',
            ['project_id' => $projectId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findByProjectAndName(int $projectId, string $branchName): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM project_branches WHERE project_id = :project_id AND branch_name = :branch_name',
            ['project_id' => $projectId, 'branch_name' => $branchName]
        )->fetch();
        return $row ?: null;
    }

    #[\NoDiscard]
    public function findDefaultForProject(int $projectId): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM project_branches WHERE project_id = :project_id ORDER BY is_default DESC, id LIMIT 1',
            ['project_id' => $projectId]
        )->fetch();
        return $row ?: null;
    }

    public function markScanned(int $id, ?string $lastCommitSha): int
    {
        return $this->db->execute(
            "UPDATE project_branches
             SET last_commit_sha = COALESCE(:last_commit_sha, last_commit_sha),
                 last_scanned_at = datetime('now'),
                 updated_at = datetime('now')
             WHERE id = :id",
            ['id' => $id, 'last_commit_sha' => $lastCommitSha]
        );
    }
}
