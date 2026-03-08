<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;

final class MatchRepo
{
    private const CREATE_BATCH_SIZE = 75;
    private const IDENTITY_FIELDS = ['scope_key', 'change_id', 'file_path', 'byte_start', 'byte_end'];

    public function __construct(private Database $db, private ?DatabaseApi $api = null) {}

    #[\NoDiscard]
    public function save(array $data): int
    {
        $fields = [
            'project_id', 'scan_run_id', 'scope_key', 'change_id', 'file_path',
            'line_start', 'line_end', 'byte_start', 'byte_end',
            'matched_source', 'suggested_fix', 'fix_method', 'status',
            'change_type', 'severity', 'old_fqn', 'migration_hint',
        ];

        $data = $this->normalizeIdentityFields($data);
        $present = array_values(array_intersect($fields, array_keys($data)));
        $cols = implode(', ', $present);
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $present));
        $params = array_intersect_key($data, array_flip($present));
        $sql = "INSERT INTO code_matches ({$cols}) VALUES ({$placeholders})";

        $assignments = [];
        foreach ($present as $field) {
            if (in_array($field, self::IDENTITY_FIELDS, true) || $field === 'change_type') {
                continue;
            }

            $assignments[] = "{$field} = excluded.{$field}";
        }

        if ($assignments !== []) {
            $sql .= ' ON CONFLICT(scope_key, change_id, file_path, byte_start, byte_end) DO UPDATE SET '
                . implode(', ', $assignments);
        } else {
            $sql .= ' ON CONFLICT(scope_key, change_id, file_path, byte_start, byte_end) DO NOTHING';
        }

        $stmt = $this->prepareStatement('match_repo.save.' . implode(',', $present), $sql);
        $stmt->execute($params);

        $id = $this->findIdByIdentity($data);
        if ($id === null) {
            throw new \LogicException('Failed to persist code match.');
        }

        return $id;
    }

    #[\NoDiscard]
    public function create(array $data): int
    {
        return $this->save($data);
    }

    #[\NoDiscard]
    public function saveBatch(array $matches): int
    {
        if ($matches === []) {
            return 0;
        }

        $fields = [
            'project_id', 'scan_run_id', 'scope_key', 'change_id', 'file_path',
            'line_start', 'line_end', 'byte_start', 'byte_end',
            'matched_source', 'suggested_fix', 'fix_method', 'status',
            'change_type', 'severity', 'old_fqn', 'migration_hint',
        ];
        $cols = implode(', ', $fields);
        $placeholders = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';
        $updateAssignments = [
            'project_id = excluded.project_id',
            'scan_run_id = excluded.scan_run_id',
            'line_start = excluded.line_start',
            'line_end = excluded.line_end',
            'matched_source = excluded.matched_source',
            'suggested_fix = excluded.suggested_fix',
            'fix_method = excluded.fix_method',
            'status = excluded.status',
            'severity = excluded.severity',
            'old_fqn = excluded.old_fqn',
            'migration_hint = excluded.migration_hint',
        ];
        $affectedRows = 0;

        foreach (array_chunk($matches, self::CREATE_BATCH_SIZE) as $chunk) {
            $sql = "INSERT INTO code_matches ({$cols}) VALUES ";
            $values = [];
            $params = [];

            foreach ($chunk as $match) {
                $match = $this->normalizeIdentityFields($match);
                $values[] = $placeholders;

                foreach ($fields as $field) {
                    $params[] = $match[$field] ?? null;
                }
            }

            $sql .= implode(', ', $values)
                . ' ON CONFLICT(scope_key, change_id, file_path, byte_start, byte_end) DO UPDATE SET '
                . implode(', ', $updateAssignments);
            $affectedRows += $this->db->execute($sql, $params);
        }

        return $affectedRows;
    }

    #[\NoDiscard]
    public function createBatch(array $matches): int
    {
        return $this->saveBatch($matches);
    }

    #[\NoDiscard]
    public function findByProject(int $projectId): array
    {
        return $this->db->query(
            'SELECT * FROM code_matches WHERE project_id = :project_id ORDER BY id',
            ['project_id' => $projectId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findByRun(int $scanRunId): array
    {
        return $this->db->query(
            'SELECT m.*, 
                    COALESCE(m.change_type, c.change_type) as change_type,
                    COALESCE(m.severity, c.severity) as severity,
                    COALESCE(m.migration_hint, c.migration_hint) as migration_hint,
                    COALESCE(m.old_fqn, c.old_fqn) as old_fqn,
                    c.diff_json, c.new_fqn, c.fix_method
             FROM code_matches m
             LEFT JOIN changes c ON m.change_id = c.id
             WHERE m.scan_run_id = :scan_run_id 
             ORDER BY m.file_path, m.line_start, m.id',
            ['scan_run_id' => $scanRunId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findPending(int $projectId, ?int $scanRunId = null): array
    {
        if ($scanRunId !== null) {
            return $this->db->query(
                "SELECT * FROM code_matches
                 WHERE scan_run_id = :scan_run_id AND status = 'pending'
                 ORDER BY file_path, line_start, id",
                ['scan_run_id' => $scanRunId]
            )->fetchAll();
        }

        return $this->db->query(
            "SELECT * FROM code_matches
             WHERE project_id = :project_id AND status = 'pending'
             ORDER BY file_path, line_start, id",
            ['project_id' => $projectId]
        )->fetchAll();
    }

    public function updateStatus(int $id, string $status): int
    {
        return $this->db->execute(
            'UPDATE code_matches SET status = :status WHERE id = :id',
            ['status' => $status, 'id' => $id]
        );
    }

    #[\NoDiscard]
    public function countByProject(int $projectId): array
    {
        return $this->db->query(
            "SELECT status, COUNT(*) AS cnt
             FROM code_matches
             WHERE project_id = :project_id
             GROUP BY status",
            ['project_id' => $projectId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function countByRun(int $scanRunId): array
    {
        return $this->db->query(
            "SELECT status, COUNT(*) AS cnt
             FROM code_matches
             WHERE scan_run_id = :scan_run_id
             GROUP BY status",
            ['scan_run_id' => $scanRunId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function getTotalCountByProject(int $projectId): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS total FROM code_matches WHERE project_id = :project_id',
            ['project_id' => $projectId]
        )->fetch();

        return (int) ($row['total'] ?? 0);
    }

    #[\NoDiscard]
    public function getTotalCountByRun(int $scanRunId): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS total FROM code_matches WHERE scan_run_id = :scan_run_id',
            ['scan_run_id' => $scanRunId]
        )->fetch();

        return (int) ($row['total'] ?? 0);
    }

    public function deleteByProject(int $projectId): int
    {
        return $this->db->execute(
            'DELETE FROM code_matches WHERE project_id = :project_id',
            ['project_id' => $projectId]
        );
    }

    private function prepareStatement(string $key, string $sql): \PDOStatement
    {
        return $this->api?->prepare($key, $sql) ?? $this->db->pdo()->prepare($sql);
    }

    #[\NoDiscard]
    private function findIdByIdentity(array $data): ?int
    {
        $data = $this->normalizeIdentityFields($data);
        $row = $this->db->query(
            'SELECT id
             FROM code_matches
             WHERE scope_key = :scope_key
               AND change_id IS :change_id
               AND file_path = :file_path
               AND byte_start = :byte_start
               AND byte_end = :byte_end
             ORDER BY id DESC
             LIMIT 1',
            [
                'scope_key' => $data['scope_key'] ?? null,
                'change_id' => $data['change_id'] ?? null,
                'file_path' => $data['file_path'] ?? null,
                'byte_start' => $data['byte_start'] ?? null,
                'byte_end' => $data['byte_end'] ?? null,
            ]
        )->fetch();

        return $row ? (int) $row['id'] : null;
    }

    private function normalizeIdentityFields(array $data): array
    {
        foreach (['byte_start', 'byte_end'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                $data[$field] = -1;
            }
        }

        $scanRunId = isset($data['scan_run_id']) && $data['scan_run_id'] !== null ? (int) $data['scan_run_id'] : null;
        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;

        if ($scanRunId !== null) {
            if ($projectId === null) {
                $row = $this->db->query(
                    'SELECT project_id FROM scan_runs WHERE id = :scan_run_id',
                    ['scan_run_id' => $scanRunId]
                )->fetch();
                if (!$row || !isset($row['project_id'])) {
                    throw new \InvalidArgumentException(sprintf('Unknown scan run id: %d', $scanRunId));
                }
                $projectId = (int) $row['project_id'];
            }

            $data['project_id'] = $projectId;
            $data['scan_run_id'] = $scanRunId;
            $data['scope_key'] = 'run:' . $scanRunId;
        } elseif ($projectId !== null) {
            $data['project_id'] = $projectId;
            $data['scope_key'] = 'project:' . $projectId;
        } else {
            throw new \InvalidArgumentException('Matches require either scan_run_id or project_id.');
        }

        return $data;
    }
}
