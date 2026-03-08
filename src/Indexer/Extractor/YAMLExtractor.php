<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\TreeSitter\FFIBinding;
use DrupalEvolver\TreeSitter\Node;

class YAMLExtractor implements ExtractorInterface
{
    private array $symbols = [];
    private DrupalYamlSemanticExtractor $semanticExtractor;

    public function __construct(
        private ?\DrupalEvolver\TreeSitter\LanguageRegistry $registry = null
    ) {
        $this->semanticExtractor = new DrupalYamlSemanticExtractor();
    }

    public function extract(Node $root, string $source, string $filePath, ?string $absolutePath = null): array
    {
        $this->symbols = [];
        $basename = basename($filePath);

        $semanticSymbols = $this->semanticExtractor->extract($source, $filePath, $absolutePath);
        if ($semanticSymbols !== null) {
            return $semanticSymbols;
        }

        $binding = $root->binding();
        if ($binding === null) {
            return $this->symbols;
        }

        if (str_ends_with($basename, '.services.yml')) {
            $this->extractServices($root, $source, $filePath, $binding);
        } elseif (str_ends_with($basename, '.routing.yml')) {
            $this->extractRoutes($root, $source, $filePath, $binding);
        } elseif (str_ends_with($basename, '.permissions.yml')) {
            $this->extractPermissions($root, $source, $filePath, $binding);
        } elseif (preg_match('/^(.+)\.libraries\.ya?ml$/', $basename, $matches)) {
            $this->extractLinks($root, $source, $filePath, $matches[1], $binding);
        } elseif (str_ends_with($basename, '.schema.yml')) {
            $this->extractSchema($root, $source, $filePath, $binding);
        }

        return $this->symbols;
    }

