<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage;

use DrupalEvolver\Storage\Repository\ChangeRepo;
use DrupalEvolver\Storage\Repository\FileRepo;
use DrupalEvolver\Storage\Repository\MatchRepo;
use DrupalEvolver\Storage\Repository\ProjectRepo;
use DrupalEvolver\Storage\Repository\SymbolRepo;
use DrupalEvolver\Storage\Repository\VersionRepo;
use Generator;
use PDOStatement;

/**
 * Centralized database facade.
 *
 * Owns the connection, schema, all repositories, and cross-table queries.
 * Commands and services construct this instead of manually wiring Database + Schema + N repos.
 */
class DatabaseApi
{
    private const SYMBOL_PAIR_FETCH_CHUNK_SIZE = 200;

    private Database $database;
    private ?VersionRepo $versionRepo = null;
    private ?FileRepo $fileRepo = null;
    private ?SymbolRepo $symbolRepo = null;
    private ?ChangeRepo $changeRepo = null;
    private ?ProjectRepo $projectRepo = null;
    private ?MatchRepo $matchRepo = null;

    /** @var array<string, PDOStatement> */
    private array $stmtCache = [];

    /** @var array<string, string> */
    private array $stmtSqlCache = [];

    public function __construct(string $path = '')
    {
        $this->database = new Database($path ?: Database::defaultPath());
        (new Schema($this->database))->createAll();
    }

    // -- Accessors -----------------------------------------------------------

    #[\NoDiscard]
    public function db(): Database
    {
        return $this->database;
    }

    #[\NoDiscard]
    public function getPath(): string
    {
        return $this->database->getPath();
    }

    #[\NoDiscard]
    public function versions(): VersionRepo
    {
        return $this->versionRepo ??= new VersionRepo($this->database);
    }

    #[\NoDiscard]
    public function files(): FileRepo
    {
        return $this->fileRepo ??= new FileRepo($this->database);
    }

    #[\NoDiscard]
    public function symbols(): SymbolRepo
    {
        return $this->symbolRepo ??= new SymbolRepo($this->database);
    }

    #[\NoDiscard]
    public function changes(): ChangeRepo
    {
        return $this->changeRepo ??= new ChangeRepo($this->database, $this);
    }

    #[\NoDiscard]
    public function projects(): ProjectRepo
    {
        return $this->projectRepo ??= new ProjectRepo($this->database);
    }

    #[\NoDiscard]
    public function matches(): MatchRepo
    {
        return $this->matchRepo ??= new MatchRepo($this->database, $this);
    }

    #[\NoDiscard]
    public function prepare(string $key, string $sql): PDOStatement
    {
        if (isset($this->stmtCache[$key])) {
            if (($this->stmtSqlCache[$key] ?? $sql) !== $sql) {
                throw new \LogicException(sprintf('Statement cache key "%s" was reused for different SQL.', $key));
            }

            return $this->stmtCache[$key];
        }

        $this->stmtSqlCache[$key] = $sql;

        return $this->stmtCache[$key] = $this->database->pdo()->prepare($sql);
    }

    // -- Cross-table diff queries --------------------------------------------

    /**
     * Symbols present in $fromVersionId but not in $toVersionId.
     * Uses EXCEPT for ~35% speed gain over NOT EXISTS.
     *
     * @return Generator<int, array<string, mixed>>
     */
    #[\NoDiscard]
    public function findRemovedSymbols(int $fromVersionId, int $toVersionId, ?string $pathFilter = null): Generator
    {
        // Step 1: Get the FQN+type pairs that were removed (EXCEPT is fastest).
        $exceptSql = 'SELECT fqn, symbol_type FROM symbols WHERE version_id = :old
                       EXCEPT
                       SELECT fqn, symbol_type FROM symbols WHERE version_id = :new';
        $pairs = $this->database->query($exceptSql, ['old' => $fromVersionId, 'new' => $toVersionId])->fetchAll();

        if (empty($pairs)) {
            return;
        }

        yield from $this->yieldSymbolsForPairs($fromVersionId, $pairs, $pathFilter);
    }

