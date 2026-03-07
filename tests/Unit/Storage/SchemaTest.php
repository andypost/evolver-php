<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Storage;

use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\Schema;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    public function testCreateAllTables(): void
    {
        $db = new Database(':memory:');
        $schema = new Schema($db);
        $schema->createAll();

        // Verify all tables exist
        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $expected = [
            'ast_snapshots', 'changes', 'code_matches', 'job_logs',
            'jobs', 'parsed_files', 'project_branches', 'projects',
            'scan_runs', 'schema_meta', 'symbols', 'versions',
        ];

        $this->assertSame($expected, $tables);
    }

    public function testSchemaVersion(): void
    {
        $db = new Database(':memory:');
        (new Schema($db))->createAll();

        $version = $db->query("SELECT value FROM schema_meta WHERE key = 'schema_version'")->fetch();
        $this->assertSame('3', $version['value']);
    }

    public function testIdempotent(): void
    {
        $db = new Database(':memory:');
        $schema = new Schema($db);
        $schema->createAll();
        $schema->createAll(); // Should not throw
        $this->assertTrue(true);
    }

    public function testCodeMatchesHasByteOffsetColumns(): void
    {
        $db = new Database(':memory:');
        (new Schema($db))->createAll();

        $columns = $db->query("PRAGMA table_info(code_matches)")->fetchAll();
        $names = array_column($columns, 'name');
        $byteStartMeta = array_values(array_filter($columns, static fn(array $column): bool => $column['name'] === 'byte_start'))[0];
        $byteEndMeta = array_values(array_filter($columns, static fn(array $column): bool => $column['name'] === 'byte_end'))[0];

        $this->assertContains('byte_start', $names);
        $this->assertContains('byte_end', $names);
        $this->assertSame(1, (int) $byteStartMeta['notnull']);
        $this->assertSame('-1', (string) $byteStartMeta['dflt_value']);
        $this->assertSame(1, (int) $byteEndMeta['notnull']);
        $this->assertSame('-1', (string) $byteEndMeta['dflt_value']);
    }

    public function testCodeMatchesHasRunScopedColumns(): void
    {
        $db = new Database(':memory:');
        (new Schema($db))->createAll();

        $columns = $db->query("PRAGMA table_info(code_matches)")->fetchAll();
        $names = array_column($columns, 'name');

        $this->assertContains('scan_run_id', $names);
        $this->assertContains('scope_key', $names);
    }

    public function testVersionsTableHasWeightColumn(): void
    {
        $db = new Database(':memory:');
        (new Schema($db))->createAll();

        $columns = $db->query("PRAGMA table_info(versions)")->fetchAll();
        $names = array_column($columns, 'name');

        $this->assertContains('weight', $names);
    }

    public function testCreateAllMigratesLegacyVersionWeights(): void
    {
        $db = new Database(':memory:');
        $db->pdo()->exec(
            'CREATE TABLE versions (
                id INTEGER PRIMARY KEY,
                tag TEXT NOT NULL UNIQUE,
                major INTEGER NOT NULL,
                minor INTEGER NOT NULL,
                patch INTEGER NOT NULL,
                file_count INTEGER DEFAULT 0,
                symbol_count INTEGER DEFAULT 0,
                indexed_at TEXT
            )'
        );
        $affected = $db->execute(
            "INSERT INTO versions (tag, major, minor, patch, indexed_at) VALUES ('10.3.0', 10, 3, 0, datetime('now'))"
        );
        $this->assertSame(1, $affected);

        (new Schema($db))->createAll();

        $row = $db->query("SELECT weight FROM versions WHERE tag = '10.3.0'")->fetch();
        $this->assertSame(10003000, (int) $row['weight']);
    }

    public function testCreateAllMigratesLegacyNullMatchOffsetsAndDeduplicates(): void
    {
        $db = new Database(':memory:');
        $db->pdo()->exec(
            'CREATE TABLE projects (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                path TEXT NOT NULL,
                type TEXT,
                core_version TEXT,
                last_scanned TEXT
            )'
        );
        $db->pdo()->exec(
            'CREATE TABLE changes (
                id INTEGER PRIMARY KEY,
                from_version_id INTEGER,
                to_version_id INTEGER,
                language TEXT,
                change_type TEXT,
                severity TEXT,
                old_symbol_id INTEGER,
                new_symbol_id INTEGER,
                old_fqn TEXT,
                new_fqn TEXT,
                diff_json TEXT,
                ts_query TEXT,
                fix_template TEXT,
                migration_hint TEXT,
                confidence REAL,
                created_at TEXT
            )'
        );
        $db->pdo()->exec(
            'CREATE TABLE code_matches (
                id INTEGER PRIMARY KEY,
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                change_id INTEGER NOT NULL REFERENCES changes(id) ON DELETE CASCADE,
                file_path TEXT NOT NULL,
                line_start INTEGER,
                line_end INTEGER,
                byte_start INTEGER,
                byte_end INTEGER,
                matched_source TEXT,
                suggested_fix TEXT,
                fix_method TEXT,
                status TEXT DEFAULT \'pending\',
                applied_at TEXT,
                UNIQUE(project_id, change_id, file_path, byte_start, byte_end)
            )'
        );

        $this->assertSame(1, $db->execute("INSERT INTO projects (id, name, path) VALUES (1, 'demo', '/tmp/demo')"));
        $this->assertSame(1, $db->execute("INSERT INTO changes (id) VALUES (1)"));
        $this->assertSame(1, $db->execute(
            "INSERT INTO code_matches (id, project_id, change_id, file_path, matched_source) VALUES (1, 1, 1, 'test.php', 'first')"
        ));
        $this->assertSame(1, $db->execute(
            "INSERT INTO code_matches (id, project_id, change_id, file_path, matched_source) VALUES (2, 1, 1, 'test.php', 'second')"
        ));

        (new Schema($db))->createAll();

        $rows = $db->query('SELECT id, byte_start, byte_end, matched_source FROM code_matches')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['id']);
        $this->assertSame(-1, (int) $rows[0]['byte_start']);
        $this->assertSame(-1, (int) $rows[0]['byte_end']);
        $this->assertSame('second', $rows[0]['matched_source']);
    }

    public function testCreateAllDeduplicatesProjectsByPathAndAddsUniqueIndex(): void
    {
        $db = new Database(':memory:');
        $db->pdo()->exec(
            'CREATE TABLE projects (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                path TEXT NOT NULL,
                type TEXT,
                core_version TEXT,
                last_scanned TEXT
            )'
        );
        $db->pdo()->exec(
            'CREATE TABLE changes (
                id INTEGER PRIMARY KEY,
                from_version_id INTEGER,
                to_version_id INTEGER,
                language TEXT,
                change_type TEXT,
                severity TEXT,
                old_symbol_id INTEGER,
                new_symbol_id INTEGER,
                old_fqn TEXT,
                new_fqn TEXT,
                diff_json TEXT,
                ts_query TEXT,
                fix_template TEXT,
                migration_hint TEXT,
                confidence REAL,
                created_at TEXT
            )'
        );
        $db->pdo()->exec(
            'CREATE TABLE code_matches (
                id INTEGER PRIMARY KEY,
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                change_id INTEGER NOT NULL REFERENCES changes(id) ON DELETE CASCADE,
                file_path TEXT NOT NULL,
                line_start INTEGER,
                line_end INTEGER,
                byte_start INTEGER,
                byte_end INTEGER,
                matched_source TEXT,
                suggested_fix TEXT,
                fix_method TEXT,
                status TEXT DEFAULT \'pending\',
                applied_at TEXT,
                UNIQUE(project_id, change_id, file_path, byte_start, byte_end)
            )'
        );

        $this->assertSame(1, $db->execute("INSERT INTO projects (id, name, path, core_version) VALUES (1, 'demo-old', '/tmp/demo', '10.2.0')"));
        $this->assertSame(1, $db->execute("INSERT INTO projects (id, name, path, core_version) VALUES (2, 'demo-new', '/tmp/demo', '10.3.0')"));
        $this->assertSame(1, $db->execute("INSERT INTO changes (id) VALUES (1)"));
        $this->assertSame(1, $db->execute(
            "INSERT INTO code_matches (id, project_id, change_id, file_path, matched_source, byte_start, byte_end) VALUES (1, 1, 1, 'test.php', 'legacy', 10, 20)"
        ));
        $this->assertSame(1, $db->execute(
            "INSERT INTO code_matches (id, project_id, change_id, file_path, matched_source, byte_start, byte_end) VALUES (2, 2, 1, 'test.php', 'current', 10, 20)"
        ));

        (new Schema($db))->createAll();

        $projects = $db->query('SELECT id, name, path, core_version FROM projects')->fetchAll();
        $this->assertCount(1, $projects);
        $this->assertSame(2, (int) $projects[0]['id']);
        $this->assertSame('/tmp/demo', $projects[0]['path']);

        $matchRows = $db->query('SELECT project_id, matched_source FROM code_matches')->fetchAll();
        $this->assertCount(1, $matchRows);
        $this->assertSame(2, (int) $matchRows[0]['project_id']);
        $this->assertSame('current', $matchRows[0]['matched_source']);

        $indexes = $db->query("PRAGMA index_list(projects)")->fetchAll();
        $projectPathIndex = array_values(array_filter($indexes, static fn(array $index): bool => $index['name'] === 'idx_project_path'));
        $this->assertCount(1, $projectPathIndex);
        $this->assertSame(1, (int) $projectPathIndex[0]['unique']);
    }
}
