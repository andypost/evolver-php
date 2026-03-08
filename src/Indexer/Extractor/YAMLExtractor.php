<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\TreeSitter\FFIBinding;
use DrupalEvolver\TreeSitter\Node;

class YAMLExtractor implements ExtractorInterface
{
    private ?\DrupalEvolver\TreeSitter\Query $cachedQuery = null;
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
        if (!$binding) {
            return []; // Should not happen in hot engine
        }

        if (str_ends_with($basename, '.services.yml')) {
            $this->extractServices($root, $source, $filePath, $binding);
        } elseif (str_ends_with($basename, '.routing.yml')) {
            $this->extractRoutes($root, $source, $filePath, $binding);
        } elseif (str_ends_with($basename, '.permissions.yml')) {
            $this->extractPermissions($root, $source, $filePath, $binding);
        } elseif (str_ends_with($basename, '.info.yml')) {
            $this->extractInfo($root, $source, $filePath, $binding);
        } elseif (str_ends_with($basename, '.libraries.yml')) {
            $this->extractLibraries($root, $source, $filePath, $binding);
        } elseif (preg_match('/\.links\.(menu|task|action|contextual)\.yml$/', $basename, $matches)) {
            $this->extractLinks($root, $source, $filePath, $matches[1], $binding);
        } elseif (str_ends_with($basename, '.schema.yml')) {
            $this->extractSchema($root, $source, $filePath, $binding);
        } elseif (str_ends_with($basename, '.breakpoints.yml')) {
            $this->extractBreakpoints($root, $source, $filePath, $binding);
        }

