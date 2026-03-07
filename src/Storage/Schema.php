<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage;

final class Schema
{
    public function __construct(private Database $db) {}

    public function createAll(): void
    {
        foreach ($this->baseStatements() as $sql) {
            $this->db->pdo()->exec($sql);
        }

        $this->ensureColumnExists('versions', 'weight', 'INTEGER');
        $this->ensureColumnExists('projects', 'source_type', "TEXT NOT NULL DEFAULT 'local_path'");
        $this->ensureColumnExists('projects', 'remote_url', 'TEXT');
        $this->ensureColumnExists('projects', 'default_branch', 'TEXT');

        $this->migrateCodeMatchesTable();
        $this->ensureCodeMatchIndexes();

        (void) $this->db->execute(
            'UPDATE versions SET weight = (major * 1000000) + (minor * 1000) + patch WHERE weight IS NULL'
        );
        (void) $this->db->execute("UPDATE projects SET source_type = 'local_path' WHERE source_type IS NULL OR source_type = ''");

        $this->deduplicateProjectsByPath();
        $this->ensureMatchScopeKeys();
        $this->deduplicateCodeMatchesByScope();

        $this->db->pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_project_path ON projects(path)');
        (void) $this->db->execute(
            "INSERT OR REPLACE INTO schema_meta (key, value) VALUES ('schema_version', '3')"
        );
    }

    /**
     * @return list<string>
     */
    private function baseStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS versions (
                id           INTEGER PRIMARY KEY,
                tag          TEXT NOT NULL UNIQUE,
                major        INTEGER NOT NULL,
                minor        INTEGER NOT NULL,
                patch        INTEGER NOT NULL,
                weight       INTEGER NOT NULL,
                file_count   INTEGER DEFAULT 0,
                symbol_count INTEGER DEFAULT 0,
                indexed_at   TEXT
            )',

            'CREATE TABLE IF NOT EXISTS parsed_files (
                id          INTEGER PRIMARY KEY,
                version_id  INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
                file_path   TEXT NOT NULL,
                language    TEXT NOT NULL,
                file_hash   TEXT NOT NULL,
                ast_sexp    BLOB,
                ast_json    TEXT,
                line_count  INTEGER,
                byte_size   INTEGER,
                parsed_at   TEXT DEFAULT (datetime(\'now\')),
                UNIQUE(version_id, file_path)
            )',

            'CREATE TABLE IF NOT EXISTS symbols (
                id                  INTEGER PRIMARY KEY,
                version_id          INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
                file_id             INTEGER NOT NULL REFERENCES parsed_files(id) ON DELETE CASCADE,
                language            TEXT NOT NULL,
                symbol_type         TEXT NOT NULL,
                fqn                 TEXT NOT NULL,
                name                TEXT NOT NULL,
                namespace           TEXT,
                parent_symbol       TEXT,
                visibility          TEXT,
                is_static           INTEGER DEFAULT 0,
                signature_hash      TEXT,
                signature_json      TEXT,
                ast_node_sexp       TEXT,
                ast_node_json       TEXT,
                source_text         TEXT,
                line_start          INTEGER,
                line_end            INTEGER,
                byte_start          INTEGER,
                byte_end            INTEGER,
                docblock            TEXT,
                is_deprecated       INTEGER DEFAULT 0,
                deprecation_message TEXT,
                deprecation_version TEXT,
                removal_version     TEXT,
                metadata_json       TEXT
            )',

            'CREATE INDEX IF NOT EXISTS idx_sym_fqn ON symbols(fqn)',
            'CREATE INDEX IF NOT EXISTS idx_sym_name ON symbols(name)',
            'CREATE INDEX IF NOT EXISTS idx_sym_type ON symbols(symbol_type)',
            'CREATE INDEX IF NOT EXISTS idx_sym_version ON symbols(version_id)',
            'CREATE INDEX IF NOT EXISTS idx_sym_version_lang_type ON symbols(version_id, language, symbol_type)',
            'CREATE INDEX IF NOT EXISTS idx_sym_lookup ON symbols(version_id, fqn, symbol_type)',
            'CREATE INDEX IF NOT EXISTS idx_sym_version_fqn_type_hash ON symbols(version_id, fqn, symbol_type, signature_hash)',
            'CREATE INDEX IF NOT EXISTS idx_sym_hash ON symbols(signature_hash)',
            'CREATE INDEX IF NOT EXISTS idx_sym_deprecated ON symbols(is_deprecated) WHERE is_deprecated = 1',
            'CREATE INDEX IF NOT EXISTS idx_sym_file ON symbols(file_id)',
            'CREATE INDEX IF NOT EXISTS idx_sym_parent ON symbols(parent_symbol) WHERE parent_symbol IS NOT NULL',