    /**
     * Symbols present in $toVersionId but not in $fromVersionId.
     *
     * @return Generator<int, array<string, mixed>>
     */
    #[\NoDiscard]
    public function findAddedSymbols(int $fromVersionId, int $toVersionId, ?string $pathFilter = null): Generator
    {
        $exceptSql = 'SELECT fqn, symbol_type FROM symbols WHERE version_id = :new
                       EXCEPT
                       SELECT fqn, symbol_type FROM symbols WHERE version_id = :old';
        $pairs = $this->database->query($exceptSql, ['old' => $fromVersionId, 'new' => $toVersionId])->fetchAll();

        if (empty($pairs)) {
            return;
        }

        yield from $this->yieldSymbolsForPairs($toVersionId, $pairs, $pathFilter);
    }

    /**
     * Symbols with same FQN+type but different signature_hash across versions.
     *
     * Uses SQLite json_extract() to retrieve param_count and return_type directly in SQL,
     * avoiding PHP json_decode() for these common fields (2-6x faster per benchmarks).
     *
     * @return Generator<int, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    #[\NoDiscard]
    public function findChangedSignatures(int $fromVersionId, int $toVersionId, ?string $pathFilter = null): Generator
    {
        $sql = 'SELECT o.id as old_id, o.fqn, o.symbol_type, o.language, o.signature_json as old_sig_json,
                    o.signature_hash as old_hash, n.id as new_id, n.signature_json as new_sig_json,
                    n.signature_hash as new_hash,
                    json_extract(o.signature_json, "$.params") as old_params,
                    json_extract(o.signature_json, "$.return_type") as old_return_type,
                    json_extract(n.signature_json, "$.params") as new_params,
                    json_extract(n.signature_json, "$.return_type") as new_return_type,
                    json_array_length(o.signature_json, "$.params") as old_param_count,
                    json_array_length(n.signature_json, "$.params") as new_param_count
             FROM symbols o
             JOIN symbols n ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
             WHERE o.version_id = :old AND n.version_id = :new
               AND o.signature_hash != n.signature_hash';

        $params = ['old' => $fromVersionId, 'new' => $toVersionId];

        if ($pathFilter) {
            $sql = 'SELECT o.id as old_id, o.fqn, o.symbol_type, o.language, o.signature_json as old_sig_json,
                        o.signature_hash as old_hash, n.id as new_id, n.signature_json as new_sig_json,
                        n.signature_hash as new_hash,
                        json_extract(o.signature_json, "$.params") as old_params,
                        json_extract(o.signature_json, "$.return_type") as old_return_type,
                        json_extract(n.signature_json, "$.params") as new_params,
                        json_extract(n.signature_json, "$.return_type") as new_return_type,
                        json_array_length(o.signature_json, "$.params") as old_param_count,
                        json_array_length(n.signature_json, "$.params") as new_param_count
                 FROM symbols o
                 JOIN parsed_files f ON o.file_id = f.id
                 JOIN symbols n ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
                 WHERE o.version_id = :old AND n.version_id = :new
                   AND o.signature_hash != n.signature_hash
                   AND f.file_path LIKE :path';
            $params['path'] = $pathFilter . '%';
        }

        $stmt = $this->database->query($sql, $params);

        while ($row = $stmt->fetch()) {
            yield [
                'old' => [
                    'id' => $row['old_id'],
                    'fqn' => $row['fqn'],
                    'symbol_type' => $row['symbol_type'],
                    'language' => $row['language'],
                    'signature_json' => $row['old_sig_json'],
                    'params' => $row['old_params'],
                    'return_type' => $row['old_return_type'],
                    'param_count' => $row['old_param_count'],
                ],
                'new' => [
                    'id' => $row['new_id'],
                    'fqn' => $row['fqn'],
                    'symbol_type' => $row['symbol_type'],
                    'language' => $row['language'],
                    'signature_json' => $row['new_sig_json'],
                    'params' => $row['new_params'],
                    'return_type' => $row['new_return_type'],
                    'param_count' => $row['new_param_count'],
                ],
            ];
        }
    }

    /**
     * Find signature changes where parameter count changed.
     * Uses SQLite json_array_length() for efficient filtering (0.93ms vs 5.58ms for PHP-side filter).
     *
     * @param int $fromVersionId Source version ID
     * @param int $toVersionId Target version ID
     * @param 'increased'|'decreased'|'changed'|null $direction Filter by change direction
     * @return Generator<int, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    #[\NoDiscard]
    public function findParamCountChanges(int $fromVersionId, int $toVersionId, ?string $direction = null): Generator
    {
        $sql = 'SELECT o.id as old_id, o.fqn, o.symbol_type, o.language, o.signature_json as old_sig_json,
                    o.signature_hash as old_hash, n.id as new_id, n.signature_json as new_sig_json,
                    n.signature_hash as new_hash,
                    json_extract(o.signature_json, "$.params") as old_params,
                    json_extract(o.signature_json, "$.return_type") as old_return_type,
                    json_extract(n.signature_json, "$.params") as new_params,
                    json_extract(n.signature_json, "$.return_type") as new_return_type,
                    json_array_length(o.signature_json, "$.params") as old_param_count,
                    json_array_length(n.signature_json, "$.params") as new_param_count
             FROM symbols o
             JOIN symbols n ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
             WHERE o.version_id = :old AND n.version_id = :new
               AND json_array_length(o.signature_json, "$.params") != json_array_length(n.signature_json, "$.params")';

        $params = ['old' => $fromVersionId, 'new' => $toVersionId];

        if ($direction === 'increased') {
            $sql .= ' AND json_array_length(o.signature_json, "$.params") < json_array_length(n.signature_json, "$.params")';
        } elseif ($direction === 'decreased') {
            $sql .= ' AND json_array_length(o.signature_json, "$.params") > json_array_length(n.signature_json, "$.params")';
        }

        $sql .= ' ORDER BY new_param_count - old_param_count DESC';

        $stmt = $this->database->query($sql, $params);

        while ($row = $stmt->fetch()) {
            yield [
                'old' => [
                    'id' => $row['old_id'],
                    'fqn' => $row['fqn'],
                    'symbol_type' => $row['symbol_type'],
                    'language' => $row['language'],
                    'signature_json' => $row['old_sig_json'],
                    'params' => $row['old_params'],
                    'return_type' => $row['old_return_type'],
                    'param_count' => $row['old_param_count'],
                ],
                'new' => [
                    'id' => $row['new_id'],
                    'fqn' => $row['fqn'],
                    'symbol_type' => $row['symbol_type'],
                    'language' => $row['language'],
                    'signature_json' => $row['new_sig_json'],
                    'params' => $row['new_params'],
                    'return_type' => $row['new_return_type'],
                    'param_count' => $row['new_param_count'],
                ],
            ];
        }
    }

    // -- Deprecation queries -------------------------------------------------

    /**
     * Symbols not deprecated in old version but deprecated in new.
     */
    #[\NoDiscard]
    public function findNewlyDeprecated(int $fromVersionId, int $toVersionId, ?string $pathFilter = null): array
    {
        $sql = 'SELECT n.* FROM symbols n
                JOIN symbols o ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
                WHERE n.version_id = :new AND o.version_id = :old
                  AND n.is_deprecated = 1 AND o.is_deprecated = 0';
        $params = ['old' => $fromVersionId, 'new' => $toVersionId];

        if ($pathFilter) {
            $sql = 'SELECT n.* FROM symbols n
                    JOIN parsed_files f ON n.file_id = f.id
                    JOIN symbols o ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
                    WHERE n.version_id = :new AND o.version_id = :old
                      AND n.is_deprecated = 1 AND o.is_deprecated = 0
                      AND f.file_path LIKE :path';
            $params['path'] = $pathFilter . '%';
        }

        return $this->database->query($sql, $params)->fetchAll();
    }

