<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Storage;

use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\Repository\ChangeRepo;
use DrupalEvolver\Storage\Repository\FileRepo;
use DrupalEvolver\Storage\Repository\MatchRepo;
use DrupalEvolver\Storage\Repository\ProjectRepo;
use DrupalEvolver\Storage\Repository\SymbolRepo;
use DrupalEvolver\Storage\Repository\VersionRepo;
use DrupalEvolver\Storage\Schema;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        (new Schema($this->db))->createAll();
    }

    public function testVersionRepo(): void
    {
        $repo = new VersionRepo($this->db);

        $id = $repo->save('10.3.0', 10, 3, 0);
        $this->assertSame(1, $id);

        $sameId = $repo->save('10.3.0', 10, 3, 0);
        $this->assertSame($id, $sameId);

        $version = $repo->findByTag('10.3.0');
        $this->assertNotNull($version);
        $this->assertSame('10.3.0', $version['tag']);
        $this->assertSame(10, (int) $version['major']);
        $this->assertSame(10003000, (int) $version['weight']);

        $this->assertSame(1, $repo->updateCounts($id, 100, 500));
        $updated = $repo->findByTag('10.3.0');
        $this->assertSame(100, (int) $updated['file_count']);
        $this->assertSame(500, (int) $updated['symbol_count']);

        $all = $repo->all();
        $this->assertCount(1, $all);
    }

    public function testFileRepo(): void
    {
        $versionRepo = new VersionRepo($this->db);
        $versionId = $versionRepo->save('10.3.0', 10, 3, 0);

        $fileRepo = new FileRepo($this->db);
        $fileId = $fileRepo->save($versionId, 'core/lib/Drupal.php', 'php', 'abc123', null, null, 100, 5000);
        $this->assertSame(1, $fileId);

        $sameFileId = $fileRepo->save($versionId, 'core/lib/Drupal.php', 'php', 'def456', 'sexp', '{"type":"file"}', 110, 5500);
        $this->assertSame($fileId, $sameFileId);

        $byHash = $fileRepo->findByHash($versionId, 'abc123');
        $this->assertNull($byHash);

        $byPath = $fileRepo->findByPath($versionId, 'core/lib/Drupal.php');
        $this->assertNotNull($byPath);
        $this->assertSame('def456', $byPath['file_hash']);
        $this->assertSame(110, (int) $byPath['line_count']);
        $this->assertSame(5500, (int) $byPath['byte_size']);
    }

    public function testSymbolRepo(): void
    {
        $versionRepo = new VersionRepo($this->db);
        $versionId = $versionRepo->create('10.3.0', 10, 3, 0);

        $fileRepo = new FileRepo($this->db);
        $fileId = $fileRepo->create($versionId, 'test.php', 'php', 'hash1', null, null, 10, 100);

        $symbolRepo = new SymbolRepo($this->db);
        $symId = $symbolRepo->create([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'php',
            'symbol_type' => 'function',
            'fqn' => 'drupal_render',
            'name' => 'drupal_render',
            'signature_hash' => 'sig_hash_1',
            'is_deprecated' => 1,
            'deprecation_version' => '10.0.0',
            'removal_version' => '11.0.0',
        ]);
        $this->assertSame(1, $symId);

        $byFqn = $symbolRepo->findByFqn($versionId, 'drupal_render');
        $this->assertNotNull($byFqn);
        $this->assertSame('function', $byFqn['symbol_type']);

        $deprecated = $symbolRepo->findDeprecated($versionId);
        $this->assertCount(1, $deprecated);

        $count = $symbolRepo->countByVersion($versionId);
        $this->assertSame(1, $count);
    }

    public function testChangeRepo(): void
    {
        $versionRepo = new VersionRepo($this->db);
        $v102 = $versionRepo->create('10.2.0', 10, 2, 0);
        $v103 = $versionRepo->create('10.3.0', 10, 3, 0);
        $v104 = $versionRepo->create('10.4.0', 10, 4, 0);
        $v110 = $versionRepo->create('11.0.0', 11, 0, 0);

        $changeRepo = new ChangeRepo($this->db);
        $changeId = $changeRepo->create([
            'from_version_id' => $v102,
            'to_version_id' => $v103,
            'language' => 'php',
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'drupal_render',
        ]);
        $this->assertSame(1, $changeId);

        $secondChangeId = $changeRepo->create([
            'from_version_id' => $v103,
            'to_version_id' => $v104,
            'language' => 'php',
            'change_type' => 'method_removed',
            'severity' => 'breaking',
            'old_fqn' => 'Drupal\\Foo::bar',
        ]);
        $this->assertGreaterThan(0, $secondChangeId);

        // Outside the selected upgrade path.
        $thirdChangeId = $changeRepo->create([
            'from_version_id' => $v104,
            'to_version_id' => $v110,
            'language' => 'php',
            'change_type' => 'class_removed',
            'severity' => 'breaking',
            'old_fqn' => 'Drupal\\Deprecated\\Legacy',
        ]);
        $this->assertGreaterThan(0, $thirdChangeId);

        $changes = $changeRepo->findByVersions($v102, $v103);
        $this->assertCount(1, $changes);

        $byType = $changeRepo->findByType('function_removed');
        $this->assertCount(1, $byType);

        $count = $changeRepo->countByVersions($v102, $v103);
        $this->assertSame(1, $count);

        $pathChanges = $changeRepo->findForUpgradePath($v102, $v104);
        $this->assertCount(2, $pathChanges);
        $this->assertSame('function_removed', $pathChanges[0]['change_type']);
        $this->assertSame('method_removed', $pathChanges[1]['change_type']);
    }

    public function testProjectAndMatchRepo(): void
    {
        $projectRepo = new ProjectRepo($this->db);
        $projectId = $projectRepo->save('mymodule', '/var/www/mymodule', 'module', '10.2.0');
        $this->assertSame(1, $projectId);

        $sameProjectId = $projectRepo->save('mymodule', '/var/www/mymodule', 'module', '10.3.0');
        $this->assertSame($projectId, $sameProjectId);

        $project = $projectRepo->findByName('mymodule');
        $this->assertNotNull($project);
        $this->assertSame('10.3.0', $project['core_version']);

        $byPath = $projectRepo->findByPath('/var/www/mymodule');
        $this->assertNotNull($byPath);

        // Create a change for the match
        $versionRepo = new VersionRepo($this->db);
        $fromId = $versionRepo->create('10.2.0', 10, 2, 0);
        $toId = $versionRepo->create('10.3.0', 10, 3, 0);
        $changeRepo = new ChangeRepo($this->db);
        $changeId = $changeRepo->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'test_func',
        ]);

        $matchRepo = new MatchRepo($this->db);
        $matchId = $matchRepo->save([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'src/MyService.php',
            'line_start' => 45,
            'line_end' => 45,
            'byte_start' => 512,
            'byte_end' => 523,
            'matched_source' => 'test_func()',
            'fix_method' => 'template',
        ]);
        $this->assertSame(1, $matchId);

        $sameMatchId = $matchRepo->save([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'src/MyService.php',
            'line_start' => 46,
            'line_end' => 46,
            'byte_start' => 512,
            'byte_end' => 523,
            'matched_source' => 'updated_test_func()',
            'suggested_fix' => 'replacement()',
            'fix_method' => 'manual',
            'status' => 'applied',
        ]);
        $this->assertSame($matchId, $sameMatchId);

        $allMatches = $matchRepo->findByProject($projectId);
        $this->assertCount(1, $allMatches);
        $this->assertSame(46, (int) $allMatches[0]['line_start']);
        $this->assertSame('updated_test_func()', $allMatches[0]['matched_source']);
        $this->assertSame('replacement()', $allMatches[0]['suggested_fix']);
        $this->assertSame('applied', $allMatches[0]['status']);

        $pending = $matchRepo->findPending($projectId);
        $this->assertCount(0, $pending);

        $matchWithoutOffsetsId = $matchRepo->save([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'src/Legacy.php',
            'line_start' => 10,
            'matched_source' => 'legacy_call()',
            'fix_method' => 'manual',
        ]);
        $sameMatchWithoutOffsetsId = $matchRepo->save([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'src/Legacy.php',
            'line_start' => 11,
            'matched_source' => 'legacy_call_updated()',
            'fix_method' => 'template',
            'status' => 'applied',
        ]);
        $this->assertSame($matchWithoutOffsetsId, $sameMatchWithoutOffsetsId);

        $legacyMatches = array_values(array_filter(
            $matchRepo->findByProject($projectId),
            static fn(array $row): bool => $row['file_path'] === 'src/Legacy.php'
        ));
        $this->assertCount(1, $legacyMatches);
        $this->assertSame(-1, (int) $legacyMatches[0]['byte_start']);
        $this->assertSame(-1, (int) $legacyMatches[0]['byte_end']);
        $this->assertSame('legacy_call_updated()', $legacyMatches[0]['matched_source']);

        $this->assertSame(1, $matchRepo->updateStatus($matchId, 'pending'));
        $pending = $matchRepo->findPending($projectId);
        $this->assertCount(1, array_values(array_filter(
            $pending,
            static fn(array $row): bool => (int) $row['id'] === $matchId
        )));

        $deleted = $matchRepo->deleteByProject($projectId);
        $this->assertSame(2, $deleted);
        $this->assertCount(0, $matchRepo->findByProject($projectId));
    }

    public function testMatchRepoCreateBatch(): void
    {
        $projectRepo = new ProjectRepo($this->db);
        $projectId = $projectRepo->create('batch-module', '/var/www/batch-module', 'module', '10.2.0');

        $versionRepo = new VersionRepo($this->db);
        $fromId = $versionRepo->create('10.2.0', 10, 2, 0);
        $toId = $versionRepo->create('10.3.0', 10, 3, 0);

        $changeRepo = new ChangeRepo($this->db);
        $changeId = $changeRepo->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'legacy_call',
        ]);

        $matchRepo = new MatchRepo($this->db);
        $created = $matchRepo->saveBatch([
            [
                'project_id' => $projectId,
                'change_id' => $changeId,
                'file_path' => 'src/A.php',
                'line_start' => 10,
                'line_end' => 10,
                'byte_start' => 100,
                'byte_end' => 112,
                'matched_source' => 'legacy_call()',
                'suggested_fix' => null,
                'fix_method' => 'template',
                'status' => 'pending',
            ],
            [
                'project_id' => $projectId,
                'change_id' => $changeId,
                'file_path' => 'src/B.php',
                'line_start' => 20,
                'line_end' => 20,
                'byte_start' => 200,
                'byte_end' => 212,
                'matched_source' => 'legacy_call()',
                'suggested_fix' => null,
                'fix_method' => 'manual',
                'status' => 'pending',
            ],
        ]);

        $this->assertSame(2, $created);
        $this->assertCount(2, $matchRepo->findPending($projectId));

        $rewritten = $matchRepo->saveBatch([
            [
                'project_id' => $projectId,
                'change_id' => $changeId,
                'file_path' => 'src/A.php',
                'line_start' => 11,
                'line_end' => 11,
                'byte_start' => 100,
                'byte_end' => 112,
                'matched_source' => 'legacy_call_updated()',
                'suggested_fix' => 'modern_call()',
                'fix_method' => 'template',
                'status' => 'applied',
            ],
        ]);

        $this->assertSame(1, $rewritten);
        $rows = $matchRepo->findByProject($projectId);
        $this->assertCount(2, $rows);
        $aRow = array_values(array_filter($rows, static fn(array $row): bool => $row['file_path'] === 'src/A.php'))[0];
        $this->assertSame(11, (int) $aRow['line_start']);
        $this->assertSame('legacy_call_updated()', $aRow['matched_source']);
        $this->assertSame('applied', $aRow['status']);
    }

    public function testChangeRepoCreateBatch(): void
    {
        $versionRepo = new VersionRepo($this->db);
        $fromId = $versionRepo->save('10.2.0', 10, 2, 0);
        $toId = $versionRepo->save('10.3.0', 10, 3, 0);

        $changeRepo = new ChangeRepo($this->db);
        $created = $changeRepo->createBatch([
            [
                'from_version_id' => $fromId,
                'to_version_id' => $toId,
                'language' => 'php',
                'change_type' => 'function_removed',
                'old_fqn' => 'legacy_a',
            ],
            [
                'from_version_id' => $fromId,
                'to_version_id' => $toId,
                'language' => 'php',
                'change_type' => 'function_removed',
                'old_fqn' => 'legacy_b',
                'severity' => 'breaking',
            ],
        ]);

        $this->assertSame(2, $created);
        $this->assertSame(2, $changeRepo->countByVersions($fromId, $toId));
    }
}