            'CREATE TABLE IF NOT EXISTS changes (
                id              INTEGER PRIMARY KEY,
                from_version_id INTEGER NOT NULL REFERENCES versions(id),
                to_version_id   INTEGER NOT NULL REFERENCES versions(id),
                language        TEXT NOT NULL,
                change_type     TEXT NOT NULL,
                severity        TEXT DEFAULT \'deprecation\',
                old_symbol_id   INTEGER REFERENCES symbols(id),
                new_symbol_id   INTEGER REFERENCES symbols(id),
                old_fqn         TEXT,
                new_fqn         TEXT,
                diff_json       TEXT,
                ts_query        TEXT,
                fix_template    TEXT,
                migration_hint  TEXT,
                confidence      REAL DEFAULT 1.0,
                created_at      TEXT DEFAULT (datetime(\'now\'))
            )',

            'CREATE INDEX IF NOT EXISTS idx_chg_versions ON changes(from_version_id, to_version_id)',
            'CREATE INDEX IF NOT EXISTS idx_chg_type ON changes(change_type)',
            'CREATE INDEX IF NOT EXISTS idx_chg_old_fqn ON changes(old_fqn)',
            'CREATE INDEX IF NOT EXISTS idx_chg_severity ON changes(severity)',

            'CREATE TABLE IF NOT EXISTS ast_snapshots (
                id            INTEGER PRIMARY KEY,
                symbol_id     INTEGER NOT NULL REFERENCES symbols(id) ON DELETE CASCADE,
                format        TEXT NOT NULL,
                content       BLOB NOT NULL,
                context_lines INTEGER DEFAULT 0,
                byte_range    TEXT
            )',