    /**
     * Symbols deprecated in old version that no longer exist in new.
     */
    #[\NoDiscard]
    public function findDeprecatedThenRemoved(int $fromVersionId, int $toVersionId, ?string $pathFilter = null): array
    {
        $sql = 'SELECT o.* FROM symbols o
                WHERE o.version_id = :old AND o.is_deprecated = 1
                AND NOT EXISTS (
                    SELECT 1 FROM symbols n
                    WHERE n.version_id = :new AND n.symbol_type = o.symbol_type AND n.fqn = o.fqn
                )';
        $params = ['old' => $fromVersionId, 'new' => $toVersionId];

        if ($pathFilter) {
            $sql = 'SELECT o.* FROM symbols o
                    JOIN parsed_files f ON o.file_id = f.id
                    WHERE o.version_id = :old AND o.is_deprecated = 1
                    AND f.file_path LIKE :path
                    AND NOT EXISTS (
                        SELECT 1 FROM symbols n
                        WHERE n.version_id = :new AND n.symbol_type = o.symbol_type AND n.fqn = o.fqn
                    )';
            $params['path'] = $pathFilter . '%';
        }

        return $this->database->query($sql, $params)->fetchAll();
    }

    // -- Applier / Report queries --------------------------------------------

    /**
     * Pending matches with their fix_template and change metadata. Used by TemplateApplier.
     */
    #[\NoDiscard]
    public function findPendingFixesWithTemplates(int $projectId): array
    {
        return $this->database->query(
            "SELECT cm.*, c.fix_template, c.change_type, c.old_fqn
             FROM code_matches cm
             JOIN changes c ON cm.change_id = c.id
             WHERE cm.project_id = :pid AND cm.status = 'pending' AND c.fix_template IS NOT NULL",
            ['pid' => $projectId]
        )->fetchAll();
    }

