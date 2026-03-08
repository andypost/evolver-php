<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer;

use DrupalEvolver\Storage\DatabaseApi;

/**
 * Builds cross-file relations between symbols.
 *
 * Links:
 * - Service definitions (YAML) to service classes (PHP)
 * - Route definitions to controller classes
 * - Plugin definitions to implementing classes
 */
class SymbolRelationBuilder
{
    public function __construct(
        private DatabaseApi $api,
    ) {}

    /**
     * Build all relations for a given version.
     */
    public function buildForVersion(int $versionId): void
    {
        $this->buildServiceRelations($versionId);
        $this->buildRouteControllerRelations($versionId);
        $this->buildPluginClassRelations($versionId);
    }

    /**
     * Link service definitions to their implementing classes.
     */
    private function buildServiceRelations(int $versionId): void
    {
        // Get all service symbols for this version
        $services = $this->api->db()->query(
            "SELECT s.id, s.fqn, s.metadata_json
             FROM symbols s
             WHERE s.version_id = :vid
               AND s.symbol_type = 'service'",
            ['vid' => $versionId]
        )->fetchAll();

        foreach ($services as $service) {
            $meta = json_decode($service['metadata_json'], true);
            $class = $meta['class'] ?? null;
            if (!$class) {
                continue;
            }

            // Find the PHP class symbol
            $classSymbol = $this->api->db()->query(
                "SELECT s.id
                 FROM symbols s
                 WHERE s.version_id = :vid
                   AND s.symbol_type IN ('class', 'interface')
                   AND s.fqn = :fqn
                 LIMIT 1",
                ['vid' => $versionId, 'fqn' => $class]
            )->fetch();

            if ($classSymbol) {
                $_ = $this->api->symbolRelations()->create(
                    $versionId,
                    (int) $service['id'],
                    (int) $classSymbol['id'],
                    \DrupalEvolver\Storage\Repository\SymbolRelationRepo::RELATION_SERVICE_CLASS,
                    ['service_id' => $service['fqn']]
                );
            }
        }
    }

    /**
     * Link route definitions to their controller classes/methods.
     */
    private function buildRouteControllerRelations(int $versionId): void
    {
        // Get all route symbols for this version
        $routes = $this->api->db()->query(
            "SELECT s.id, s.fqn, s.metadata_json
             FROM symbols s
             WHERE s.version_id = :vid
               AND s.symbol_type = 'drupal_route'",
            ['vid' => $versionId]
        )->fetchAll();

        foreach ($routes as $route) {
            $meta = json_decode($route['metadata_json'], true);
            $controller = $meta['controller'] ?? null;
            if (!$controller) {
                continue;
            }

            // Parse controller string (e.g., 'Drupal\module\Controller\Example::method')
            if (!str_contains($controller, '::')) {
                continue;
            }

            [$classFqn, $methodName] = explode('::', $controller, 2);

            // Find the PHP method symbol
            $methodSymbol = $this->api->db()->query(
                "SELECT s.id
                 FROM symbols s
                 WHERE s.version_id = :vid
                   AND s.symbol_type = 'method'
                   AND s.fqn = :fqn
                 LIMIT 1",
                ['vid' => $versionId, 'fqn' => $classFqn . '::' . $methodName]
            )->fetch();

            if (!$methodSymbol) {
                // Try to find the class at least
                $classSymbol = $this->api->db()->query(
                    "SELECT s.id
                     FROM symbols s
                     WHERE s.version_id = :vid
                       AND s.symbol_type = 'class'
                       AND s.fqn = :fqn
                     LIMIT 1",
                    ['vid' => $versionId, 'fqn' => $classFqn]
                )->fetch();

                if ($classSymbol) {
                    $_ = $this->api->symbolRelations()->create(
                        $versionId,
                        (int) $route['id'],
                        (int) $classSymbol['id'],
                        \DrupalEvolver\Storage\Repository\SymbolRelationRepo::RELATION_CONTROLLER_ROUTE,
                        ['route_id' => $route['fqn'], 'method' => $methodName]
                    );
                }
            } else {
                $_ = $this->api->symbolRelations()->create(
                    $versionId,
                    (int) $route['id'],
                    (int) $methodSymbol['id'],
                    \DrupalEvolver\Storage\Repository\SymbolRelationRepo::RELATION_CONTROLLER_ROUTE,
                    ['route_id' => $route['fqn']]
                );
            }
        }
    }

    /**
     * Link plugin definitions to their implementing classes.
     */
    private function buildPluginClassRelations(int $versionId): void
    {
        // Get all plugin definition symbols for this version
        $plugins = $this->api->db()->query(
            "SELECT s.id, s.fqn, s.name, s.parent_symbol, s.metadata_json, s.line_start
             FROM symbols s
             WHERE s.version_id = :vid
               AND s.symbol_type = 'plugin_definition'",
            ['vid' => $versionId]
        )->fetchAll();

        foreach ($plugins as $plugin) {
            // The parent_symbol should point to the class that owns this plugin
            $parentClass = $plugin['parent_symbol'] ?? null;
            if (!$parentClass) {
                continue;
            }

            // Find the PHP class symbol
            $classSymbol = $this->api->db()->query(
                "SELECT s.id
                 FROM symbols s
                 WHERE s.version_id = :vid
                   AND s.symbol_type IN ('class', 'interface')
                   AND s.fqn = :fqn
                 LIMIT 1",
                ['vid' => $versionId, 'fqn' => $parentClass]
            )->fetch();

            if ($classSymbol) {
                $meta = json_decode($plugin['metadata_json'], true);
                $_ = $this->api->symbolRelations()->create(
                    $versionId,
                    (int) $plugin['id'],
                    (int) $classSymbol['id'],
                    \DrupalEvolver\Storage\Repository\SymbolRelationRepo::RELATION_PLUGIN_DEF,
                    [
                        'plugin_id' => $plugin['fqn'],
                        'plugin_type' => $meta['plugin_type'] ?? $plugin['name'],
                    ]
                );
            }
        }
    }
}
