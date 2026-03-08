<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Pattern\QueryPattern;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;

class ChangeRepo
{
    private const CREATE_BATCH_SIZE = 100;

    public function __construct(private Database $db, private ?DatabaseApi $api = null) {}

    #[\NoDiscard]
    public function create(array $data): int
    {
        $fields = [
            'from_version_id', 'to_version_id', 'language', 'change_type', 'severity',
            'old_symbol_id', 'new_symbol_id', 'old_fqn', 'new_fqn',
            'diff_json', 'ts_query', 'query_version', 'fix_template', 'migration_hint', 'confidence',
        ];

        if (!array_key_exists('query_version', $data)) {
            $data['query_version'] = QueryGenerator::QUERY_VERSION;
        }

        // Convert QueryPattern to string if present
        if (isset($data['ts_query']) && $data['ts_query'] instanceof QueryPattern) {
            $data['ts_query'] = $data['ts_query']->pattern;
        }

        $present = array_intersect($fields, array_keys($data));
        $cols = implode(', ', $present);
        $placeholders = implode(', ', array_map(fn($f) => ':' . $f, $present));
        $params = array_intersect_key($data, array_flip($present));
        $sql = "INSERT INTO changes ({$cols}) VALUES ({$placeholders})";

        $stmt = $this->prepareStatement('change_repo.create.' . implode(',', $present), $sql);
        $stmt->execute($params);
        return $this->db->lastInsertId();
    }

    #[\NoDiscard]
    public function findByVersions(int $fromId, int $toId): array
    {
        return $this->db->query(
            'SELECT * FROM changes WHERE from_version_id = :from AND to_version_id = :to',
            ['from' => $fromId, 'to' => $toId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findForUpgradePath(int $fromId, int $toId): array
    {
        if ($fromId === $toId) {
            return [];
        }

        return $this->db->query(
            "SELECT DISTINCT c.*
             FROM changes c
             JOIN versions v_from ON v_from.id = c.from_version_id
             JOIN versions v_to ON v_to.id = c.to_version_id
             JOIN versions v_start ON v_start.id = :start_id
             JOIN versions v_end ON v_end.id = :end_id
             WHERE v_from.weight >= v_start.weight
               AND v_to.weight <= v_end.weight
               AND v_from.weight < v_to.weight
             ORDER BY v_from.weight, v_to.weight, c.id",
            ['start_id' => $fromId, 'end_id' => $toId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findByType(string $type): array
    {
        return $this->db->query(
            'SELECT * FROM changes WHERE change_type = :type',
            ['type' => $type]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function countByVersions(int $fromId, int $toId): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) as cnt FROM changes WHERE from_version_id = :from AND to_version_id = :to',
            ['from' => $fromId, 'to' => $toId]
        )->fetch()['cnt'];
    }

    #[\NoDiscard]
    public function createBatch(array $changes): int
    {
        if ($changes === []) {
            return 0;
        }

        $fields = [
            'from_version_id', 'to_version_id', 'language', 'change_type', 'severity',
            'old_symbol_id', 'new_symbol_id', 'old_fqn', 'new_fqn',
            'diff_json', 'ts_query', 'query_version', 'fix_template', 'migration_hint', 'confidence',
        ];

        $cols = implode(', ', $fields);
        $placeholders = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';
        $affectedRows = 0;

        foreach (array_chunk($changes, self::CREATE_BATCH_SIZE) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $change) {
                $change['severity'] ??= 'deprecation';
                $change['confidence'] ??= 1.0;
                $change['query_version'] ??= QueryGenerator::QUERY_VERSION;
                $values[] = $placeholders;

                foreach ($fields as $field) {
                    $params[] = $change[$field] ?? null;
                }
            }

            $sql = sprintf('INSERT INTO changes (%s) VALUES %s', $cols, implode(', ', $values));
            $affectedRows += $this->db->execute($sql, $params);
        }

        return $affectedRows;
    }

    private function prepareStatement(string $key, string $sql): \PDOStatement
    {
        return $this->api?->prepare($key, $sql) ?? $this->db->pdo()->prepare($sql);
    }
}