    /**
     * All matches with change info for reporting. Used by ReportCommand.
     */
    #[\NoDiscard]
    public function findMatchesWithChanges(int $projectId): array
    {
        return $this->database->query(
            "SELECT cm.*, c.change_type, c.severity, c.old_fqn
             FROM code_matches cm
             JOIN changes c ON cm.change_id = c.id
             WHERE cm.project_id = :pid
             ORDER BY cm.file_path, cm.line_start",
            ['pid' => $projectId]
        )->fetchAll();
    }

    // -- Status / utility queries --------------------------------------------

    /**
     * Aggregate stats for StatusCommand.
     */
    #[\NoDiscard]
    public function getStats(): array
    {
        return [
            'versions' => $this->database->query('SELECT * FROM versions ORDER BY weight, id')->fetchAll(),
            'file_count' => (int) $this->database->query('SELECT COUNT(*) as cnt FROM parsed_files')->fetch()['cnt'],
            'symbol_count' => (int) $this->database->query('SELECT COUNT(*) as cnt FROM symbols')->fetch()['cnt'],
            'change_count' => (int) $this->database->query('SELECT COUNT(*) as cnt FROM changes')->fetch()['cnt'],
            'project_count' => (int) $this->database->query('SELECT COUNT(*) as cnt FROM projects')->fetch()['cnt'],
            'match_count' => (int) $this->database->query('SELECT COUNT(*) as cnt FROM code_matches')->fetch()['cnt'],
        ];
    }

    /**
     * Delete existing changes for a version pair before re-diffing.
     */
    public function deleteChangesForPair(int $fromVersionId, int $toVersionId): int
    {
        return $this->database->execute(
            'DELETE FROM changes WHERE from_version_id = ? AND to_version_id = ?',
            [$fromVersionId, $toVersionId]
        );
    }

    /**
     * Fetch a single symbol by ID.
     */
    #[\NoDiscard]
    public function findSymbolById(int $id): ?array
    {
        $row = $this->database->query('SELECT * FROM symbols WHERE id = ?', [$id])->fetch();
        return $row ?: null;
    }

    /**
     * @param array<int, array{fqn: string, symbol_type: string}> $pairs
     * @return Generator<int, array<string, mixed>>
     */
    private function yieldSymbolsForPairs(int $versionId, array $pairs, ?string $pathFilter = null): Generator
    {
        foreach (array_chunk($pairs, self::SYMBOL_PAIR_FETCH_CHUNK_SIZE) as $chunkIndex => $pairChunk) {
            $sql = 'SELECT s.* FROM symbols s';
            $params = ['vid' => $versionId];

            if ($pathFilter) {
                $sql .= ' JOIN parsed_files f ON s.file_id = f.id';
            }

            $sql .= ' WHERE s.version_id = :vid AND (';

            $conditions = [];
            foreach ($pairChunk as $i => $pair) {
                $suffix = $chunkIndex . '_' . $i;
                $conditions[] = "(s.fqn = :fqn{$suffix} AND s.symbol_type = :type{$suffix})";
                $params["fqn{$suffix}"] = $pair['fqn'];
                $params["type{$suffix}"] = $pair['symbol_type'];
            }

            $sql .= implode(' OR ', $conditions) . ')';

            if ($pathFilter) {
                $sql .= ' AND f.file_path LIKE :path';
                $params['path'] = $pathFilter . '%';
            }

            $stmt = $this->database->query($sql, $params);
            while ($row = $stmt->fetch()) {
                yield $row;
            }
        }
    }
}
