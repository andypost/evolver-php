<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Differ;

use DrupalEvolver\Differ\FixTemplateGenerator;
use DrupalEvolver\Differ\RenameMatcher;
use DrupalEvolver\Differ\SignatureDiffer;
use DrupalEvolver\Differ\VersionDiffer;
use DrupalEvolver\Differ\YAMLDiffer;
use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

class VersionDifferTest extends TestCase
{
    private DatabaseApi $api;
    private VersionDiffer $differ;

    /** @var array<string, int> Symbol IDs keyed by label */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->api = new DatabaseApi(':memory:');
        $this->differ = new VersionDiffer(
            $this->api,
            new SignatureDiffer(),
            new RenameMatcher(),
            new YAMLDiffer(),
            new FixTemplateGenerator(),
            new QueryGenerator(),
        );

        $this->seedTestData();
    }

    // -- Data fixtures -------------------------------------------------------

    private function seedTestData(): void
    {
        $oldVer = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $newVer = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $oldPhp = $this->api->files()->create($oldVer, 'core/old.php', 'php', 'oldphp', null, null, 10, 100);
        $newPhp = $this->api->files()->create($newVer, 'core/new.php', 'php', 'newphp', null, null, 10, 100);
        $oldYml = $this->api->files()->create($oldVer, 'core/services.yml', 'yaml', 'oldyml', null, null, 10, 100);
        $newYml = $this->api->files()->create($newVer, 'core/services.yml', 'yaml', 'newyml', null, null, 10, 100);

        // Renamed function: old_render -> new_render (same signature body)
        $this->ids['old_render'] = $this->api->symbols()->create([
            'version_id' => $oldVer, 'file_id' => $oldPhp,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'Drupal\\Core\\old_render', 'name' => 'old_render',
            'signature_hash' => 'old-render-hash',
            'signature_json' => '{"params":[{"name":"$build","type":"array"}],"return_type":"string"}',
            'source_text' => 'function old_render(array $build): string { return implode(",", $build); }',
        ]);
        $this->ids['new_render'] = $this->api->symbols()->create([
            'version_id' => $newVer, 'file_id' => $newPhp,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'Drupal\\Core\\new_render', 'name' => 'new_render',
            'signature_hash' => 'new-render-hash',
            'signature_json' => '{"params":[{"name":"$build","type":"array"}],"return_type":"string"}',
            'source_text' => 'function new_render(array $build): string { return implode(",", $build); }',
        ]);

        // Namespace move: Drupal\Core\Old\MovedClass -> Drupal\Core\New\MovedClass
        $this->ids['old_class'] = $this->api->symbols()->create([
            'version_id' => $oldVer, 'file_id' => $oldPhp,
            'language' => 'php', 'symbol_type' => 'class',
            'fqn' => 'Drupal\\Core\\Old\\MovedClass', 'name' => 'MovedClass',
            'signature_hash' => 'old-class-hash',
            'signature_json' => '{"parent":null,"interfaces":null}',
            'source_text' => 'class MovedClass {}',
        ]);
        $this->ids['new_class'] = $this->api->symbols()->create([
            'version_id' => $newVer, 'file_id' => $newPhp,
            'language' => 'php', 'symbol_type' => 'class',
            'fqn' => 'Drupal\\Core\\New\\MovedClass', 'name' => 'MovedClass',
            'signature_hash' => 'new-class-hash',
            'signature_json' => '{"parent":null,"interfaces":null}',
            'source_text' => 'class MovedClass {}',
        ]);

        // YAML service with changed class
        $this->ids['old_service'] = $this->api->symbols()->create([
            'version_id' => $oldVer, 'file_id' => $oldYml,
            'language' => 'yaml', 'symbol_type' => 'service',
            'fqn' => 'my.service', 'name' => 'my.service',
            'signature_hash' => 'service-old-hash',
            'signature_json' => '{"class":"Drupal\\\\Core\\\\OldService","arguments":["@logger.factory"]}',
        ]);
        $this->ids['new_service'] = $this->api->symbols()->create([
            'version_id' => $newVer, 'file_id' => $newYml,
            'language' => 'yaml', 'symbol_type' => 'service',
            'fqn' => 'my.service', 'name' => 'my.service',
            'signature_hash' => 'service-new-hash',
            'signature_json' => '{"class":"Drupal\\\\Core\\\\NewService","arguments":["@logger.factory"]}',
        ]);

        // Signature change: added parameter
        $this->ids['old_sig'] = $this->api->symbols()->create([
            'version_id' => $oldVer, 'file_id' => $oldPhp,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'Drupal\\Core\\sig_changed', 'name' => 'sig_changed',
            'signature_hash' => 'sig-old-hash',
            'signature_json' => '{"params":[{"name":"$a","type":"string"}],"return_type":null}',
        ]);
        $this->ids['new_sig'] = $this->api->symbols()->create([
            'version_id' => $newVer, 'file_id' => $newPhp,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'Drupal\\Core\\sig_changed', 'name' => 'sig_changed',
            'signature_hash' => 'sig-new-hash',
            'signature_json' => '{"params":[{"name":"$a","type":"string"},{"name":"$context","type":"array"}],"return_type":null}',
        ]);

        // Deprecated in old, removed in new
        $this->ids['legacy'] = $this->api->symbols()->create([
            'version_id' => $oldVer, 'file_id' => $oldPhp,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'Drupal\\Core\\legacy_func', 'name' => 'legacy_func',
            'signature_hash' => 'legacy-hash',
            'signature_json' => '{"params":[],"return_type":null}',
            'source_text' => 'function legacy_func() {}',
            'is_deprecated' => 1,
            'deprecation_message' => 'Use new API.',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runDiff(): array
    {
        return $this->differ->diff('10.2.0', '10.3.0');
    }

    // -- Tests ---------------------------------------------------------------

    public function testDiffProducesExpectedChangeCount(): void
    {
        $changes = $this->runDiff();
        $this->assertCount(5, $changes);
    }

    public function testFunctionRenameDetected(): void
    {
        $changes = $this->runDiff();
        $rename = $this->findChange($changes, 'function_renamed', 'Drupal\\Core\\old_render');

        $this->assertNotNull($rename, 'function_renamed change should exist');
        $this->assertSame($this->ids['old_render'], $rename['old_symbol_id']);
        $this->assertSame($this->ids['new_render'], $rename['new_symbol_id']);
        $this->assertSame('Drupal\\Core\\new_render', $rename['new_fqn']);
        $this->assertSame('breaking', $rename['severity']);
    }

    public function testFunctionRenameFixTemplate(): void
    {
        $changes = $this->runDiff();
        $rename = $this->findChange($changes, 'function_renamed', 'Drupal\\Core\\old_render');

        $this->assertNotNull($rename);
        $template = json_decode((string) ($rename['fix_template'] ?? ''), true);
        $this->assertSame('function_rename', $template['type']);
        $this->assertSame('old_render', $template['old']);
        $this->assertSame('new_render', $template['new']);
    }

    public function testNamespaceMoveDetected(): void
    {
        $changes = $this->runDiff();
        $classRename = $this->findChange($changes, 'class_renamed', 'Drupal\\Core\\Old\\MovedClass');

        $this->assertNotNull($classRename, 'class_renamed change should exist');
        $this->assertSame($this->ids['old_class'], $classRename['old_symbol_id']);
        $this->assertSame($this->ids['new_class'], $classRename['new_symbol_id']);
        $this->assertSame('Drupal\\Core\\New\\MovedClass', $classRename['new_fqn']);
    }

    public function testNamespaceMoveFixTemplate(): void
    {
        $changes = $this->runDiff();
        $classRename = $this->findChange($changes, 'class_renamed', 'Drupal\\Core\\Old\\MovedClass');

        $this->assertNotNull($classRename);
        $template = json_decode((string) ($classRename['fix_template'] ?? ''), true);
        $this->assertSame('namespace_move', $template['type']);
        $this->assertSame('Drupal\\Core\\Old', $template['old_namespace']);
        $this->assertSame('Drupal\\Core\\New', $template['new_namespace']);
        $this->assertSame('MovedClass', $template['class']);
    }

    public function testYamlServiceClassChangeDetected(): void
    {
        $changes = $this->runDiff();
        $yaml = $this->findChange($changes, 'service_class_changed', 'my.service');

        $this->assertNotNull($yaml, 'service_class_changed change should exist');
        $this->assertSame('yaml', $yaml['language']);
        $this->assertSame('my.service', $yaml['new_fqn']);

        $diff = json_decode((string) ($yaml['diff_json'] ?? ''), true);
        $this->assertNotNull($diff);
        $this->assertSame('Drupal\\Core\\OldService', $diff['old_class']);
        $this->assertSame('Drupal\\Core\\NewService', $diff['new_class']);
    }

    public function testSignatureChangeDetected(): void
    {
        $changes = $this->runDiff();
        $sig = $this->findChange($changes, 'signature_changed', 'Drupal\\Core\\sig_changed');

        $this->assertNotNull($sig, 'signature_changed change should exist');
        $this->assertSame($this->ids['old_sig'], $sig['old_symbol_id']);
        $this->assertSame($this->ids['new_sig'], $sig['new_symbol_id']);
        $this->assertSame('breaking', $sig['severity']);
    }

    public function testSignatureChangeFixTemplate(): void
    {
        $changes = $this->runDiff();
        $sig = $this->findChange($changes, 'signature_changed', 'Drupal\\Core\\sig_changed');

        $this->assertNotNull($sig);
        $template = json_decode((string) ($sig['fix_template'] ?? ''), true);
        $this->assertSame('parameter_insert', $template['type']);
        $this->assertSame(1, $template['position']);
    }

    public function testSignatureChangeDiffJson(): void
    {
        $changes = $this->runDiff();
        $sig = $this->findChange($changes, 'signature_changed', 'Drupal\\Core\\sig_changed');

        $this->assertNotNull($sig);
        $diff = json_decode((string) ($sig['diff_json'] ?? ''), true);
        $this->assertIsArray($diff['changes']);
        $this->assertCount(1, $diff['old']['params']);
        $this->assertCount(2, $diff['new']['params']);
    }

    public function testDeprecatedThenRemovedHasRemovalSeverity(): void
    {
        $changes = $this->runDiff();
        $removal = $this->findChange($changes, 'function_removed', 'Drupal\\Core\\legacy_func');

        $this->assertNotNull($removal, 'deprecated-then-removed change should exist');
        $this->assertSame('removal', $removal['severity']);
        $this->assertSame($this->ids['legacy'], $removal['old_symbol_id']);
        $this->assertSame('Use new API.', $removal['migration_hint']);
    }

    public function testDeprecatedRemovedNotDuplicatedAsBreaking(): void
    {
        $changes = $this->runDiff();

        $breakingRemoved = array_filter($changes, static fn(array $c): bool =>
            ($c['change_type'] ?? '') === 'function_removed'
            && ($c['old_fqn'] ?? '') === 'Drupal\\Core\\legacy_func'
            && ($c['severity'] ?? '') === 'breaking'
        );

        $this->assertCount(0, $breakingRemoved, 'deprecated-then-removed should not appear as breaking');
    }

    public function testAllChangesHaveTsQuery(): void
    {
        $changes = $this->runDiff();

        foreach ($changes as $change) {
            $this->assertNotNull(
                $change['ts_query'] ?? null,
                "Change {$change['change_type']} for {$change['old_fqn']} should have ts_query"
            );
            $this->assertNotEmpty($change['ts_query']);
        }
    }

    public function testChangesStoredInDatabase(): void
    {
        $this->runDiff();

        $fromVer = $this->api->versions()->findByTag('10.2.0');
        $toVer = $this->api->versions()->findByTag('10.3.0');
        $count = $this->api->changes()->countByVersions((int) $fromVer['id'], (int) $toVer['id']);

        $this->assertSame(5, $count);
    }

    public function testRerunDiffClearsPreviousChanges(): void
    {
        $this->runDiff();
        $this->runDiff();

        $fromVer = $this->api->versions()->findByTag('10.2.0');
        $toVer = $this->api->versions()->findByTag('10.3.0');
        $count = $this->api->changes()->countByVersions((int) $fromVer['id'], (int) $toVer['id']);

        $this->assertSame(5, $count, 'Re-running diff should replace, not duplicate');
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $changes
     */
    private function findChange(array $changes, string $type, string $oldFqn): ?array
    {
        foreach ($changes as $change) {
            if (($change['change_type'] ?? null) === $type && ($change['old_fqn'] ?? null) === $oldFqn) {
                return $change;
            }
        }

        return null;
    }
}
