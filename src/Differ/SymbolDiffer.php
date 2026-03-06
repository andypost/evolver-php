<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

use DrupalEvolver\Storage\Database;
use Generator;

class SymbolDiffer
{
    private ?string $pathFilter = null;

    public function __construct(private Database $db) {}

    public function setPathFilter(?string $path): void
    {
        $this->pathFilter = $path;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function findRemoved(int $fromVersionId, int $toVersionId): Generator
    {
        $sql = 'SELECT o.* FROM symbols o
                JOIN parsed_files f ON o.file_id = f.id
                WHERE o.version_id = :old';
        $params = ['old' => $fromVersionId, 'new' => $toVersionId];

        if ($this->pathFilter) {
            $sql .= ' AND f.file_path LIKE :path';
            $params['path'] = $this->pathFilter . '%';
        }

        $sql .= ' AND NOT EXISTS (
                   SELECT 1 FROM symbols n
                   WHERE n.version_id = :new
                     AND n.fqn = o.fqn
                     AND n.symbol_type = o.symbol_type
               )';

        $stmt = $this->db->query($sql, $params);

        while ($row = $stmt->fetch()) {
            yield $row;
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function findAdded(int $fromVersionId, int $toVersionId): Generator
    {
        $sql = 'SELECT n.* FROM symbols n
                JOIN parsed_files f ON n.file_id = f.id
                WHERE n.version_id = :new';
        $params = ['old' => $fromVersionId, 'new' => $toVersionId];

        if ($this->pathFilter) {
            $sql .= ' AND f.file_path LIKE :path';
            $params['path'] = $this->pathFilter . '%';
        }

        $sql .= ' AND NOT EXISTS (
                   SELECT 1 FROM symbols o
                   WHERE o.version_id = :old
                     AND o.fqn = n.fqn
                     AND o.symbol_type = n.symbol_type
               )';

        $stmt = $this->db->query($sql, $params);

        while ($row = $stmt->fetch()) {
            yield $row;
        }
    }

    /**
     * @return Generator<int, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    public function findChanged(int $fromVersionId, int $toVersionId): Generator
    {
        $sql = 'SELECT o.id as old_id, o.fqn, o.symbol_type, o.language, o.signature_json as old_sig_json,
                    o.signature_hash as old_hash, n.id as new_id, n.signature_json as new_sig_json,
                    n.signature_hash as new_hash
             FROM symbols o
             JOIN parsed_files f ON o.file_id = f.id
             JOIN symbols n ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
             WHERE o.version_id = :old AND n.version_id = :new
               AND o.signature_hash != n.signature_hash';
        
        $params = ['old' => $fromVersionId, 'new' => $toVersionId];

        if ($this->pathFilter) {
            $sql .= ' AND f.file_path LIKE :path';
            $params['path'] = $this->pathFilter . '%';
        }

        $stmt = $this->db->query($sql, $params);

        while ($row = $stmt->fetch()) {
            yield [
                'old' => [
                    'id' => $row['old_id'],
                    'fqn' => $row['fqn'],
                    'symbol_type' => $row['symbol_type'],
                    'language' => $row['language'],
                    'signature_json' => $row['old_sig_json'],
                ],
                'new' => [
                    'id' => $row['new_id'],
                    'fqn' => $row['fqn'],
                    'symbol_type' => $row['symbol_type'],
                    'language' => $row['language'],
                    'signature_json' => $row['new_sig_json'],
                ],
            ];
        }
    }
}