            'CREATE TABLE IF NOT EXISTS projects (
                id             INTEGER PRIMARY KEY,
                name           TEXT NOT NULL,
                path           TEXT NOT NULL,
                type           TEXT,
                source_type    TEXT NOT NULL DEFAULT \'local_path\',
                remote_url     TEXT,
                default_branch TEXT,
                core_version   TEXT,
                last_scanned   TEXT
            )',

            'CREATE TABLE IF NOT EXISTS project_branches (
                id             INTEGER PRIMARY KEY,
                project_id     INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                branch_name    TEXT NOT NULL,
                is_default     INTEGER NOT NULL DEFAULT 0,
                last_commit_sha TEXT,
                last_scanned_at TEXT,
                created_at     TEXT DEFAULT (datetime(\'now\')),
                updated_at     TEXT DEFAULT (datetime(\'now\')),
                UNIQUE(project_id, branch_name)
            )',
            'CREATE INDEX IF NOT EXISTS idx_project_branch_project ON project_branches(project_id)',
            'CREATE INDEX IF NOT EXISTS idx_project_branch_default ON project_branches(project_id, is_default)',

            'CREATE TABLE IF NOT EXISTS jobs (
                id               INTEGER PRIMARY KEY,
                kind             TEXT NOT NULL,
                status           TEXT NOT NULL DEFAULT \'queued\',
                payload_json     TEXT NOT NULL,
                attempts         INTEGER NOT NULL DEFAULT 0,
                max_attempts     INTEGER NOT NULL DEFAULT 1,
                reserved_at      TEXT,
                started_at       TEXT,
                finished_at      TEXT,
                progress_current INTEGER NOT NULL DEFAULT 0,
                progress_total   INTEGER NOT NULL DEFAULT 0,
                progress_label   TEXT,
                error_message    TEXT,
                created_at       TEXT DEFAULT (datetime(\'now\')),
                updated_at       TEXT DEFAULT (datetime(\'now\'))
            )',
            'CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status, id)',
            'CREATE INDEX IF NOT EXISTS idx_jobs_kind ON jobs(kind, status)',

            'CREATE TABLE IF NOT EXISTS scan_runs (
                id                  INTEGER PRIMARY KEY,
                project_id          INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                branch_name         TEXT NOT NULL,
                commit_sha          TEXT,
                source_path         TEXT,
                from_core_version   TEXT,
                target_core_version TEXT NOT NULL,
                status              TEXT NOT NULL DEFAULT \'queued\',
                job_id              INTEGER REFERENCES jobs(id) ON DELETE SET NULL,
                file_count          INTEGER NOT NULL DEFAULT 0,
                scanned_file_count  INTEGER NOT NULL DEFAULT 0,
                match_count         INTEGER NOT NULL DEFAULT 0,
                auto_fixable_count  INTEGER NOT NULL DEFAULT 0,
                summary_json        TEXT,
                error_message       TEXT,
                created_at          TEXT DEFAULT (datetime(\'now\')),
                started_at          TEXT,
                finished_at         TEXT
            )',
            'CREATE INDEX IF NOT EXISTS idx_scan_runs_project ON scan_runs(project_id, created_at DESC)',
            'CREATE INDEX IF NOT EXISTS idx_scan_runs_job ON scan_runs(job_id)',
            'CREATE INDEX IF NOT EXISTS idx_scan_runs_status ON scan_runs(status)',

            'CREATE TABLE IF NOT EXISTS job_logs (
                id         INTEGER PRIMARY KEY,
                job_id      INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
                seq         INTEGER NOT NULL,
                level       TEXT NOT NULL,
                message     TEXT NOT NULL,
                created_at  TEXT DEFAULT (datetime(\'now\')),
                UNIQUE(job_id, seq)
            )',
            'CREATE INDEX IF NOT EXISTS idx_job_logs_job ON job_logs(job_id, seq)',

            $this->codeMatchesTableSql(),

            'CREATE TABLE IF NOT EXISTS schema_meta (
                key   TEXT PRIMARY KEY,
                value TEXT
            )',
        ];
    }

    private function codeMatchesTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS code_matches (
            id             INTEGER PRIMARY KEY,
            project_id     INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            scan_run_id    INTEGER REFERENCES scan_runs(id) ON DELETE CASCADE,
            scope_key      TEXT NOT NULL,
            change_id      INTEGER NOT NULL REFERENCES changes(id) ON DELETE CASCADE,
            file_path      TEXT NOT NULL,
            line_start     INTEGER,
            line_end       INTEGER,
            byte_start     INTEGER NOT NULL DEFAULT -1,
            byte_end       INTEGER NOT NULL DEFAULT -1,
            matched_source TEXT,
            suggested_fix  TEXT,
            fix_method     TEXT,
            status         TEXT DEFAULT \'pending\',
            applied_at     TEXT,
            UNIQUE(scope_key, change_id, file_path, byte_start, byte_end)
        )';
    }

    private function ensureColumnExists(string $table, string $column, string $type): void
    {
        $columns = $this->db->query("PRAGMA table_info({$table})")->fetchAll();
        foreach ($columns as $meta) {
            if (($meta['name'] ?? null) === $column) {
                return;
            }
        }

        $this->db->pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
    }

    private function migrateCodeMatchesTable(): void
    {
        $columns = $this->db->query("PRAGMA table_info(code_matches)")->fetchAll();
        if ($columns === []) {
            return;
        }

        $names = array_column($columns, 'name');
        $needsRebuild = !in_array('scan_run_id', $names, true) || !in_array('scope_key', $names, true);

        if (!$needsRebuild) {
            $this->ensureColumnExists('code_matches', 'byte_start', 'INTEGER NOT NULL DEFAULT -1');
            $this->ensureColumnExists('code_matches', 'byte_end', 'INTEGER NOT NULL DEFAULT -1');
            return;
        }

        $this->db->pdo()->exec('ALTER TABLE code_matches RENAME TO code_matches_legacy');
        $this->db->pdo()->exec($this->codeMatchesTableSql());

        $legacyColumns = array_column($this->db->query("PRAGMA table_info(code_matches_legacy)")->fetchAll(), 'name');
        $hasAppliedAt = in_array('applied_at', $legacyColumns, true);

        $this->db->pdo()->exec(
            'INSERT INTO code_matches (
                id, project_id, scan_run_id, scope_key, change_id, file_path,
                line_start, line_end, byte_start, byte_end, matched_source,
                suggested_fix, fix_method, status, applied_at
            )
            SELECT legacy.id,
                   legacy.project_id,
                   NULL,
                   \'project:\' || legacy.project_id,
                   legacy.change_id,
                   legacy.file_path,
                   legacy.line_start,
                   legacy.line_end,
                   COALESCE(legacy.byte_start, -1),
                   COALESCE(legacy.byte_end, -1),
                   legacy.matched_source,
                   legacy.suggested_fix,
                   legacy.fix_method,
                   COALESCE(legacy.status, \'pending\'),
                   ' . ($hasAppliedAt ? 'legacy.applied_at' : 'NULL') . '
            FROM code_matches_legacy legacy
            WHERE legacy.id IN (
                SELECT MAX(id)
                FROM code_matches_legacy
                GROUP BY project_id, change_id, file_path, COALESCE(byte_start, -1), COALESCE(byte_end, -1)
            )'
        );

        $this->db->pdo()->exec('DROP TABLE code_matches_legacy');
    }

    private function deduplicateProjectsByPath(): void
    {
        $duplicates = $this->db->query(
            'SELECT path, MAX(id) AS keep_id
             FROM projects
             GROUP BY path
             HAVING COUNT(*) > 1'
        )->fetchAll();

        foreach ($duplicates as $row) {
            $path = (string) ($row['path'] ?? '');
            $keepId = (int) ($row['keep_id'] ?? 0);
            if ($path === '' || $keepId <= 0) {
                continue;
            }

            $obsoleteProjects = $this->db->query(
                'SELECT id FROM projects WHERE path = :path AND id != :keep_id ORDER BY id',
                ['path' => $path, 'keep_id' => $keepId]
            )->fetchAll();

            foreach ($obsoleteProjects as $obsoleteProject) {
                $oldId = (int) ($obsoleteProject['id'] ?? 0);
                if ($oldId <= 0) {
                    continue;
                }

                (void) $this->db->execute(
                    'DELETE FROM code_matches
                     WHERE project_id = :old_id
                       AND EXISTS (
                           SELECT 1
                           FROM code_matches keep
                           WHERE keep.project_id = :keep_id
                             AND COALESCE(keep.scan_run_id, 0) = COALESCE(code_matches.scan_run_id, 0)
                             AND keep.change_id = code_matches.change_id
                             AND keep.file_path = code_matches.file_path
                             AND keep.byte_start = code_matches.byte_start
                             AND keep.byte_end = code_matches.byte_end
                       )',
                    ['old_id' => $oldId, 'keep_id' => $keepId]
                );
                (void) $this->db->execute(
                    'UPDATE code_matches SET project_id = :keep_id WHERE project_id = :old_id',
                    ['keep_id' => $keepId, 'old_id' => $oldId]
                );
                (void) $this->db->execute(
                    "UPDATE code_matches
                     SET scope_key = CASE
                         WHEN scan_run_id IS NOT NULL THEN 'run:' || scan_run_id
                         ELSE 'project:' || :keep_id
                     END
                     WHERE project_id = :keep_id AND scope_key = :old_scope_key",
                    ['keep_id' => $keepId, 'old_scope_key' => 'project:' . $oldId]
                );
                (void) $this->db->execute(
                    'UPDATE scan_runs SET project_id = :keep_id WHERE project_id = :old_id',
                    ['keep_id' => $keepId, 'old_id' => $oldId]
                );
                (void) $this->db->execute(
                    'UPDATE project_branches SET project_id = :keep_id WHERE project_id = :old_id',
                    ['keep_id' => $keepId, 'old_id' => $oldId]
                );
                (void) $this->db->execute('DELETE FROM projects WHERE id = :old_id', ['old_id' => $oldId]);
            }
        }
    }

    private function ensureMatchScopeKeys(): void
    {
        (void) $this->db->execute(
            "UPDATE code_matches
             SET scope_key = CASE
                 WHEN scan_run_id IS NOT NULL THEN 'run:' || scan_run_id
                 ELSE 'project:' || project_id
             END
             WHERE scope_key IS NULL OR scope_key = ''"
        );
        (void) $this->db->execute('UPDATE code_matches SET byte_start = -1 WHERE byte_start IS NULL');
        (void) $this->db->execute('UPDATE code_matches SET byte_end = -1 WHERE byte_end IS NULL');
    }

    private function deduplicateCodeMatchesByScope(): void
    {
        (void) $this->db->execute(
            'DELETE FROM code_matches
             WHERE id NOT IN (
                 SELECT MAX(id)
                 FROM code_matches
                 GROUP BY scope_key, change_id, file_path, byte_start, byte_end
             )'
        );
    }

    private function ensureCodeMatchIndexes(): void
    {
        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_match_project ON code_matches(project_id)',
            'CREATE INDEX IF NOT EXISTS idx_match_run ON code_matches(scan_run_id)',
            'CREATE INDEX IF NOT EXISTS idx_match_status ON code_matches(status)',
            'CREATE INDEX IF NOT EXISTS idx_match_change ON code_matches(change_id)',
            'CREATE INDEX IF NOT EXISTS idx_match_scope ON code_matches(scope_key)',
        ] as $sql) {
            $this->db->pdo()->exec($sql);
        }
    }
}
