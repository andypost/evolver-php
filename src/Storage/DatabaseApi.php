<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage;

use DrupalEvolver\Storage\Repository\ChangeRepo;
use DrupalEvolver\Storage\Repository\FileRepo;
use DrupalEvolver\Storage\Repository\JobLogRepo;
use DrupalEvolver\Storage\Repository\JobRepo;
use DrupalEvolver\Storage\Repository\MatchRepo;
use DrupalEvolver\Storage\Repository\ProjectBranchRepo;
use DrupalEvolver\Storage\Repository\ProjectRepo;
use DrupalEvolver\Storage\Repository\ScanRunRepo;
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
    private ?ProjectBranchRepo $projectBranchRepo = null;
    private ?ScanRunRepo $scanRunRepo = null;
    private ?JobRepo $jobRepo = null;
    private ?JobLogRepo $jobLogRepo = null;
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
    public function projectBranches(): ProjectBranchRepo
    {
        return $this->projectBranchRepo ??= new ProjectBranchRepo($this->database);
    }

    #[\NoDiscard]
    public function scanRuns(): ScanRunRepo
    {
        return $this->scanRunRepo ??= new ScanRunRepo($this->database);
    }

    #[\NoDiscard]
    public function jobs(): JobRepo
    {
        return $this->jobRepo ??= new JobRepo($this->database);
    }

    #[\NoDiscard]
    public function jobLogs(): JobLogRepo
    {
        return $this->jobLogRepo ??= new JobLogRepo($this->database);
    }

    #[\NoDiscard]
    public function matches(): MatchRepo
    {
        return $this->matchRepo ??= new MatchRepo($this->database, $this);
    }

    #[\NoDiscard]
    public function findSymbolById(int $id): ?array
    {
        $sql = 'SELECT s.*, f.file_path 
                FROM symbols s 
                JOIN parsed_files f ON s.file_id = f.id 
                WHERE s.id = :id';
        return $this->database->query($sql, ['id' => $id])->fetch() ?: null;
    }

    /**
     * @return array<int, array{relationship: string, symbol: array<string, mixed>}>
     */
    #[\NoDiscard]
    public function findSemanticLinksForSymbol(int $symbolId): array
    {
        $symbol = $this->findSymbolById($symbolId);
        if ($symbol === null) {
            return [];
        }

        $versionId = (int) ($symbol['version_id'] ?? 0);
        if ($versionId <= 0) {
            return [];
        }

        $links = [];
        $symbolType = (string) ($symbol['symbol_type'] ?? '');
        $language = (string) ($symbol['language'] ?? '');

        if ($language === 'yaml' && $symbolType === 'service') {
            $signature = $this->decodeJsonMap($symbol['signature_json'] ?? null);
            $classFqn = ltrim((string) ($signature['class'] ?? ''), '\\');
            if ($classFqn !== '') {
                $classSymbol = $this->database->query(
                    'SELECT s.*, f.file_path
                     FROM symbols s
                     JOIN parsed_files f ON f.id = s.file_id
                     WHERE s.version_id = :vid
                       AND s.language = :lang
                       AND s.symbol_type = :type
                       AND s.fqn = :fqn
                     LIMIT 1',
                    [
                        'vid' => $versionId,
                        'lang' => 'php',
                        'type' => 'class',
                        'fqn' => $classFqn,
                    ]
                )->fetch();

                if ($classSymbol !== false) {
                    $links[] = [
                        'relationship' => 'implementation_class',
                        'symbol' => $classSymbol,
                    ];
                }
            }
        }

        if ($language === 'php' && $symbolType === 'class') {
            $serviceRows = $this->database->query(
                'SELECT s.*, f.file_path
                 FROM symbols s
                 JOIN parsed_files f ON f.id = s.file_id
                 WHERE s.version_id = :vid
                   AND s.language = :lang
                   AND s.symbol_type = :type
                   AND ltrim(COALESCE(json_extract(s.signature_json, \'$.class\'), \'\'), \'\\\') = :fqn
                 ORDER BY s.fqn',
                [
                    'vid' => $versionId,
                    'lang' => 'yaml',
                    'type' => 'service',
                    'fqn' => (string) ($symbol['fqn'] ?? ''),
                ]
            )->fetchAll();

            foreach ($serviceRows as $serviceRow) {
                $links[] = [
                    'relationship' => 'registered_service',
                    'symbol' => $serviceRow,
                ];
            }
        }

        return $links;
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
    public function findPendingFixesWithTemplates(int $projectId, ?int $scanRunId = null): array
    {
        if ($scanRunId !== null) {
            return $this->findPendingFixesWithTemplatesForRun($scanRunId);
        }

        return $this->database->query(
            "SELECT cm.*, c.fix_template, c.change_type, c.old_fqn
             FROM code_matches cm
             JOIN changes c ON cm.change_id = c.id
             WHERE cm.project_id = :pid AND cm.status = 'pending' AND c.fix_template IS NOT NULL",
            ['pid' => $projectId]
        )->fetchAll();
    }

    /**
     * Pending matches with templates for a specific scan run.
     */
    #[\NoDiscard]
    public function findPendingFixesWithTemplatesForRun(int $scanRunId): array
    {
        return $this->database->query(
            "SELECT cm.*, c.fix_template, c.change_type, c.old_fqn
             FROM code_matches cm
             JOIN changes c ON cm.change_id = c.id
             WHERE cm.scan_run_id = :scan_run_id
               AND cm.status = 'pending'
               AND c.fix_template IS NOT NULL
             ORDER BY cm.file_path, cm.line_start, cm.id",
            ['scan_run_id' => $scanRunId]
        )->fetchAll();
    }

    /**
     * All matches with change info for reporting. Used by ReportCommand.
     */
    #[\NoDiscard]
    public function findMatchesWithChanges(int $projectId, ?int $scanRunId = null): array
    {
        if ($scanRunId !== null) {
            return $this->findMatchesWithChangesForRun($scanRunId);
        }

        return $this->database->query(
            "SELECT cm.*, c.change_type, c.severity, c.old_fqn, c.new_fqn
             FROM code_matches cm
             JOIN changes c ON cm.change_id = c.id
             WHERE cm.project_id = :pid
             ORDER BY cm.file_path, cm.line_start, cm.id",
            ['pid' => $projectId]
        )->fetchAll();
    }

    /**
     * All matches with change info for a specific scan run.
     */
    #[\NoDiscard]
    public function findMatchesWithChangesForRun(int $scanRunId): array
    {
        return $this->database->query(
            "SELECT cm.*, c.change_type, c.severity, c.old_fqn, c.new_fqn
             FROM code_matches cm
             JOIN changes c ON cm.change_id = c.id
             WHERE cm.scan_run_id = :scan_run_id
             ORDER BY cm.file_path, cm.line_start, cm.id",
            ['scan_run_id' => $scanRunId]
        )->fetchAll();
    }

    /**
     * Aggregate summary for a scan run, used by run detail pages and completion bookkeeping.
     *
     * @return array{total: int, auto_fixable: int, by_severity: array<string, int>, by_change_type: array<string, int>}
     */
    #[\NoDiscard]
    public function summarizeScanRun(int $scanRunId): array
    {
        $rows = $this->database->query(
            "SELECT c.severity, c.change_type, cm.fix_method, COUNT(*) AS cnt
             FROM code_matches cm
             JOIN changes c ON cm.change_id = c.id
             WHERE cm.scan_run_id = :scan_run_id
             GROUP BY c.severity, c.change_type, cm.fix_method",
            ['scan_run_id' => $scanRunId]
        )->fetchAll();

        $summary = [
            'total' => 0,
            'auto_fixable' => 0,
            'by_severity' => [],
            'by_change_type' => [],
        ];

        foreach ($rows as $row) {
            $count = (int) ($row['cnt'] ?? 0);
            $severity = (string) ($row['severity'] ?? 'unknown');
            $changeType = (string) ($row['change_type'] ?? 'unknown');
            $summary['total'] += $count;
            $summary['by_severity'][$severity] = ($summary['by_severity'][$severity] ?? 0) + $count;
            $summary['by_change_type'][$changeType] = ($summary['by_change_type'][$changeType] ?? 0) + $count;

            if (($row['fix_method'] ?? null) === 'template') {
                $summary['auto_fixable'] += $count;
            }
        }

        return $summary;
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
            'scan_run_count' => (int) $this->database->query('SELECT COUNT(*) as cnt FROM scan_runs')->fetch()['cnt'],
            'job_count' => (int) $this->database->query('SELECT COUNT(*) as cnt FROM jobs')->fetch()['cnt'],
            'active_job_count' => (int) $this->database->query("SELECT COUNT(*) as cnt FROM jobs WHERE status IN ('queued', 'running')")->fetch()['cnt'],
            'match_count' => (int) $this->database->query('SELECT COUNT(*) as cnt FROM code_matches')->fetch()['cnt'],
        ];
    }

    #[\NoDiscard]
    public function getChangesSummaryByVersionPair(): array
    {
        return $this->database->query(
            'SELECT vf.tag AS from_tag, vt.tag AS to_tag, COUNT(*) AS change_count,
                    SUM(CASE WHEN c.severity = "breaking" THEN 1 ELSE 0 END) AS breaking_count,
                    SUM(CASE WHEN c.severity = "deprecation" THEN 1 ELSE 0 END) AS deprecation_count,
                    SUM(CASE WHEN c.severity = "notice" THEN 1 ELSE 0 END) AS notice_count
             FROM changes c
             JOIN versions vf ON c.from_version_id = vf.id
             JOIN versions vt ON c.to_version_id = vt.id
             GROUP BY c.from_version_id, c.to_version_id
             ORDER BY vf.weight, vt.weight'
        )->fetchAll();
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
     * Search semantic YAML symbols by exact references stored in metadata/signature JSON.
     *
     * Intended for Drupal YAML use cases like:
     * - find info files mentioning module "block"
     * - find links referencing route "entity.block.edit_form"
     * - find exported config depending on module "node"
     *
     * @param array<int, string> $symbolTypes
     * @return array<int, array<string, mixed>>
     */
    #[\NoDiscard]
    public function searchSemanticYamlSymbols(int $versionId, string $term, array $symbolTypes = [], int $limit = 100): array
    {
        $term = trim($term);
        $limit = max(1, $limit);
        if ($term === '') {
            return [];
        }

        $sql = <<<SQL
            SELECT
                s.*,
                f.file_path,
                cs.fqn AS resolved_class_fqn,
                cf.file_path AS resolved_class_file_path
            FROM symbols s
            JOIN parsed_files f ON f.id = s.file_id
            LEFT JOIN symbols cs ON cs.version_id = s.version_id
                AND cs.language = 'php'
                AND cs.symbol_type = 'class'
                AND cs.fqn = ltrim(COALESCE(json_extract(s.signature_json, '$.class'), ''), '\\')
            LEFT JOIN parsed_files cf ON cf.id = cs.file_id
            WHERE s.version_id = :vid
              AND s.language = :lang
            SQL;
        $params = [
            'vid' => $versionId,
            'lang' => 'yaml',
            'term' => $term,
            'like' => '%' . $term . '%',
        ];

        if ($symbolTypes !== []) {
            $typeConditions = [];
            foreach (array_values($symbolTypes) as $index => $symbolType) {
                $key = 'type' . $index;
                $typeConditions[] = ':' . $key;
                $params[$key] = $symbolType;
            }

            $sql .= ' AND s.symbol_type IN (' . implode(', ', $typeConditions) . ')';
        }

        $sql .= <<<SQL
         AND (
                s.fqn = :term
                OR s.name = :term
                OR s.fqn LIKE :like
                OR s.name LIKE :like
                OR json_extract(s.metadata_json, '$.label') LIKE :like
                OR json_extract(s.signature_json, '$.class') = :term
                OR json_extract(s.signature_json, '$.class') LIKE :like
                OR json_extract(s.signature_json, '$.path') = :term
                OR json_extract(s.signature_json, '$.path') LIKE :like
                OR json_extract(s.signature_json, '$.controller') = :term
                OR json_extract(s.signature_json, '$.controller') LIKE :like
                OR json_extract(s.metadata_json, '$.configure_route') = :term
                OR json_extract(s.metadata_json, '$.base_theme') = :term
                OR json_extract(s.metadata_json, '$.route_name') = :term
                OR json_extract(s.metadata_json, '$.base_route') = :term
                OR json_extract(s.metadata_json, '$.parent') = :term
                OR json_extract(s.metadata_json, '$.parent_id') = :term
                OR EXISTS (
                    SELECT 1
                    FROM json_each(COALESCE(s.metadata_json, '{}'), '$.mentioned_extensions')
                    WHERE json_each.value = :term
                )
                OR EXISTS (
                    SELECT 1
                    FROM json_each(COALESCE(s.metadata_json, '{}'), '$.dependency_targets')
                    WHERE json_each.value = :term
                )
                OR EXISTS (
                    SELECT 1
                    FROM json_each(COALESCE(s.metadata_json, '{}'), '$.route_refs')
                    WHERE json_each.value = :term
                )
                OR EXISTS (
                    SELECT 1
                    FROM json_each(COALESCE(s.metadata_json, '{}'), '$.install')
                    WHERE json_each.value = :term
                )
                OR EXISTS (
                    SELECT 1
                    FROM json_each(COALESCE(s.metadata_json, '{}'), '$.recipes')
                    WHERE json_each.value = :term
                )
                OR EXISTS (
                    SELECT 1
                    FROM json_each(COALESCE(s.signature_json, '{}'), '$.dependencies')
                    WHERE json_each.value = :term
                )
                OR EXISTS (
                    SELECT 1
                    FROM json_each(COALESCE(s.signature_json, '{}'), '$.dependencies.module')
                    WHERE json_each.value = :term
                )
                OR EXISTS (
                    SELECT 1
                    FROM json_each(COALESCE(s.signature_json, '{}'), '$.dependencies.theme')
                    WHERE json_each.value = :term
                )
                OR EXISTS (
                    SELECT 1
                    FROM json_each(COALESCE(s.signature_json, '{}'), '$.dependencies.config')
                    WHERE json_each.value = :term
                )
            )
            ORDER BY s.symbol_type, s.fqn
            LIMIT {$limit}
        SQL;

        return $this->database->query($sql, $params)->fetchAll();
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

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonMap(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
