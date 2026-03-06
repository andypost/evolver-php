<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage;

class Schema
{
    public function __construct(private Database $db) {}

    public function createAll(): void
    {
        $statements = [
            'CREATE TABLE IF NOT EXISTS versions (
                id          INTEGER PRIMARY KEY,
                tag         TEXT NOT NULL UNIQUE,
                major       INTEGER NOT NULL,
                minor       INTEGER NOT NULL,
                patch       INTEGER NOT NULL,
                weight      INTEGER NOT NULL,
                file_count  INTEGER DEFAULT 0,
                symbol_count INTEGER DEFAULT 0,
                indexed_at  TEXT
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
            'CREATE INDEX IF NOT EXISTS idx_sym_lookup ON symbols(version_id, fqn, symbol_type)',
            'CREATE INDEX IF NOT EXISTS idx_sym_version_fqn_type_hash ON symbols(version_id, fqn, symbol_type, signature_hash)',
            'CREATE INDEX IF NOT EXISTS idx_sym_hash ON symbols(signature_hash)',
            'CREATE INDEX IF NOT EXISTS idx_sym_deprecated ON symbols(is_deprecated) WHERE is_deprecated = 1',
            'CREATE INDEX IF NOT EXISTS idx_sym_file ON symbols(file_id)',
            'CREATE INDEX IF NOT EXISTS idx_sym_parent ON symbols(parent_symbol) WHERE parent_symbol IS NOT NULL',

            'CREATE TABLE IF NOT EXISTS changes (
                id                  INTEGER PRIMARY KEY,
                from_version_id     INTEGER NOT NULL REFERENCES versions(id),
                to_version_id       INTEGER NOT NULL REFERENCES versions(id),
                language            TEXT NOT NULL,
                change_type         TEXT NOT NULL,
                severity            TEXT DEFAULT \'deprecation\',
                old_symbol_id       INTEGER REFERENCES symbols(id),
                new_symbol_id       INTEGER REFERENCES symbols(id),
                old_fqn             TEXT,
                new_fqn             TEXT,
                diff_json           TEXT,
                ts_query            TEXT,
                fix_template        TEXT,
                migration_hint      TEXT,
                confidence          REAL DEFAULT 1.0,
                created_at          TEXT DEFAULT (datetime(\'now\'))
            )',

            'CREATE INDEX IF NOT EXISTS idx_chg_versions ON changes(from_version_id, to_version_id)',
            'CREATE INDEX IF NOT EXISTS idx_chg_type ON changes(change_type)',
            'CREATE INDEX IF NOT EXISTS idx_chg_old_fqn ON changes(old_fqn)',
            'CREATE INDEX IF NOT EXISTS idx_chg_severity ON changes(severity)',

            'CREATE TABLE IF NOT EXISTS ast_snapshots (
                id              INTEGER PRIMARY KEY,
                symbol_id       INTEGER NOT NULL REFERENCES symbols(id) ON DELETE CASCADE,
                format          TEXT NOT NULL,
                content         BLOB NOT NULL,
                context_lines   INTEGER DEFAULT 0,
                byte_range      TEXT
            )',

            'CREATE TABLE IF NOT EXISTS projects (
                id              INTEGER PRIMARY KEY,
                name            TEXT NOT NULL,
                path            TEXT NOT NULL,
                type            TEXT,
                core_version    TEXT,
                last_scanned    TEXT
            )',

            'CREATE TABLE IF NOT EXISTS code_matches (
                id              INTEGER PRIMARY KEY,
                project_id      INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                change_id       INTEGER NOT NULL REFERENCES changes(id) ON DELETE CASCADE,
                file_path       TEXT NOT NULL,
                line_start      INTEGER,
                line_end        INTEGER,
                byte_start      INTEGER NOT NULL DEFAULT -1,
                byte_end        INTEGER NOT NULL DEFAULT -1,
                matched_source  TEXT,
                suggested_fix   TEXT,
                fix_method      TEXT,
                status          TEXT DEFAULT \'pending\',
                applied_at      TEXT,
                UNIQUE(project_id, change_id, file_path, byte_start, byte_end)
            )',

            'CREATE INDEX IF NOT EXISTS idx_match_project ON code_matches(project_id)',
            'CREATE INDEX IF NOT EXISTS idx_match_status ON code_matches(status)',
            'CREATE INDEX IF NOT EXISTS idx_match_change ON code_matches(change_id)',

            'CREATE TABLE IF NOT EXISTS schema_meta (
                key   TEXT PRIMARY KEY,
                value TEXT
            )',
        ];

        foreach ($statements as $sql) {
            $this->db->pdo()->exec($sql);
        }

        // Lightweight forward migration for existing databases.
        $this->ensureColumnExists('versions', 'weight', 'INTEGER');
        $this->ensureColumnExists('code_matches', 'byte_start', 'INTEGER DEFAULT -1');
        $this->ensureColumnExists('code_matches', 'byte_end', 'INTEGER DEFAULT -1');
        $weightsBackfilled = $this->db->execute(
            'UPDATE versions SET weight = (major * 1000000) + (minor * 1000) + patch WHERE weight IS NULL'
        );
        $this->deduplicateProjectsByPath();
        $this->deduplicateCodeMatchesByIdentity();
        $normalizedByteStarts = $this->db->execute('UPDATE code_matches SET byte_start = -1 WHERE byte_start IS NULL');
        $normalizedByteEnds = $this->db->execute('UPDATE code_matches SET byte_end = -1 WHERE byte_end IS NULL');
        $this->db->pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_project_path ON projects(path)');

        $schemaMetaWritten = $this->db->execute(
            "INSERT OR REPLACE INTO schema_meta (key, value) VALUES ('schema_version', '2')"
        );
        if ($schemaMetaWritten < 1 && ($weightsBackfilled + $normalizedByteStarts + $normalizedByteEnds) < 0) {
            throw new \LogicException('Schema metadata write failed.');
        }
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

                $deletedConflictingMatches = $this->db->execute(
                    'DELETE FROM code_matches
                     WHERE project_id = :old_id
                       AND EXISTS (
                           SELECT 1
                           FROM code_matches keep
                           WHERE keep.project_id = :keep_id
                             AND keep.change_id = code_matches.change_id
                             AND keep.file_path = code_matches.file_path
                             AND IFNULL(keep.byte_start, -1) = IFNULL(code_matches.byte_start, -1)
                             AND IFNULL(keep.byte_end, -1) = IFNULL(code_matches.byte_end, -1)
                    )',
                    ['old_id' => $oldId, 'keep_id' => $keepId]
                );
                $movedMatches = $this->db->execute(
                    'UPDATE code_matches SET project_id = :keep_id WHERE project_id = :old_id',
                    ['keep_id' => $keepId, 'old_id' => $oldId]
                );
                $deletedProjects = $this->db->execute('DELETE FROM projects WHERE id = :old_id', ['old_id' => $oldId]);
                if (($deletedConflictingMatches + $movedMatches + $deletedProjects) < 0) {
                    throw new \LogicException('Project deduplication failed.');
                }
            }
        }
    }

    private function deduplicateCodeMatchesByIdentity(): void
    {
        $removedDuplicates = $this->db->execute(
            'DELETE FROM code_matches
             WHERE id NOT IN (
                 SELECT MAX(id)
                 FROM code_matches
                 GROUP BY project_id, change_id, file_path, IFNULL(byte_start, -1), IFNULL(byte_end, -1)
             )'
        );
        if ($removedDuplicates < 0) {
            throw new \LogicException('Match deduplication failed.');
        }
    }
}
