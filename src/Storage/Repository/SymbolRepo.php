<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;
use DrupalEvolver\Symbol\SymbolType;

class SymbolRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function create(array $data): int
    {
        $fields = [
            'version_id', 'file_id', 'language', 'symbol_type', 'fqn', 'name',
            'namespace', 'parent_symbol', 'visibility', 'is_static',
            'signature_hash', 'signature_json', 'ast_node_sexp', 'ast_node_json',
            'source_text', 'line_start', 'line_end', 'byte_start', 'byte_end',
            'docblock', 'is_deprecated', 'deprecation_message',
            'deprecation_version', 'removal_version', 'metadata_json',
        ];

        $present = array_intersect($fields, array_keys($data));
        $cols = implode(', ', $present);
        $placeholders = implode(', ', array_map(fn($f) => ':' . $f, $present));
        $params = array_intersect_key($data, array_flip($present));

        $inserted = $this->db->execute("INSERT INTO symbols ({$cols}) VALUES ({$placeholders})", $params);
        if ($inserted !== 1) {
            throw new \LogicException('Failed to create symbol.');
        }

        return $this->db->lastInsertId();
    }

    #[\NoDiscard]
    public function insertBatch(array $symbols): int
    {
        if (empty($symbols)) {
            return 0;
        }

        $fields = [
            'version_id', 'file_id', 'language', 'symbol_type', 'fqn', 'name',
            'namespace', 'parent_symbol', 'visibility', 'is_static',
            'signature_hash', 'signature_json', 'ast_node_sexp', 'ast_node_json',
            'source_text', 'line_start', 'line_end', 'byte_start', 'byte_end',
            'docblock', 'is_deprecated', 'deprecation_message',
            'deprecation_version', 'removal_version', 'metadata_json',
        ];

        $cols = implode(', ', $fields);
        $placeholders = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';
        
        $sql = "INSERT INTO symbols ({$cols}) VALUES ";
        $values = [];
        $params = [];
        
        foreach ($symbols as $sym) {
            $values[] = $placeholders;
            foreach ($fields as $field) {
                $params[] = $sym[$field] ?? null;
            }
        }
        
        $sql .= implode(', ', $values);

        return $this->db->execute($sql, $params);
    }

    public function deleteByFile(int $fileId): int
    {
        return $this->db->execute(
            'DELETE FROM symbols WHERE file_id = :file_id',
            ['file_id' => $fileId]
        );
    }

    #[\NoDiscard]
    public function replaceForFile(int $fileId, array $symbols): int
    {
        $this->deleteByFile($fileId);

        if ($symbols === []) {
            return 0;
        }

        return $this->insertBatch($symbols);
    }

    #[\NoDiscard]
    public function findByFqn(int $versionId, string $fqn): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM symbols WHERE version_id = :vid AND fqn = :fqn',
            ['vid' => $versionId, 'fqn' => $fqn]
        )->fetch();
        return $row ?: null;
    }

    #[\NoDiscard]
    public function findByVersion(int $versionId): array
    {
        return $this->db->query(
            'SELECT * FROM symbols WHERE version_id = :vid',
            ['vid' => $versionId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findByVersionPaginated(int $versionId, int $offset = 0, int $limit = 100, ?string $type = null, ?string $search = null, ?string $language = null): array
    {
        $sql = 'SELECT s.*, f.file_path
                FROM symbols s
                JOIN parsed_files f ON s.file_id = f.id
                WHERE s.version_id = :vid';
        $params = ['vid' => $versionId];

        if ($type) {
            $sql .= ' AND s.symbol_type = :type';
            $params['type'] = $type;
        }

        if ($language) {
            $sql .= ' AND s.language = :language';
            $params['language'] = $language;
        }

        if ($search) {
            $sql .= ' AND (s.fqn LIKE :search OR s.name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY s.fqn ASC LIMIT :limit OFFSET :offset';
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        return $this->db->query($sql, $params)->fetchAll();
    }

    #[\NoDiscard]
    public function countByVersionFiltered(int $versionId, ?string $type = null, ?string $search = null, ?string $language = null): int
    {
        $sql = 'SELECT COUNT(*) as cnt FROM symbols s WHERE s.version_id = :vid';
        $params = ['vid' => $versionId];

        if ($type) {
            $sql .= ' AND s.symbol_type = :type';
            $params['type'] = $type;
        }

        if ($language) {
            $sql .= ' AND s.language = :language';
            $params['language'] = $language;
        }

        if ($search) {
            $sql .= ' AND (s.fqn LIKE :search OR s.name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        return (int) $this->db->query($sql, $params)->fetch()['cnt'];
    }

    #[\NoDiscard]
    public function getSymbolTypes(int $versionId): array
    {
        return $this->db->query(
            'SELECT DISTINCT symbol_type FROM symbols WHERE version_id = :vid ORDER BY symbol_type',
            ['vid' => $versionId]
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Get symbol types grouped by language for a version.
     *
     * @return array<string, list<string>> Language => [symbol_type, ...]
     */
    #[\NoDiscard]
    public function getSymbolTypesGroupedByLanguage(int $versionId): array
    {
        $rows = $this->db->query(
            'SELECT DISTINCT language, symbol_type
             FROM symbols
             WHERE version_id = :vid
             ORDER BY language, symbol_type',
            ['vid' => $versionId]
        )->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['language']][] = (string) $row['symbol_type'];
        }

        return $grouped;
    }

    #[\NoDiscard]
    public function findDeprecated(int $versionId): array
    {
        return $this->db->query(
            'SELECT * FROM symbols WHERE version_id = :vid AND is_deprecated = 1',
            ['vid' => $versionId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findBySignatureHash(int $versionId, string $hash): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM symbols WHERE version_id = :vid AND signature_hash = :hash',
            ['vid' => $versionId, 'hash' => $hash]
        )->fetch();
        return $row ?: null;
    }

    #[\NoDiscard]
    public function countByVersion(int $versionId): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) as cnt FROM symbols WHERE version_id = :vid',
            ['vid' => $versionId]
        )->fetch()['cnt'];
    }

    #[\NoDiscard]
    public function findByTypeAndVersion(int $versionId, SymbolType $symbolType): array
    {
        return $this->db->query(
            'SELECT s.*, f.file_path
             FROM symbols s
             JOIN parsed_files f ON s.file_id = f.id
             WHERE s.version_id = :vid AND s.symbol_type = :type
             ORDER BY s.fqn',
            ['vid' => $versionId, 'type' => $symbolType->value]
        )->fetchAll();
    }
}
