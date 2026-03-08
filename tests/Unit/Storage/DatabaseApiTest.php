<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Storage;

use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

class DatabaseApiTest extends TestCase
{
    private DatabaseApi $api;

    protected function setUp(): void
    {
        $this->api = new DatabaseApi(':memory:');
    }

    public function testLazyRepoLoading(): void
    {
        $v1 = $this->api->versions();
        $v2 = $this->api->versions();
        $this->assertSame($v1, $v2, 'Repos should be singletons');

        $this->assertSame($this->api->files(), $this->api->files());
        $this->assertSame($this->api->symbols(), $this->api->symbols());
        $this->assertSame($this->api->changes(), $this->api->changes());
        $this->assertSame($this->api->projects(), $this->api->projects());
        $this->assertSame($this->api->matches(), $this->api->matches());
    }

    public function testGetPathReturnsMemory(): void
    {
        $this->assertSame(':memory:', $this->api->getPath());
    }

    public function testPrepareCachesStatements(): void
    {
        $first = $this->api->prepare('versions.by_tag', 'SELECT * FROM versions WHERE tag = :tag');
        $second = $this->api->prepare('versions.by_tag', 'SELECT * FROM versions WHERE tag = :tag');

        $this->assertSame($first, $second);
    }

    public function testFindRemovedSymbols(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $oldFileId = $this->api->files()->create($fromId, 'old.php', 'php', 'h1', null, null, 10, 100);
        $newFileId = $this->api->files()->create($toId, 'new.php', 'php', 'h2', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $fromId, 'file_id' => $oldFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'removed_func', 'name' => 'removed_func',
            'signature_hash' => 'h1',
        ]);

        $this->createSymbol([
            'version_id' => $fromId, 'file_id' => $oldFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'kept_func', 'name' => 'kept_func',
            'signature_hash' => 'h2',
        ]);

        $this->createSymbol([
            'version_id' => $toId, 'file_id' => $newFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'kept_func', 'name' => 'kept_func',
            'signature_hash' => 'h2',
        ]);