        return $this->symbols;
    }

    private function getTopLevelQuery(FFIBinding $binding): \DrupalEvolver\TreeSitter\Query
    {
        if ($this->cachedQuery === null) {
            $pattern = '(document (block_node (block_mapping (block_mapping_pair key: (flow_node) @key value: (_) @val))))';
            $registry = $this->registry ?? new \DrupalEvolver\TreeSitter\LanguageRegistry();
            $this->cachedQuery = new \DrupalEvolver\TreeSitter\Query(
                $binding,
                $pattern,
                $registry->loadLanguage('yaml')
            );
        }
        return $this->cachedQuery;
    }

    private function extractTopLevelKeys(Node $root, string $source, string $filePath, string $symbolType, FFIBinding $binding): void
    {
        $query = $this->getTopLevelQuery($binding);
        $matches = $query->matches($root, $source);
        
        foreach ($matches as $captures) {
            if (isset($captures['key'])) {
                $node = $captures['key'];
                $name = trim($node->text());
                $this->symbols[] = [
                    'language' => 'yaml',
                    'symbol_type' => $symbolType,
                    'fqn' => $name,
                    'name' => $name,
                    'signature_hash' => hash('sha256', "{$symbolType}|{$name}"),
                    'source_text' => $node->parent()->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function extractInfo(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        $query = $this->getTopLevelQuery($binding);
        $matches = $query->matches($root, $source);
        
        $moduleData = [];
        foreach ($matches as $captures) {
            if (isset($captures['key'], $captures['val'])) {
                $moduleData[trim($captures['key']->text())] = trim($captures['val']->text());
            }
        }

        if (!empty($moduleData)) {
            $type = $moduleData['type'] ?? 'module';
            $symbolType = ($type === 'theme') ? 'theme_info' : 'module_info';
            
            $this->symbols[] = [
                'language' => 'yaml',
                'symbol_type' => $symbolType,
                'fqn' => $moduleData['name'] ?? basename($filePath, '.info.yml'),
                'name' => $moduleData['name'] ?? basename($filePath, '.info.yml'),
                'signature_hash' => hash('sha256', "info|" . ($moduleData['name'] ?? '') . "|" . ($moduleData['type'] ?? '')),
                'signature_json' => json_encode($moduleData),
                'source_text' => $source,
                'line_start' => 1,
                'line_end' => substr_count($source, "\n") + 1,
                'byte_start' => 0,
                'byte_end' => strlen($source),
            ];
        }
    }

    private function extractServices(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        $pattern = '(block_mapping_pair key: (flow_node) @sk (#eq? @sk "services") value: (block_node (block_mapping (block_mapping_pair key: (flow_node) @name value: (block_node) @props))))';
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
                    'signature_hash' => hash('sha256', "service|{$keyText}|" . ($serviceData['class'] ?? '')),
                    'signature_json' => json_encode($serviceData),
                    'source_text' => $node->parent()->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function extractRoutes(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        $query = $this->getTopLevelQuery($binding);
        $matches = $query->matches($root, $source);

        foreach ($matches as $captures) {
            if (isset($captures['key'])) {
                $node = $captures['key'];
                $keyText = trim($node->text());
                if (in_array($keyText, ['services', 'parameters', '_defaults'], true)) continue;

                $routeData = ['path' => null, 'controller' => null];
                if (isset($captures['val'])) {
                    $captures['val']->walk(function (Node $child) use (&$routeData) {
                        if ($child->type() !== 'block_mapping_pair') return;
                        $ck = $child->childByFieldName('key');
                        $cv = $child->childByFieldName('value');
                        if ($ck && $cv) {
                            $propName = trim($ck->text());
                            if ($propName === 'path') $routeData['path'] = trim($cv->text());
                            elseif ($propName === '_controller' || $propName === '_form') $routeData['controller'] = trim($cv->text());
                        }
                    });
                }

                $this->symbols[] = [
                    'language' => 'yaml',
                    'symbol_type' => 'route',
                    'fqn' => $keyText,
                    'name' => $keyText,
                    'signature_hash' => hash('sha256', "route|{$keyText}|{$routeData['path']}|{$routeData['controller']}"),
                    'signature_json' => json_encode($routeData),
                    'source_text' => $node->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function extractBreakpoints(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        $this->extractTopLevelKeys($root, $source, $filePath, 'breakpoint', $binding);
    }

    private function extractSchema(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        $this->extractTopLevelKeys($root, $source, $filePath, 'config_schema', $binding);
    }

    private function extractLibraries(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        $this->extractTopLevelKeys($root, $source, $filePath, 'library', $binding);
    }

    private function extractLinks(Node $root, string $source, string $filePath, string $type, FFIBinding $binding): void
    {
        $this->extractTopLevelKeys($root, $source, $filePath, "link_{$type}", $binding);
    }

    private function extractPermissions(Node $root, string $source, string $filePath, FFIBinding $binding): void
    {
        $this->extractTopLevelKeys($root, $source, $filePath, 'permission', $binding);
    }

    private function extractServiceProps(Node $valNode, array &$serviceData): void
    {
        // Try to find the block_mapping inside the valNode
        $mapping = null;
        if ($valNode->type() === 'block_mapping') {
            $mapping = $valNode;
        } else {
            foreach ($valNode->children() as $child) {
                if ($child->type() === 'block_mapping') {
                    $mapping = $child;
                    break;
                }
                if ($child->type() === 'block_node') {
                    $m = $child->namedChild(0);
                    if ($m && $m->type() === 'block_mapping') {
                        $mapping = $m;
                        break;
                    }
                }
            }
        }

        if (!$mapping) return;

        foreach ($mapping->namedChildren() as $prop) {
            if ($prop->type() !== 'block_mapping_pair') continue;
            $pk = $prop->childByFieldName('key');
            $pv = $prop->childByFieldName('value');
            if ($pk && $pv) {
                $propName = trim($pk->text());
                match ($propName) {
                    'class' => $serviceData['class'] = trim($pv->text()),
                    'arguments' => $serviceData['arguments'] = trim($pv->text()),
                    'tags' => $serviceData['tags'] = trim($pv->text()),
                    default => null,
                };
            }
        }
    }
}
