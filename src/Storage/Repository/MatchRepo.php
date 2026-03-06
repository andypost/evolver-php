<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;

class MatchRepo
{
    private const CREATE_BATCH_SIZE = 75;
    private const IDENTITY_FIELDS = ['project_id', 'change_id', 'file_path', 'byte_start', 'byte_end'];

    public function __construct(private Database $db, private ?DatabaseApi $api = null) {}

    #[\NoDiscard]
    public function save(array $data): int
    {
        $fields = [
            'project_id', 'change_id', 'file_path', 'line_start', 'line_end',
            'byte_start', 'byte_end',
            'matched_source', 'suggested_fix', 'fix_method', 'status',
        ];

        $data = $this->normalizeIdentityFields($data);
        $present = array_intersect($fields, array_keys($data));
        $cols = implode(', ', $present);
        $placeholders = implode(', ', array_map(fn($f) => ':' . $f, $present));
        $params = array_intersect_key($data, array_flip($present));
        $sql = "INSERT INTO code_matches ({$cols}) VALUES ({$placeholders})";

        $assignments = [];
        foreach ($present as $field) {
            if (in_array($field, self::IDENTITY_FIELDS, true)) {
                continue;
            }

            $assignments[] = "{$field} = excluded.{$field}";
        }

        if ($assignments !== []) {
            $sql .= ' ON CONFLICT(project_id, change_id, file_path, byte_start, byte_end) DO UPDATE SET '
                . implode(', ', $assignments);
        } else {
            $sql .= ' ON CONFLICT(project_id, change_id, file_path, byte_start, byte_end) DO NOTHING';
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
        if (empty($matches)) {
            return 0;
        }

        $fields = [
            'project_id', 'change_id', 'file_path', 'line_start', 'line_end',
            'byte_start', 'byte_end',
            'matched_source', 'suggested_fix', 'fix_method', 'status',
        ];

        $cols = implode(', ', $fields);
        $placeholders = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';
        $updateAssignments = [
            'line_start = excluded.line_start',
            'line_end = excluded.line_end',
            'matched_source = excluded.matched_source',
            'suggested_fix = excluded.suggested_fix',
            'fix_method = excluded.fix_method',
            'status = excluded.status',
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
                . ' ON CONFLICT(project_id, change_id, file_path, byte_start, byte_end) DO UPDATE SET '
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
            'SELECT * FROM code_matches WHERE project_id = :pid',
            ['pid' => $projectId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findPending(int $projectId): array
    {
        return $this->db->query(
            "SELECT * FROM code_matches WHERE project_id = :pid AND status = 'pending'",
            ['pid' => $projectId]
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
            "SELECT status, COUNT(*) as cnt FROM code_matches WHERE project_id = :pid GROUP BY status",
            ['pid' => $projectId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function getTotalCountByProject(int $projectId): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) as total FROM code_matches WHERE project_id = :pid",
            ['pid' => $projectId]
        )->fetch();

        return (int) ($row['total'] ?? 0);
    }

    #[\NoDiscard]
    public function deleteByProject(int $projectId): int
    {
        return $this->db->execute(
            'DELETE FROM code_matches WHERE project_id = :pid',
            ['pid' => $projectId]
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
             WHERE project_id = :project_id
               AND change_id = :change_id
               AND file_path = :file_path
               AND byte_start = :byte_start
               AND byte_end = :byte_end
             ORDER BY id DESC
             LIMIT 1',
            [
                'project_id' => $data['project_id'] ?? null,
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

        return $data;
    }
}