        $removed = iterator_to_array($this->api->findRemovedSymbols($fromId, $toId));
        $this->assertCount(1, $removed);
        $this->assertSame('removed_func', $removed[0]['fqn']);
    }

    public function testFindAddedSymbols(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $oldFileId = $this->api->files()->create($fromId, 'old.php', 'php', 'h1', null, null, 10, 100);
        $newFileId = $this->api->files()->create($toId, 'new.php', 'php', 'h2', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $fromId, 'file_id' => $oldFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'existing', 'name' => 'existing',
            'signature_hash' => 'h1',
        ]);

        $this->createSymbol([
            'version_id' => $toId, 'file_id' => $newFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'existing', 'name' => 'existing',
            'signature_hash' => 'h1',
        ]);

        $this->createSymbol([
            'version_id' => $toId, 'file_id' => $newFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'new_func', 'name' => 'new_func',
            'signature_hash' => 'h3',
        ]);

        $added = iterator_to_array($this->api->findAddedSymbols($fromId, $toId));
        $this->assertCount(1, $added);
        $this->assertSame('new_func', $added[0]['fqn']);
    }

    public function testFindRemovedSymbolsHandlesLargeRemovedSets(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $oldFileId = $this->api->files()->create($fromId, 'old.php', 'php', 'h1', null, null, 10, 100);
        $newFileId = $this->api->files()->create($toId, 'new.php', 'php', 'h2', null, null, 10, 100);

        for ($i = 0; $i < 1100; $i++) {
            $this->createSymbol([
                'version_id' => $fromId,
                'file_id' => $oldFileId,
                'language' => 'php',
                'symbol_type' => 'function',
                'fqn' => "removed_func_{$i}",
                'name' => "removed_func_{$i}",
                'signature_hash' => "old_hash_{$i}",
            ]);
        }

        $this->createSymbol([
            'version_id' => $toId,
            'file_id' => $newFileId,
            'language' => 'php',
            'symbol_type' => 'function',
            'fqn' => 'kept_func',
            'name' => 'kept_func',
            'signature_hash' => 'kept_hash',
        ]);

        $removed = iterator_to_array($this->api->findRemovedSymbols($fromId, $toId));

        $this->assertCount(1100, $removed);
        $this->assertContains('removed_func_0', array_column($removed, 'fqn'));
        $this->assertContains('removed_func_1099', array_column($removed, 'fqn'));
    }

    public function testFindChangedSignatures(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $oldFileId = $this->api->files()->create($fromId, 'old.php', 'php', 'h1', null, null, 10, 100);
        $newFileId = $this->api->files()->create($toId, 'new.php', 'php', 'h2', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $fromId, 'file_id' => $oldFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'changed_func', 'name' => 'changed_func',
            'signature_hash' => 'old_hash',
            'signature_json' => '{"params":[{"name":"$a"}],"return_type":null}',
        ]);

        $this->createSymbol([
            'version_id' => $toId, 'file_id' => $newFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'changed_func', 'name' => 'changed_func',
            'signature_hash' => 'new_hash',
            'signature_json' => '{"params":[{"name":"$a"},{"name":"$b"}],"return_type":null}',
        ]);

        $changed = iterator_to_array($this->api->findChangedSignatures($fromId, $toId));
        $this->assertCount(1, $changed);
        $this->assertSame('changed_func', $changed[0]['old']['fqn']);
    }

    public function testFindNewlyDeprecated(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $oldFileId = $this->api->files()->create($fromId, 'old.php', 'php', 'h1', null, null, 10, 100);
        $newFileId = $this->api->files()->create($toId, 'new.php', 'php', 'h2', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $fromId, 'file_id' => $oldFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'will_deprecate', 'name' => 'will_deprecate',
            'signature_hash' => 'h1', 'is_deprecated' => 0,
        ]);

        $this->createSymbol([
            'version_id' => $toId, 'file_id' => $newFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'will_deprecate', 'name' => 'will_deprecate',
            'signature_hash' => 'h1', 'is_deprecated' => 1,
            'deprecation_message' => 'Use something else',
        ]);

        $deprecated = $this->api->findNewlyDeprecated($fromId, $toId);
        $this->assertCount(1, $deprecated);
        $this->assertSame('will_deprecate', $deprecated[0]['fqn']);
    }

    public function testFindDeprecatedThenRemoved(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $oldFileId = $this->api->files()->create($fromId, 'old.php', 'php', 'h1', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $fromId, 'file_id' => $oldFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'old_deprecated', 'name' => 'old_deprecated',
            'signature_hash' => 'h1', 'is_deprecated' => 1,
            'deprecation_message' => 'Removed in 11.0',
        ]);

        $removed = $this->api->findDeprecatedThenRemoved($fromId, $toId);
        $this->assertCount(1, $removed);
        $this->assertSame('old_deprecated', $removed[0]['fqn']);
    }

    public function testGetStats(): void
    {
        $this->assertGreaterThan(0, $this->api->versions()->create('10.2.0', 10, 2, 0));

        $stats = $this->api->getStats();
        $this->assertCount(1, $stats['versions']);
        $this->assertSame(0, $stats['symbol_count']);
        $this->assertSame(0, $stats['change_count']);
        $this->assertSame(0, $stats['project_count']);
        $this->assertSame(0, $stats['match_count']);
    }

    public function testDeleteChangesForPair(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $this->api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'test',
        ]);
        $this->assertGreaterThan(0, $changeId);

        $this->assertSame(1, $this->api->changes()->countByVersions($fromId, $toId));

        $this->assertSame(1, $this->api->deleteChangesForPair($fromId, $toId));
        $this->assertSame(0, $this->api->changes()->countByVersions($fromId, $toId));
        $this->assertSame(0, $this->api->deleteChangesForPair($fromId, $toId));
    }

    public function testFindSymbolById(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $fileId = $this->api->files()->create($versionId, 'test.php', 'php', 'h1', null, null, 10, 100);

        $id = $this->api->symbols()->create([
            'version_id' => $versionId, 'file_id' => $fileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'test_func', 'name' => 'test_func',
            'signature_hash' => 'h1',
        ]);

        $sym = $this->api->findSymbolById($id);
        $this->assertNotNull($sym);
        $this->assertSame('test_func', $sym['fqn']);

        $this->assertNull($this->api->findSymbolById(99999));
    }

    public function testFindPendingFixesWithTemplates(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $this->api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'foo',
            'fix_template' => '{"type":"function_rename","old":"foo","new":"bar"}',
        ]);

        $projectId = $this->api->projects()->create('test', '/tmp/test', 'module', '10.2.0');

        $matchId = $this->api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'test.php',
            'line_start' => 1,
            'matched_source' => 'foo()',
            'fix_method' => 'template',
        ]);
        $this->assertGreaterThan(0, $matchId);

        $fixes = $this->api->findPendingFixesWithTemplates($projectId);
        $this->assertCount(1, $fixes);
        $this->assertSame('function_removed', $fixes[0]['change_type']);
        $this->assertNotNull($fixes[0]['fix_template']);
    }

    public function testFindMatchesWithChanges(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $this->api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'foo',
        ]);

        $projectId = $this->api->projects()->create('test', '/tmp/test', 'module', '10.2.0');

        $matchId = $this->api->matches()->create([
            'project_id' => $projectId,
            'change_id' => $changeId,
            'file_path' => 'test.php',
            'line_start' => 5,
            'matched_source' => 'foo()',
        ]);
        $this->assertGreaterThan(0, $matchId);

        $matches = $this->api->findMatchesWithChanges($projectId);
        $this->assertCount(1, $matches);
        $this->assertSame('function_removed', $matches[0]['change_type']);
        $this->assertSame('breaking', $matches[0]['severity']);
    }

    public function testFindParamCountChanges(): void
    {
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $oldFileId = $this->api->files()->create($fromId, 'old.php', 'php', 'h1', null, null, 10, 100);
        $newFileId = $this->api->files()->create($toId, 'new.php', 'php', 'h2', null, null, 10, 100);

        // Function with 1 param -> now has 2 params (increased)
        $this->createSymbol([
            'version_id' => $fromId, 'file_id' => $oldFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'gained_param', 'name' => 'gained_param',
            'signature_hash' => 'old_1',
            'signature_json' => '{"params":[{"name":"$a"}],"return_type":null}',
        ]);
        $this->createSymbol([
            'version_id' => $toId, 'file_id' => $newFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'gained_param', 'name' => 'gained_param',
            'signature_hash' => 'new_1',
            'signature_json' => '{"params":[{"name":"$a"},{"name":"$b"}],"return_type":null}',
        ]);

        // Function with 2 params -> now has 1 param (decreased)
        $this->createSymbol([
            'version_id' => $fromId, 'file_id' => $oldFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'lost_param', 'name' => 'lost_param',
            'signature_hash' => 'old_2',
            'signature_json' => '{"params":[{"name":"$a"},{"name":"$b"}],"return_type":null}',
        ]);
        $this->createSymbol([
            'version_id' => $toId, 'file_id' => $newFileId,
            'language' => 'php', 'symbol_type' => 'function',
            'fqn' => 'lost_param', 'name' => 'lost_param',
            'signature_hash' => 'new_2',
            'signature_json' => '{"params":[{"name":"$a"}],"return_type":null}',
        ]);

        // All param count changes
        $allChanges = iterator_to_array($this->api->findParamCountChanges($fromId, $toId));
        $this->assertCount(2, $allChanges);

        // Only increased params
        $increased = iterator_to_array($this->api->findParamCountChanges($fromId, $toId, 'increased'));
        $this->assertCount(1, $increased);
        $this->assertSame('gained_param', $increased[0]['old']['fqn']);
        $this->assertSame(1, $increased[0]['old']['param_count']);
        $this->assertSame(2, $increased[0]['new']['param_count']);

        // Only decreased params
        $decreased = iterator_to_array($this->api->findParamCountChanges($fromId, $toId, 'decreased'));
        $this->assertCount(1, $decreased);
        $this->assertSame('lost_param', $decreased[0]['old']['fqn']);
        $this->assertSame(2, $decreased[0]['old']['param_count']);
        $this->assertSame(1, $decreased[0]['new']['param_count']);
    }

    public function testSearchSemanticYamlSymbolsFindsModuleMentions(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $fileId = $this->api->files()->create($versionId, 'modules/custom/example/example.info.yml', 'yaml', 'h1', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'yaml',
            'symbol_type' => 'module_info',
            'fqn' => 'example',
            'name' => 'example',
            'signature_hash' => 'yaml_hash_1',
            'signature_json' => '{"dependencies":["drupal:block","drupal:node"]}',
            'metadata_json' => '{"label":"Example","dependency_targets":["block","node"],"mentioned_extensions":["block","node"],"configure_route":"example.settings"}',
        ]);

        $results = $this->api->searchSemanticYamlSymbols($versionId, 'block', ['module_info']);

        $this->assertCount(1, $results);
        $this->assertSame('module_info', $results[0]['symbol_type']);
        $this->assertSame('example', $results[0]['fqn']);
        $this->assertSame('modules/custom/example/example.info.yml', $results[0]['file_path']);
    }

    public function testSearchSemanticYamlSymbolsFindsRouteReferences(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $fileId = $this->api->files()->create($versionId, 'core/modules/block/block.links.contextual.yml', 'yaml', 'h2', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'yaml',
            'symbol_type' => 'link_contextual',
            'fqn' => 'block_configure',
            'name' => 'block_configure',
            'signature_hash' => 'yaml_hash_2',
            'signature_json' => '{"route_name":"entity.block.edit_form"}',
            'metadata_json' => '{"label":"Configure block","route_name":"entity.block.edit_form","route_refs":["entity.block.edit_form"],"group":"block"}',
        ]);

        $results = $this->api->searchSemanticYamlSymbols($versionId, 'entity.block.edit_form', ['link_contextual']);

        $this->assertCount(1, $results);
        $this->assertSame('block_configure', $results[0]['fqn']);
        $this->assertSame('core/modules/block/block.links.contextual.yml', $results[0]['file_path']);
    }

    public function testSearchSemanticYamlSymbolsFindsServiceByClassName(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $fileId = $this->api->files()->create($versionId, 'core/modules/block/block.services.yml', 'yaml', 'h4', null, null, 10, 100);
        $classFileId = $this->api->files()->create($versionId, 'core/modules/block/src/BlockRepository.php', 'php', 'h5', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $classFileId,
            'language' => 'php',
            'symbol_type' => 'class',
            'fqn' => 'Drupal\\block\\BlockRepository',
            'name' => 'BlockRepository',
            'namespace' => 'Drupal\\block',
            'signature_hash' => 'php_hash_1',
        ]);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'yaml',
            'symbol_type' => 'service',
            'fqn' => 'block.repository',
            'name' => 'block.repository',
            'signature_hash' => 'yaml_hash_4',
            'signature_json' => '{"class":"Drupal\\\\block\\\\BlockRepository","arguments":"[@entity_type.manager]"}',
        ]);

        $results = $this->api->searchSemanticYamlSymbols($versionId, 'Drupal\\block\\BlockRepository', ['service']);

        $this->assertCount(1, $results);
        $this->assertSame('block.repository', $results[0]['fqn']);
        $this->assertSame('Drupal\\block\\BlockRepository', $results[0]['resolved_class_fqn']);
        $this->assertSame('core/modules/block/src/BlockRepository.php', $results[0]['resolved_class_file_path']);
    }

    public function testSearchSemanticYamlSymbolsFindsDrupalLibraryByAssetPath(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $fileId = $this->api->files()->create($versionId, 'core/modules/block/block.libraries.yml', 'drupal_libraries', 'h_lib', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'drupal_libraries',
            'symbol_type' => 'drupal_library',
            'fqn' => 'drupal.block.admin',
            'name' => 'drupal.block.admin',
            'signature_hash' => 'yaml_hash_lib_1',
            'signature_json' => '{"dependencies":["core/drupal","core/once"],"js":{"js/block.admin.js":{}},"css":{"theme":{"css/block.admin.css":{}}}}',
            'metadata_json' => '{"owner":"block","asset_paths":["core/modules/block/css/block.admin.css","core/modules/block/js/block.admin.js"],"javascript_assets":["core/modules/block/js/block.admin.js"],"css_assets":["core/modules/block/css/block.admin.css"],"dependency_libraries":["core/drupal","core/once"],"dependency_owners":["core"]}',
        ]);

        $results = $this->api->searchSemanticYamlSymbols($versionId, 'core/modules/block/js/block.admin.js', ['drupal_library']);

        $this->assertCount(1, $results);
        $this->assertSame('drupal_library', $results[0]['symbol_type']);
        $this->assertSame('drupal.block.admin', $results[0]['fqn']);
        $this->assertSame('core/modules/block/block.libraries.yml', $results[0]['file_path']);
    }

    public function testFindSemanticLinksForServiceSymbol(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $yamlFileId = $this->api->files()->create($versionId, 'core/modules/block/block.services.yml', 'yaml', 'h4', null, null, 10, 100);
        $phpFileId = $this->api->files()->create($versionId, 'core/modules/block/src/BlockRepository.php', 'php', 'h5', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $phpFileId,
            'language' => 'php',
            'symbol_type' => 'class',
            'fqn' => 'Drupal\\block\\BlockRepository',
            'name' => 'BlockRepository',
            'namespace' => 'Drupal\\block',
            'signature_hash' => 'php_hash_2',
        ]);

        $serviceId = $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $yamlFileId,
            'language' => 'yaml',
            'symbol_type' => 'service',
            'fqn' => 'block.repository',
            'name' => 'block.repository',
            'signature_hash' => 'yaml_hash_5',
            'signature_json' => '{"class":"Drupal\\\\block\\\\BlockRepository"}',
        ]);

        $links = $this->api->findSemanticLinksForSymbol($serviceId);

        $this->assertCount(1, $links);
        $this->assertSame('implementation_class', $links[0]['relationship']);
        $this->assertSame('Drupal\\block\\BlockRepository', $links[0]['symbol']['fqn']);
        $this->assertSame('core/modules/block/src/BlockRepository.php', $links[0]['symbol']['file_path']);
    }

    public function testFindSemanticLinksForClassSymbol(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $yamlFileId = $this->api->files()->create($versionId, 'core/modules/block/block.services.yml', 'yaml', 'h4', null, null, 10, 100);
        $phpFileId = $this->api->files()->create($versionId, 'core/modules/block/src/BlockRepository.php', 'php', 'h5', null, null, 10, 100);

        $classId = $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $phpFileId,
            'language' => 'php',
            'symbol_type' => 'class',
            'fqn' => 'Drupal\\block\\BlockRepository',
            'name' => 'BlockRepository',
            'namespace' => 'Drupal\\block',
            'signature_hash' => 'php_hash_3',
        ]);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $yamlFileId,
            'language' => 'yaml',
            'symbol_type' => 'service',
            'fqn' => 'block.repository',
            'name' => 'block.repository',
            'signature_hash' => 'yaml_hash_6',
            'signature_json' => '{"class":"Drupal\\\\block\\\\BlockRepository"}',
        ]);

        $links = $this->api->findSemanticLinksForSymbol($classId);

        $this->assertCount(1, $links);
        $this->assertSame('registered_service', $links[0]['relationship']);
        $this->assertSame('block.repository', $links[0]['symbol']['fqn']);
        $this->assertSame('core/modules/block/block.services.yml', $links[0]['symbol']['file_path']);
    }

    public function testFindSemanticLinksForDrupalLibrarySymbol(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $libraryFileId = $this->api->files()->create($versionId, 'core/modules/block/block.libraries.yml', 'drupal_libraries', 'h_lib_2', null, null, 10, 100);
        $jsFileId = $this->api->files()->create($versionId, 'core/modules/block/js/block.admin.js', 'javascript', 'h_js_1', null, null, 10, 100);
        $cssFileId = $this->api->files()->create($versionId, 'core/modules/block/css/block.admin.css', 'css', 'h_css_1', null, null, 10, 100);

        $libraryId = $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $libraryFileId,
            'language' => 'drupal_libraries',
            'symbol_type' => 'drupal_library',
            'fqn' => 'drupal.block.admin',
            'name' => 'drupal.block.admin',
            'signature_hash' => 'yaml_hash_lib_2',
            'metadata_json' => '{"asset_paths":["core/modules/block/css/block.admin.css","core/modules/block/js/block.admin.js"],"javascript_assets":["core/modules/block/js/block.admin.js"],"css_assets":["core/modules/block/css/block.admin.css"]}',
        ]);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $jsFileId,
            'language' => 'javascript',
            'symbol_type' => 'function',
            'fqn' => 'announceBlockUpdate',
            'name' => 'announceBlockUpdate',
            'signature_hash' => 'js_hash_1',
        ]);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $cssFileId,
            'language' => 'css',
            'symbol_type' => 'css_selector',
            'fqn' => '.block-admin',
            'name' => '.block-admin',
            'signature_hash' => 'css_hash_1',
        ]);

        $links = $this->api->findSemanticLinksForSymbol($libraryId);

        $this->assertCount(2, $links);
        $this->assertSame('css_asset_symbol', $links[0]['relationship']);
        $this->assertSame('core/modules/block/css/block.admin.css', $links[0]['symbol']['file_path']);
        $this->assertSame('javascript_asset_symbol', $links[1]['relationship']);
        $this->assertSame('core/modules/block/js/block.admin.js', $links[1]['symbol']['file_path']);
    }

    public function testFindSemanticLinksForAssetSymbolReturnsOwningLibrary(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $libraryFileId = $this->api->files()->create($versionId, 'core/modules/block/block.libraries.yml', 'drupal_libraries', 'h_lib_3', null, null, 10, 100);
        $jsFileId = $this->api->files()->create($versionId, 'core/modules/block/js/block.admin.js', 'javascript', 'h_js_2', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $libraryFileId,
            'language' => 'drupal_libraries',
            'symbol_type' => 'drupal_library',
            'fqn' => 'drupal.block.admin',
            'name' => 'drupal.block.admin',
            'signature_hash' => 'yaml_hash_lib_3',
            'metadata_json' => '{"asset_paths":["core/modules/block/js/block.admin.js"]}',
        ]);

        $assetSymbolId = $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $jsFileId,
            'language' => 'javascript',
            'symbol_type' => 'function',
            'fqn' => 'announceBlockUpdate',
            'name' => 'announceBlockUpdate',
            'signature_hash' => 'js_hash_2',
        ]);

        $links = $this->api->findSemanticLinksForSymbol($assetSymbolId);

        $this->assertCount(1, $links);
        $this->assertSame('declared_by_library', $links[0]['relationship']);
        $this->assertSame('drupal.block.admin', $links[0]['symbol']['fqn']);
        $this->assertSame('core/modules/block/block.libraries.yml', $links[0]['symbol']['file_path']);
    }

    public function testSearchSemanticYamlSymbolsFindsExportedConfigDependencies(): void
    {
        $versionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $fileId = $this->api->files()->create($versionId, 'db/config/system.site.yml', 'yaml', 'h3', null, null, 10, 100);

        $this->createSymbol([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'yaml',
            'symbol_type' => 'config_export',
            'fqn' => 'system.site',
            'name' => 'system.site',
            'signature_hash' => 'yaml_hash_3',
            'signature_json' => '{"dependencies":{"module":["block","node"]},"status":true}',
            'metadata_json' => '{"top_level_keys":["dependencies","status"],"dependency_modules":["block","node"],"skipped_keys":["uuid","langcode","_core.default_config_hash"]}',
        ]);

        $results = $this->api->searchSemanticYamlSymbols($versionId, 'node', ['config_export']);

        $this->assertCount(1, $results);
        $this->assertSame('config_export', $results[0]['symbol_type']);
        $this->assertSame('system.site', $results[0]['fqn']);
    }

    public function testSummarizeScanRunCountsPharboristMatchesAsAutoFixable(): void
    {
        $projectId = $this->api->projects()->create('test', '/tmp/test', 'module', '10.2.0');
        $fromId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $this->api->versions()->create('10.3.0', 10, 3, 0);

        $changeId = $this->api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'plugin_annotation_to_attribute',
            'severity' => 'deprecation',
            'old_fqn' => 'demo_block',
        ]);

        $runId = $this->api->scanRuns()->create(
            $projectId,
            'main',
            null,
            null,
            '10.2.0',
            '10.3.0',
            'completed',
        );

        $matchId = $this->api->matches()->create([
            'project_id' => $projectId,
            'scan_run_id' => $runId,
            'change_id' => $changeId,
            'file_path' => 'src/Plugin/Block/DemoBlock.php',
            'line_start' => 7,
            'matched_source' => '@Block(...)',
            'fix_method' => 'pharborist',
        ]);
        $this->assertGreaterThan(0, $matchId);

        $summary = $this->api->summarizeScanRun($runId);

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['auto_fixable']);
        $this->assertSame(1, $summary['by_change_type']['plugin_annotation_to_attribute']);
        $this->assertSame(1, $summary['by_category']['Modernization']);
    }

    private function createSymbol(array $data): int
    {
        $id = $this->api->symbols()->create($data);
        $this->assertGreaterThan(0, $id);

        return $id;
    }
}