    private function extractLinks(Node $root, string $source, string $filePath, string $extension, FFIBinding $binding): void
    {
        $pattern = '(document (block_node (block_mapping (block_mapping_pair key: (flow_node) @key value: (_) @value))))';
        $registry = $this->registry ?? new \DrupalEvolver\TreeSitter\LanguageRegistry();
        $query = new \DrupalEvolver\TreeSitter\Query($binding, $pattern, $registry->loadLanguage('yaml'));
        $matches = $query->matches($root, $source);

        foreach ($matches as $captures) {
            if (isset($captures['key']) && isset($captures['value'])) {
                $node = $captures['key'];
                $keyText = trim($node->text());
                $valNode = $captures['value'];

                $assetPaths = [];
                $valNode->walk(function (Node $n) use (&$assetPaths) {
                    if ($n->type() === 'block_mapping_pair') {
                        $k = $n->childByFieldName('key');
                        if ($k && (str_ends_with($k->text(), '.css') || str_ends_with($k->text(), '.js'))) {
                            $assetPaths[] = trim($k->text(), "\"'");
                        }
                    }
                });

                $this->symbols[] = [
                    'language' => 'yaml',
                    'symbol_type' => 'drupal_library',
                    'fqn' => $keyText,
                    'name' => $keyText,
                    'signature_hash' => hash('sha256', "library|{$keyText}"),
                    'metadata_json' => json_encode(['asset_paths' => array_values(array_unique($assetPaths))]),
                    'source_text' => $source,
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function extractSchema(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        $pattern = '(document (block_node (block_mapping (block_mapping_pair key: (flow_node) @key))))';
        $registry = $this->registry ?? new \DrupalEvolver\TreeSitter\LanguageRegistry();
        $query = new \DrupalEvolver\TreeSitter\Query($binding, $pattern, $registry->loadLanguage('yaml'));
        $matches = $query->matches($root, $source);

        foreach ($matches as $captures) {
            if (isset($captures['key'])) {
                $node = $captures['key'];
                $keyText = trim($node->text());

                $this->symbols[] = [
                    'language' => 'yaml',
                    'symbol_type' => 'config_schema',
                    'fqn' => $keyText,
                    'name' => $keyText,
                    'signature_hash' => hash('sha256', "schema|{$keyText}"),
                    'source_text' => $node->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function extractServices(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        // Restrict to direct children of 'services' key
        $pattern = '(document (block_node (block_mapping (block_mapping_pair key: (flow_node) @sk (#eq? @sk "services") value: (block_node (block_mapping (block_mapping_pair key: (flow_node) @name value: (block_node) @props)))))))';
        $registry = $this->registry ?? new \DrupalEvolver\TreeSitter\LanguageRegistry();
        $query = new \DrupalEvolver\TreeSitter\Query($binding, $pattern, $registry->loadLanguage('yaml'));
        $matches = $query->matches($root, $source);

        foreach ($matches as $captures) {
            if (isset($captures['name'])) {
                $node = $captures['name'];
                $keyText = trim($node->text());
                if (in_array($keyText, ['_defaults', 'parameters'], true)) continue;

                $serviceData = ['class' => null, 'arguments' => null, 'tags' => null];
                if (isset($captures['props'])) {
                    $this->extractServiceProps($captures['props'], $serviceData);
                }

                $this->symbols[] = [
                    'language' => 'yaml',
                    'symbol_type' => 'service',
                    'fqn' => $keyText,
                    'name' => $keyText,
                    'signature_hash' => hash('sha256', "service|{$keyText}|" . json_encode($serviceData)),
                    'metadata_json' => json_encode($serviceData),
                    'source_text' => $captures['props']->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function extractServiceProps(Node $props, array &$serviceData): void
    {
        foreach ($props->namedChildren() as $map) {
            if ($map->type() !== 'block_mapping') continue;
            foreach ($map->namedChildren() as $pair) {
                if ($pair->type() !== 'block_mapping_pair') continue;
                $pk = $pair->childByFieldName('key');
                $pv = $pair->childByFieldName('value');
                if (!$pk || !$pv) continue;

                match (trim($pk->text())) {
                    'class' => $serviceData['class'] = trim($pv->text()),
                    'arguments' => $serviceData['arguments'] = trim($pv->text()),
                    'tags' => $serviceData['tags'] = trim($pv->text()),
                    default => null,
                };
            }
        }
    }

    private function extractRoutes(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        // Match top-level route entries
        $pattern = '(document (block_node (block_mapping (block_mapping_pair key: (flow_node) @name value: (block_node) @props))))';
        $registry = $this->registry ?? new \DrupalEvolver\TreeSitter\LanguageRegistry();
        $query = new \DrupalEvolver\TreeSitter\Query($binding, $pattern, $registry->loadLanguage('yaml'));
        $matches = $query->matches($root, $source);

        foreach ($matches as $captures) {
            if (isset($captures['name']) && isset($captures['props'])) {
                $node = $captures['name'];
                $routeName = trim($node->text());
                $routeData = ['path' => null, 'defaults' => null, 'requirements' => null, 'options' => null, 'controller' => null];
                $this->extractRouteProps($captures['props'], $routeData);

                $this->symbols[] = [
                    'language' => 'yaml',
                    'symbol_type' => 'drupal_route',
                    'fqn' => $routeName,
                    'name' => $routeName,
                    'signature_hash' => hash('sha256', "route|{$routeName}|" . json_encode($routeData)),
                    'metadata_json' => json_encode($routeData),
                    'source_text' => $captures['props']->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function extractRouteProps(Node $props, array &$routeData): void
    {
        foreach ($props->namedChildren() as $map) {
            if ($map->type() !== 'block_mapping') continue;
            foreach ($map->namedChildren() as $pair) {
                if ($pair->type() !== 'block_mapping_pair') continue;
                $pk = $pair->childByFieldName('key');
                $pv = $pair->childByFieldName('value');
                if (!$pk || !$pv) continue;

                $key = trim($pk->text());
                $value = trim($pv->text(), '"\'');

                match ($key) {
                    'path' => $routeData['path'] = $value,
                    'defaults' => $routeData['defaults'] = $value,
                    'requirements' => $routeData['requirements'] = $value,
                    'options' => $routeData['options'] = $value,
                    'methods' => $routeData['methods'] = $value,
                    default => null,
                };

                // Extract controller from defaults if present (e.g., 'Drupal\example\Controller\ExampleController::method')
                if ($key === 'defaults' && str_contains($value, '::')) {
                    $routeData['controller'] = $value;
                }
            }
        }
    }

    private function extractPermissions(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        // Match top-level permission entries
        $pattern = '(document (block_node (block_mapping (block_mapping_pair key: (flow_node) @name value: (block_node) @props))))';
        $registry = $this->registry ?? new \DrupalEvolver\TreeSitter\LanguageRegistry();
        $query = new \DrupalEvolver\TreeSitter\Query($binding, $pattern, $registry->loadLanguage('yaml'));
        $matches = $query->matches($root, $source);

        foreach ($matches as $captures) {
            if (isset($captures['name']) && isset($captures['props'])) {
                $node = $captures['name'];
                $permId = trim($node->text());
                $permData = ['title' => null, 'description' => null, 'restrict_access' => null];
                $this->extractPermissionProps($captures['props'], $permData);

                $this->symbols[] = [
                    'language' => 'yaml',
                    'symbol_type' => 'drupal_permission',
                    'fqn' => $permId,
                    'name' => $permId,
                    'signature_hash' => hash('sha256', "permission|{$permId}|" . json_encode($permData)),
                    'metadata_json' => json_encode($permData),
                    'source_text' => $captures['props']->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function extractPermissionProps(Node $props, array &$permData): void
    {
        foreach ($props->namedChildren() as $map) {
            if ($map->type() !== 'block_mapping') continue;
            foreach ($map->namedChildren() as $pair) {
                if ($pair->type() !== 'block_mapping_pair') continue;
                $pk = $pair->childByFieldName('key');
                $pv = $pair->childByFieldName('value');
                if (!$pk || !$pv) continue;

                $key = trim($pk->text());
                $value = trim($pv->text(), '"\'');

                match ($key) {
                    'title', 'label' => $permData['title'] = $value,
                    'description' => $permData['description'] = $value,
                    'restrict access' => $permData['restrict_access'] = $value === 'true',
                    default => null,
                };
            }
        }
    }
}
