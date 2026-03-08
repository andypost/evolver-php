<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Indexer\SymbolRelationBuilder;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

class SymbolRelationBuilderTest extends TestCase
{
    private ?Database $db = null;
    private ?DatabaseApi $api = null;
    private ?SymbolRelationBuilder $builder = null;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->api = new DatabaseApi(':memory:');
        $this->builder = new SymbolRelationBuilder($this->api);
    }

    public function testBuildServiceRelations(): void
    {
        $versionId = $this->api->versions()->save('10.0.0', 10, 0, 0);

        // Create a service symbol
        $fileId = $this->api->files()->save($versionId, 'test.services.yml', 'yaml', hash('sha256', 'test'), null, null, 1, 20);
        $_ = $this->api->symbols()->create([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'yaml',
            'symbol_type' => 'service',
            'fqn' => 'my_service',
            'name' => 'my_service',
            'signature_hash' => hash('sha256', 'service|my_service|[]'),
            'metadata_json' => json_encode(['class' => 'Drupal\my_module\MyService']),
            'source_text' => 'my_service:',
            'line_start' => 1,
            'line_end' => 1,
            'byte_start' => 0,
            'byte_end' => 20,
        ]);

        // Create the PHP class symbol
        $fileId2 = $this->api->files()->save($versionId, 'src/MyService.php', 'php', hash('sha256', 'test'), null, null, 1, 20);
        $_ = $this->api->symbols()->create([
            'version_id' => $versionId,
            'file_id' => $fileId2,
            'language' => 'php',
            'symbol_type' => 'class',
            'fqn' => 'Drupal\my_module\MyService',
            'name' => 'MyService',
            'namespace' => 'Drupal\my_module',
            'signature_hash' => hash('sha256', 'class|Drupal\my_module\MyService|null|null'),
            'source_text' => 'class MyService {}',
            'line_start' => 1,
            'line_end' => 1,
            'byte_start' => 0,
            'byte_end' => 20,
        ]);

        $this->builder->buildForVersion($versionId);

        $relations = $this->api->symbolRelations()->findByVersion($versionId);
        $this->assertCount(1, $relations);

        $relation = reset($relations);
        $this->assertSame(\DrupalEvolver\Storage\Repository\SymbolRelationRepo::RELATION_SERVICE_CLASS, $relation['relation_type']);
    }

    public function testBuildRouteControllerRelations(): void
    {
        $versionId = $this->api->versions()->save('10.0.0', 10, 0, 0);

        // Create a route symbol
        $fileId = $this->api->files()->save($versionId, 'test.routing.yml', 'yaml', hash('sha256', 'test'), null, null, 1, 20);
        $_ = $this->api->symbols()->create([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'yaml',
            'symbol_type' => 'drupal_route',
            'fqn' => 'my_module.example',
            'name' => 'my_module.example',
            'signature_hash' => hash('sha256', 'route|my_module.example|[]'),
            'metadata_json' => json_encode([
                'path' => '/example',
                'controller' => 'Drupal\my_module\Controller\ExampleController::build'
            ]),
            'source_text' => 'my_module.example:',
            'line_start' => 1,
            'line_end' => 1,
            'byte_start' => 0,
            'byte_end' => 20,
        ]);

        // Create the PHP method symbol
        $fileId2 = $this->api->files()->save($versionId, 'src/Controller/ExampleController.php', 'php', hash('sha256', 'test'), null, null, 1, 25);
        $_ = $this->api->symbols()->create([
            'version_id' => $versionId,
            'file_id' => $fileId2,
            'language' => 'php',
            'symbol_type' => 'method',
            'fqn' => 'Drupal\my_module\Controller\ExampleController::build',
            'name' => 'build',
            'namespace' => 'Drupal\my_module\Controller',
            'parent_symbol' => 'Drupal\my_module\Controller\ExampleController',
            'visibility' => 'public',
            'is_static' => 0,
            'signature_hash' => hash('sha256', 'method|Drupal\my_module\Controller\ExampleController::build|public|[]|null'),
            'signature_json' => json_encode(['params' => [], 'return_type' => null]),
            'source_text' => 'public function build() {}',
            'line_start' => 1,
            'line_end' => 1,
            'byte_start' => 0,
            'byte_end' => 25,
        ]);

        $this->builder->buildForVersion($versionId);

        $relations = $this->api->symbolRelations()->findByVersion($versionId);
        $this->assertCount(1, $relations);

        $relation = reset($relations);
        $this->assertSame(\DrupalEvolver\Storage\Repository\SymbolRelationRepo::RELATION_CONTROLLER_ROUTE, $relation['relation_type']);
    }

    public function testBuildPluginClassRelations(): void
    {
        $versionId = $this->api->versions()->save('10.0.0', 10, 0, 0);

        // Create a plugin definition symbol
        $fileId = $this->api->files()->save($versionId, 'src/Plugin/Block/MyBlock.php', 'php', hash('sha256', 'test'), null, null, 1, 30);
        $_ = $this->api->symbols()->create([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'php',
            'symbol_type' => 'plugin_definition',
            'fqn' => 'my_block',
            'name' => 'Block',
            'parent_symbol' => 'Drupal\my_module\Plugin\Block\MyBlock',
            'signature_hash' => hash('sha256', 'plugin_definition|my_block|[]'),
            'metadata_json' => json_encode(['plugin_type' => 'Block', 'plugin_id' => 'my_block']),
            'source_text' => '@Block',
            'line_start' => 1,
            'line_end' => 1,
            'byte_start' => 0,
            'byte_end' => 10,
        ]);

        // Create the PHP class symbol
        $_ = $this->api->symbols()->create([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'php',
            'symbol_type' => 'class',
            'fqn' => 'Drupal\my_module\Plugin\Block\MyBlock',
            'name' => 'MyBlock',
            'namespace' => 'Drupal\my_module\Plugin\Block',
            'signature_hash' => hash('sha256', 'class|Drupal\my_module\Plugin\Block\MyBlock|null|null'),
            'source_text' => 'class MyBlock {}',
            'line_start' => 2,
            'line_end' => 2,
            'byte_start' => 10,
            'byte_end' => 30,
        ]);

        $this->builder->buildForVersion($versionId);

        $relations = $this->api->symbolRelations()->findByVersion($versionId);
        $this->assertCount(1, $relations);

        $relation = reset($relations);
        $this->assertSame(\DrupalEvolver\Storage\Repository\SymbolRelationRepo::RELATION_PLUGIN_DEF, $relation['relation_type']);
    }

    public function testHandlesMissingRelations(): void
    {
        $versionId = $this->api->versions()->save('10.0.0', 10, 0, 0);

        // Create a service symbol without a matching class
        $fileId = $this->api->files()->save($versionId, 'test.services.yml', 'yaml', hash('sha256', 'test'), null, null, 1, 20);
        $_ = $this->api->symbols()->create([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'yaml',
            'symbol_type' => 'service',
            'fqn' => 'orphan_service',
            'name' => 'orphan_service',
            'signature_hash' => hash('sha256', 'service|orphan_service|[]'),
            'metadata_json' => json_encode(['class' => 'Drupal\nonexistent\Orphan']),
            'source_text' => 'orphan_service:',
            'line_start' => 1,
            'line_end' => 1,
            'byte_start' => 0,
            'byte_end' => 20,
        ]);

        // Should not throw, just skip the relation
        $this->builder->buildForVersion($versionId);

        $relations = $this->api->symbolRelations()->findByVersion($versionId);
        $this->assertCount(0, $relations);
    }
}
