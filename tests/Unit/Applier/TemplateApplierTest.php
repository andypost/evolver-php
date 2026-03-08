<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Applier;

use DrupalEvolver\Applier\TemplateApplier;
use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

class TemplateApplierTest extends TestCase
{
    private function createTestApi(): DatabaseApi
    {
        return new DatabaseApi(':memory:');
    }

    public function testApplyUsesByteOffsetsWhenAvailable(): void
    {
        $api = $this->createTestApi();

        $tmpDir = sys_get_temp_dir() . '/evolver-applier-' . uniqid('', true);
        mkdir($tmpDir, 0777, true);
        $filePath = $tmpDir . '/test.php';

        $source = "<?php\nfoo_old();\nfoo_old();\n";
        file_put_contents($filePath, $source);

        $first = strpos($source, 'foo_old()');
        $second = strpos($source, 'foo_old()', $first + 1);
        $this->assertNotFalse($first);
        $this->assertNotFalse($second);

        $fromId = $api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'foo_old',
            'fix_template' => json_encode([
                'type' => 'function_rename',
                'old' => 'foo_old',
                'new' => 'foo_new',
            ]),
        ]);

        $projectId = $api->projects()->create('demo', $tmpDir, 'module', '10.2.0');

        $matchId = $api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'test.php',
            'line_start' => 3,
            'byte_start' => $second,
            'byte_end' => $second + strlen('foo_old()'),
            'matched_source' => 'foo_old()',
            'fix_method' => 'template',
        ]);
        $this->assertGreaterThan(0, $matchId);

        $applier = new TemplateApplier($api);
        $applied = $applier->apply($projectId, $tmpDir, null, false, false);
        $this->assertSame(1, $applied);

        $updated = file_get_contents($filePath);
        $this->assertSame("<?php\nfoo_old();\nfoo_new();\n", $updated);

        $pending = $api->matches()->findPending($projectId);
        $this->assertCount(0, $pending);

        @unlink($filePath);
        @rmdir($tmpDir);
    }

    public function testDryRunDoesNotMutateStatusesAndReturnsStats(): void
    {
        $api = $this->createTestApi();

        $tmpDir = sys_get_temp_dir() . '/evolver-applier-dry-' . uniqid('', true);
        mkdir($tmpDir, 0777, true);
        $filePath = $tmpDir . '/test.php';
        file_put_contents($filePath, "<?php\nfoo_old();\n");

        $fromId = $api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'foo_old',
            'fix_template' => json_encode([
                'type' => 'function_rename',
                'old' => 'foo_old',
                'new' => 'foo_new',
            ]),
        ]);

        $projectId = $api->projects()->create('demo-dry', $tmpDir, 'module', '10.2.0');

        $matchId = $api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'test.php',
            'line_start' => 2,
            'byte_start' => strpos("<?php\nfoo_old();\n", 'foo_old()'),
            'byte_end' => strpos("<?php\nfoo_old();\n", 'foo_old()') + strlen('foo_old()'),
            'matched_source' => 'foo_old()',
            'fix_method' => 'template',
        ]);
        $this->assertGreaterThan(0, $matchId);

        $applier = new TemplateApplier($api);
        $stats = $applier->applyWithStats($projectId, $tmpDir, null, true, false);

        $this->assertSame(1, $stats['would_apply']);
        $this->assertSame(0, $stats['applied']);
        $this->assertSame(0, $stats['failed']);

        $pending = $api->matches()->findPending($projectId);
        $this->assertCount(1, $pending);
        $this->assertSame("<?php\nfoo_old();\n", file_get_contents($filePath));

        @unlink($filePath);
        @rmdir($tmpDir);
    }

    public function testOverlappingRangesMarkSecondMatchAsFailed(): void
    {
        $api = $this->createTestApi();

        $tmpDir = sys_get_temp_dir() . '/evolver-applier-overlap-' . uniqid('', true);
        mkdir($tmpDir, 0777, true);
        $filePath = $tmpDir . '/test.php';
        $source = "<?php\nfoo_old();\n";
        file_put_contents($filePath, $source);

        $fromId = $api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $api->versions()->create('10.3.0', 10, 3, 0);

        $renameChangeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'foo_old',
            'fix_template' => json_encode([
                'type' => 'function_rename',
                'old' => 'foo_old',
                'new' => 'foo_new',
            ]),
        ]);

        $stringChangeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'old',
            'fix_template' => json_encode([
                'type' => 'string_replace',
                'old' => 'old',
                'new' => 'new',
            ]),
        ]);

        $projectId = $api->projects()->create('demo-overlap', $tmpDir, 'module', '10.2.0');

        $fullStart = strpos($source, 'foo_old()');
        $fullEnd = $fullStart + strlen('foo_old()');
        $innerStart = strpos($source, 'old');
        $innerEnd = $innerStart + strlen('old');

        $renameMatchId = $api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $renameChangeId,
            'file_path' => 'test.php',
            'line_start' => 2,
            'byte_start' => $fullStart,
            'byte_end' => $fullEnd,
            'matched_source' => 'foo_old()',
            'fix_method' => 'template',
        ]);
        $this->assertGreaterThan(0, $renameMatchId);
        $stringMatchId = $api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $stringChangeId,
            'file_path' => 'test.php',
            'line_start' => 2,
            'byte_start' => $innerStart,
            'byte_end' => $innerEnd,
            'matched_source' => 'old',
            'fix_method' => 'template',
        ]);
        $this->assertGreaterThan(0, $stringMatchId);

        $applier = new TemplateApplier($api);
        $stats = $applier->applyWithStats($projectId, $tmpDir, null, false, false);

        $this->assertSame(1, $stats['applied']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['conflicts']);

        $counts = $api->matches()->countByProject($projectId);
        $indexed = [];
        foreach ($counts as $row) {
            $indexed[$row['status']] = (int) $row['cnt'];
        }
        $this->assertSame(1, $indexed['applied'] ?? 0);
        $this->assertSame(1, $indexed['failed'] ?? 0);

        @unlink($filePath);
        @rmdir($tmpDir);
    }

    public function testNoopTemplateMarksMatchSkipped(): void
    {
        $api = $this->createTestApi();

        $tmpDir = sys_get_temp_dir() . '/evolver-applier-noop-' . uniqid('', true);
        mkdir($tmpDir, 0777, true);
        $filePath = $tmpDir . '/test.php';
        file_put_contents($filePath, "<?php\nfoo_old();\n");

        $fromId = $api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'foo_old',
            'fix_template' => json_encode([
                'type' => 'function_rename',
                'old' => 'foo_old',
                'new' => 'foo_old',
            ]),
        ]);

        $projectId = $api->projects()->create('demo-noop', $tmpDir, 'module', '10.2.0');

        $matchId = $api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'test.php',
            'line_start' => 2,
            'matched_source' => 'foo_old()',
            'fix_method' => 'template',
        ]);
        $this->assertGreaterThan(0, $matchId);

        $applier = new TemplateApplier($api);
        $stats = $applier->applyWithStats($projectId, $tmpDir, null, false, false);

        $this->assertSame(0, $stats['applied']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['failed']);
        $this->assertCount(0, $api->matches()->findPending($projectId));

        $counts = $api->matches()->countByProject($projectId);
        $indexed = [];
        foreach ($counts as $row) {
            $indexed[$row['status']] = (int) $row['cnt'];
        }
        $this->assertSame(1, $indexed['skipped'] ?? 0);

        @unlink($filePath);
        @rmdir($tmpDir);
    }

    public function testInvalidTemplateMarksMatchFailed(): void
    {
        $api = $this->createTestApi();

        $tmpDir = sys_get_temp_dir() . '/evolver-applier-invalid-' . uniqid('', true);
        mkdir($tmpDir, 0777, true);
        $filePath = $tmpDir . '/test.php';
        file_put_contents($filePath, "<?php\nfoo_old();\n");

        $fromId = $api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'foo_old',
            'fix_template' => 'not json',
        ]);

        $projectId = $api->projects()->create('demo-invalid', $tmpDir, 'module', '10.2.0');

        $matchId = $api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'test.php',
            'line_start' => 2,
            'matched_source' => 'foo_old()',
            'fix_method' => 'template',
        ]);
        $this->assertGreaterThan(0, $matchId);

        $applier = new TemplateApplier($api);
        $stats = $applier->applyWithStats($projectId, $tmpDir, null, false, false);

        $this->assertSame(0, $stats['applied']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(1, $stats['failed']);
        $this->assertCount(0, $api->matches()->findPending($projectId));

        $counts = $api->matches()->countByProject($projectId);
        $indexed = [];
        foreach ($counts as $row) {
            $indexed[$row['status']] = (int) $row['cnt'];
        }
        $this->assertSame(1, $indexed['failed'] ?? 0);

        @unlink($filePath);
        @rmdir($tmpDir);
    }

    public function testLegacyFallbackFailsWhenMatchIsAmbiguous(): void
    {
        $api = $this->createTestApi();

        $tmpDir = sys_get_temp_dir() . '/evolver-applier-legacy-ambiguous-' . uniqid('', true);
        mkdir($tmpDir, 0777, true);
        $filePath = $tmpDir . '/test.php';
        file_put_contents($filePath, "<?php\nfoo_old();\nfoo_old();\n");

        $fromId = $api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'foo_old',
            'fix_template' => json_encode([
                'type' => 'function_rename',
                'old' => 'foo_old',
                'new' => 'foo_new',
            ]),
        ]);

        $projectId = $api->projects()->create('demo-legacy-ambiguous', $tmpDir, 'module', '10.2.0');

        $matchId = $api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'test.php',
            'line_start' => 2,
            'matched_source' => 'foo_old()',
            'fix_method' => 'template',
        ]);
        $this->assertGreaterThan(0, $matchId);

        $applier = new TemplateApplier($api);
        $stats = $applier->applyWithStats($projectId, $tmpDir, null, false, false);

        $this->assertSame(0, $stats['applied']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame("<?php\nfoo_old();\nfoo_old();\n", file_get_contents($filePath));

        $counts = $api->matches()->countByProject($projectId);
        $indexed = [];
        foreach ($counts as $row) {
            $indexed[$row['status']] = (int) $row['cnt'];
        }
        $this->assertSame(1, $indexed['failed'] ?? 0);

        @unlink($filePath);
        @rmdir($tmpDir);
    }

    public function testApplySupportsPharboristAnnotationToAttributeTransforms(): void
    {
        $api = $this->createTestApi();

        $tmpDir = sys_get_temp_dir() . '/evolver-applier-pharborist-' . uniqid('', true);
        mkdir($tmpDir, 0777, true);
        $filePath = $tmpDir . '/DemoBlock.php';
        $source = <<<'PHP'
<?php

namespace Drupal\demo\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * @Block(
 *   id = "demo_block"
 * )
 */
class DemoBlock extends BlockBase {}
PHP;
        file_put_contents($filePath, $source);

        $fromId = $api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'plugin_annotation_to_attribute',
            'old_fqn' => 'demo_block',
            'fix_template' => json_encode([
                'action' => 'annotation_to_attribute',
                'annotation' => 'Block',
                'attribute' => 'Block',
                'attribute_import' => 'Drupal\\Core\\Block\\Attribute\\Block',
            ]),
        ]);

        $projectId = $api->projects()->create('demo-pharborist', $tmpDir, 'module', '10.2.0');

        $matchId = $api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'DemoBlock.php',
            'line_start' => 7,
            'matched_source' => "@Block(\n *   id = \"demo_block\"\n * )",
            'fix_method' => 'pharborist',
        ]);
        $this->assertGreaterThan(0, $matchId);

        $applier = new TemplateApplier($api);
        $stats = $applier->applyWithStats($projectId, $tmpDir, null, false, false);

        $this->assertSame(1, $stats['applied']);
        $this->assertSame(1, $stats['files_changed']);
        $this->assertSame(0, $stats['failed']);

        $updated = file_get_contents($filePath);
        $this->assertIsString($updated);
        $this->assertStringContainsString("use Drupal\\Core\\Block\\Attribute\\Block;", $updated);
        $this->assertStringContainsString('#[Block(id: "demo_block")]', $updated);
        $this->assertStringNotContainsString('@Block(', $updated);

        $counts = $api->matches()->countByProject($projectId);
        $indexed = [];
        foreach ($counts as $row) {
            $indexed[$row['status']] = (int) $row['cnt'];
        }
        $this->assertSame(1, $indexed['applied'] ?? 0);

        @unlink($filePath);
        @rmdir($tmpDir);
    }
}
