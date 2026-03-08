<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\Adapter\EcosystemAdapterInterface;
use DrupalEvolver\Symbol\SymbolType;
use DrupalEvolver\TreeSitter\Node;

class PHPExtractor implements ExtractorInterface
{
    private string $currentNamespace = '';
    private array $symbols = [];
    private ?\DrupalEvolver\TreeSitter\Query $cachedQuery = null;

    private ?string $currentClassFqn = null;
    private int $currentClassEndByte = -1;

    public function __construct(
        private \DrupalEvolver\TreeSitter\LanguageRegistry $registry,
        private EcosystemAdapterInterface $adapter,
    ) {}

    public function extract(Node $root, string $source, string $filePath, ?string $absolutePath = null): array
    {
        $this->currentNamespace = '';
        $this->currentClassFqn = null;
        $this->currentClassEndByte = -1;
        $this->symbols = [];

        $binding = $root->binding();
        if (!$binding) {
            $root->walk(function (Node $node) use ($source, $filePath) {
                $this->processNode($node, $source, $filePath);
            });
            return $this->symbols;
        }

        if ($this->cachedQuery === null) {
            $queryPattern = '
                (namespace_definition) @namespace
                (function_definition) @function
                (class_declaration) @class
                (interface_declaration) @interface
                (trait_declaration) @trait
                (method_declaration) @method
                (const_declaration) @constant
                (attribute) @attribute
                (function_call_expression) @call
            ';

            $registry = $this->registry ?? new \DrupalEvolver\TreeSitter\LanguageRegistry();
            $this->cachedQuery = new \DrupalEvolver\TreeSitter\Query(
                $binding,
                $queryPattern,
                $registry->loadLanguage('php')
            );
        }

        $matches = $this->cachedQuery->matches($root, $source);

        foreach ($matches as $captures) {
            foreach ($captures as $captureName => $node) {
                // Update context
                if ($this->currentClassFqn !== null && $node->startByte() > $this->currentClassEndByte) {
                    $this->currentClassFqn = null;
                    $this->currentClassEndByte = -1;
                }

                match ($captureName) {
                    'namespace' => $this->extractNamespace($node),
                    'function' => $this->extractFunction($node, $source, $filePath),
                    'class', 'interface', 'trait' => $this->extractClassLike($node, $captureName, $source, $filePath),
                    'method' => $this->extractMethod($node, $source, $filePath),
                    'constant' => $this->extractConstant($node, $source, $filePath),
                    'attribute' => null, // Handled within class context
                    'call' => $this->checkDeprecation($node, $source),
                    default => null,
                };
            }
        }

        return $this->symbols;
    }

    private function processNode(Node $node, string $source, string $filePath): void
    {
        if ($this->currentClassFqn !== null && $node->startByte() > $this->currentClassEndByte) {
            $this->currentClassFqn = null;
            $this->currentClassEndByte = -1;
        }

        $type = $node->type();
        if ($type === 'namespace_definition') {
            $this->extractNamespace($node);
        } elseif ($type === 'function_definition') {
            $this->extractFunction($node, $source, $filePath);
        } elseif (in_array($type, ['class_declaration', 'interface_declaration', 'trait_declaration'], true)) {
            $this->extractClassLike($node, str_replace('_declaration', '', $type), $source, $filePath);
        } elseif ($type === 'method_declaration') {
            $this->extractMethod($node, $source, $filePath);
        } elseif ($type === 'const_declaration') {
            $this->extractConstant($node, $source, $filePath);
        } elseif ($type === 'attribute') {
            $this->extractHook($node, $source, $filePath);
        } elseif ($type === 'function_call_expression') {
            $this->checkDeprecation($node, $source);
        }
    }

    private function extractNamespace(Node $node): void
    {
        $name = $node->childByFieldName('name');
        if ($name) {
            $this->currentNamespace = $name->text();
        }
    }

    private function extractClassLike(Node $node, string $type, string $source, string $filePath): void
    {
        $name = $node->childByFieldName('name');
        if (!$name) {
            return;
        }

        $symbolType = $this->resolveClassLikeSymbolType($type);

        $shortName = $name->text();
        $fqn = $this->currentNamespace ? $this->currentNamespace . '\\' . $shortName : $shortName;

        // Set context for methods/constants
        $this->currentClassFqn = $fqn;
        $this->currentClassEndByte = $node->endByte();

        $parentClass = null;
        $interfacesText = null;

        if ($type === 'class') {
            foreach ($node->namedChildren() as $child) {
                if ($child->type() === 'base_clause') {
                    $parentClass = preg_replace('/^extends\s+/', '', $child->text());
                } elseif ($child->type() === 'class_interface_clause') {
                    $interfacesText = preg_replace('/^implements\s+/', '', $child->text());
                }
            }
        }

        $docblock = $this->findDocblock($node);
        $sigJson = $symbolType === SymbolType::ClassSymbol ? json_encode(['parent' => $parentClass, 'interfaces' => $interfacesText]) : null;
        $hashData = $symbolType === SymbolType::ClassSymbol
            ? SymbolType::ClassSymbol->value . "|{$fqn}|{$parentClass}|{$interfacesText}"
            : "{$symbolType->value}|{$fqn}";

        $symbol = [
            'language' => 'php',
            'symbol_type' => $symbolType->value,
            'fqn' => $fqn,
            'name' => $shortName,
            'namespace' => $this->currentNamespace ?: null,
            'signature_hash' => hash('sha256', $hashData),
            'signature_json' => $sigJson,
            'source_text' => $node->text(),
            'line_start' => $node->startPoint()['row'] + 1,
            'line_end' => $node->endPoint()['row'] + 1,
            'byte_start' => $node->startByte(),
            'byte_end' => $node->endByte(),
            'docblock' => $docblock,
        ];

        $this->applyDeprecationFromDocblock($symbol, $docblock);
        $this->symbols[] = $symbol;

        if ($symbolType === SymbolType::ClassSymbol) {
            $this->checkSpecialDrupalClasses($node, $symbol, $docblock, $interfacesText);
            $this->extractClassAttributes($node, $source, $filePath, $fqn, $this->currentNamespace);
        }
    }

    private function extractClassAttributes(Node $node, string $source, string $filePath, string $parentFqn, string $namespace): void
    {
        $node->walk(function (Node $child) use ($source, $filePath, $parentFqn, $namespace) {
            if ($child->type() === 'attribute') {
                $oldNamespace = $this->currentNamespace;
                $oldClass = $this->currentClassFqn;
                
                $this->currentNamespace = $namespace;
                $this->currentClassFqn = $parentFqn;
                
                $this->extractHook($child, $source, $filePath);
                
                $this->currentNamespace = $oldNamespace;
                $this->currentClassFqn = $oldClass;
            }
            // Skip recursing into bodies of methods or other nested things to avoid noise
            if ($child->type() === 'declaration_list' || $child->type() === 'compound_statement') {
                return false;
            }
        });
    }

    private function checkSpecialDrupalClasses(Node $node, array $symbol, ?string $docblock, ?string $interfaces): void
    {
        if ($docblock) {
            if (preg_match('/@(\w+)\s*\(\s*.*?\bid\s*[:=]\s*["\']([^"\']+)["\']/is', $docblock, $m)) {
                $this->symbols[] = array_merge($symbol, [
                    'symbol_type' => SymbolType::PluginDefinition->value,
                    'fqn' => $m[2],
                    'name' => $m[1],
                    'metadata_json' => json_encode(['plugin_type' => $m[1], 'plugin_id' => $m[2]]),
                ]);
            }
        }

        if ($interfaces && str_contains($interfaces, 'EventSubscriberInterface')) {
            $events = $this->extractSubscribedEvents($node, $symbol['source_text']);
            $this->symbols[] = array_merge($symbol, [
                'symbol_type' => SymbolType::EventSubscriber->value,
                'metadata_json' => json_encode(['events' => $events]),
            ]);
        }
    }

    private function extractSubscribedEvents(Node $node, string $source): array
    {
        $events = [];
        $methodNode = null;
        $node->walk(function (Node $child) use (&$methodNode) {
            if ($child->type() === 'method_declaration') {
                $nameNode = $child->childByFieldName('name');
                if ($nameNode && $nameNode->text() === 'getSubscribedEvents') {
                    $methodNode = $child;
                    return false;
                }
            }
        });

        if ($methodNode) {
            $methodNode->walk(function (Node $n) use (&$events) {
                if ($n->type() === 'string' || $n->type() === 'encapsed_string') {
                    $events[] = trim($n->text(), "\"'");
                } elseif ($n->type() === 'class_constant_access_expression') {
                    $events[] = $n->text();
                }
            });
        }

        return array_values(array_unique(array_filter($events)));
    }

    private function extractFunction(Node $node, string $source, string $filePath): void
    {
        $name = $node->childByFieldName('name');
        if (!$name) {
            return;
        }

        $shortName = $name->text();
        $fqn = $this->currentNamespace ? $this->currentNamespace . '\\' . $shortName : $shortName;
        $params = $this->extractParameters($node);
        $returnType = $this->extractReturnType($node);
        $docblock = $this->findDocblock($node);

        $symbol = [
            'language' => 'php',
            'symbol_type' => SymbolType::FunctionSymbol->value,
            'fqn' => $fqn,
            'name' => $shortName,
            'namespace' => $this->currentNamespace ?: null,
            'signature_json' => json_encode(['params' => $params, 'return_type' => $returnType]),
            'signature_hash' => hash('sha256', SymbolType::FunctionSymbol->value . "|{$fqn}|" . json_encode($params) . "|{$returnType}"),
            'source_text' => $node->text(),
            'line_start' => $node->startPoint()['row'] + 1,
            'line_end' => $node->endPoint()['row'] + 1,
            'byte_start' => $node->startByte(),
            'byte_end' => $node->endByte(),
            'docblock' => $docblock,
        ];

        $this->applyDeprecationFromDocblock($symbol, $docblock);
        $this->symbols[] = $symbol;

        $this->checkProceduralHook($symbol, $filePath);
    }

    private function checkProceduralHook(array $symbol, string $filePath): void
    {
        if (!$this->adapter->isHookFile($filePath) || $symbol['namespace'] !== null) {
            return;
        }

        $hookName = $this->adapter->extractHookName($symbol['name'], $filePath);
        if ($hookName !== null) {
            $this->symbols[] = array_merge($symbol, [
                'symbol_type' => SymbolType::Hook->value,
                'fqn' => $hookName,
                'name' => $hookName,
            ]);
        }
    }

    private function extractMethod(Node $node, string $source, string $filePath): void
    {
        $name = $node->childByFieldName('name');
        if (!$name) {
            return;
        }

        $shortName = $name->text();
        $parentClass = $this->currentClassFqn;
        $fqn = $parentClass ? $parentClass . '::' . $shortName : $shortName;

        $visibility = 'public';
        $isStatic = false;
        foreach ($node->children() as $child) {
            $type = $child->type();
            if (in_array($type, ['public', 'protected', 'private'], true)) {
                $visibility = $type;
            }
            if ($type === 'static') {
                $isStatic = true;
            }
        }

        $params = $this->extractParameters($node);
        $returnType = $this->extractReturnType($node);
        $docblock = $this->findDocblock($node);

        $symbol = [
            'language' => 'php',
            'symbol_type' => SymbolType::Method->value,
            'fqn' => $fqn,
            'name' => $shortName,
            'namespace' => $this->currentNamespace ?: null,
            'parent_symbol' => $parentClass,
            'visibility' => $visibility,
            'is_static' => $isStatic ? 1 : 0,
            'signature_json' => json_encode(['params' => $params, 'return_type' => $returnType]),
            'signature_hash' => hash('sha256', "method|{$fqn}|{$visibility}|" . json_encode($params) . "|{$returnType}"),
            'source_text' => $node->text(),
            'line_start' => $node->startPoint()['row'] + 1,
            'line_end' => $node->endPoint()['row'] + 1,
            'byte_start' => $node->startByte(),
            'byte_end' => $node->endByte(),
            'docblock' => $docblock,
        ];

        $this->applyDeprecationFromDocblock($symbol, $docblock);
        $this->symbols[] = $symbol;
    }

    private function extractConstant(Node $node, string $source, string $filePath): void
    {
        foreach ($node->namedChildren() as $child) {
            if ($child->type() === 'const_element') {
                // const_element doesn't have a 'name' field, the first named child is the name
                $nameNode = $child->namedChild(0);
                if (!$nameNode || $nameNode->type() !== 'name') {
                    continue;
                }

                $shortName = $nameNode->text();
                $parentClass = $this->currentClassFqn;
                $fqn = $parentClass
                    ? $parentClass . '::' . $shortName
                    : ($this->currentNamespace ? $this->currentNamespace . '\\' . $shortName : $shortName);

                $symbolType = SymbolType::Constant;
                if ($parentClass && (str_contains($parentClass, 'Events') || str_contains($parentClass, 'Event'))) {
                    $symbolType = SymbolType::DrupalEvent;
                }

                $this->symbols[] = [
                    'language' => 'php',
                    'symbol_type' => $symbolType->value,
                    'fqn' => $fqn,
                    'name' => $shortName,
                    'namespace' => $this->currentNamespace ?: null,
                    'parent_symbol' => $parentClass,
                    'signature_hash' => hash('sha256', "{$symbolType->value}|{$fqn}"),
                    'source_text' => $node->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function extractHook(Node $node, string $source, string $filePath): void
    {
        $attrNameNode = $node->childByFieldName('name') ?? $node->namedChild(0);
        if (!$attrNameNode) {
            return;
        }

        $attrName = $attrNameNode->text();
        $args = $node->childByFieldName('parameters') ?? $node->namedChild(1);

        if ($attrName === 'Hook' || str_ends_with($attrName, '\\Hook')) {
            if (!$args) return;
            $hookName = '';
            $args->walk(function (Node $n) use (&$hookName) {
                if ($n->type() === 'string' || $n->type() === 'encapsed_string') {
                    if ($hookName === '') $hookName = trim($n->text(), "\"'");
                }
            });

            if ($hookName) {
                $this->symbols[] = [
                    'language' => 'php',
                    'symbol_type' => SymbolType::Hook->value,
                    'fqn' => $hookName,
                    'name' => $hookName,
                    'namespace' => $this->currentNamespace ?: null,
                    'source_text' => $node->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
            return;
        }

        if ($args) {
            $pluginId = null;
            foreach ($args->namedChildren() as $arg) {
                if ($arg->type() === 'argument') {
                    $nameNode = $arg->childByFieldName('name');
                    $valueNode = $arg->childByFieldName('value') ?? $arg->namedChild(1);
                    if ($nameNode && $nameNode->text() === 'id') {
                        if ($valueNode && ($valueNode->type() === 'string' || $valueNode->type() === 'encapsed_string')) {
                            $pluginId = trim($valueNode->text(), "\"'");
                        }
                        break;
                    }
                    if (!$nameNode && $valueNode && ($valueNode->type() === 'string' || $valueNode->type() === 'encapsed_string')) {
                        if ($pluginId === null) {
                            $pluginId = trim($valueNode->text(), "\"'");
                        }
                    }
                }
            }

            if ($pluginId) {
                $this->symbols[] = [
                    'language' => 'php',
                    'symbol_type' => SymbolType::PluginDefinition->value,
                    'fqn' => $pluginId,
                    'name' => $attrName,
                    'namespace' => $this->currentNamespace ?: null,
                    'metadata_json' => json_encode(['plugin_type' => $attrName, 'plugin_id' => $pluginId]),
                    'source_text' => $node->text(),
                    'line_start' => $node->startPoint()['row'] + 1,
                    'line_end' => $node->endPoint()['row'] + 1,
                    'byte_start' => $node->startByte(),
                    'byte_end' => $node->endByte(),
                ];
            }
        }
    }

    private function checkDeprecation(Node $node, string $source): void
    {
        $fn = $node->childByFieldName('function');
        if (!$fn) return;

        $fnText = $fn->text();
        if (!in_array($fnText, ['trigger_error', '@trigger_error', 'trigger_deprecation'], true)) return;

        $args = $node->childByFieldName('arguments');
        if (!$args) return;

        $argText = $args->text();
        if ($fnText === 'trigger_deprecation') {
            $depVersion = preg_match("/,\s*['\"](\d+\.\d+)['\"],/", $argText, $m) ? $m[1] : null;
            $remVersion = null;
        } else {
            $depVersion = preg_match('/is deprecated in drupal:(\d+\.\d+\.\d+)/', $argText, $m) ? $m[1] : null;
            $remVersion = preg_match('/is removed from drupal:(\d+\.\d+\.\d+)/', $argText, $m) ? $m[1] : null;
        }
        $hint = preg_match('/Use (.+?) instead/', $argText, $m) ? trim($m[1]) : null;

        if (!empty($this->symbols)) {
            $lastSymbol = &$this->symbols[count($this->symbols) - 1];
            $lastSymbol['is_deprecated'] = 1;
            $lastSymbol['deprecation_message'] = $argText;
            $lastSymbol['deprecation_version'] = $depVersion;
            $lastSymbol['removal_version'] = $remVersion;
            if ($hint) $lastSymbol['metadata_json'] = json_encode(['replacement_hint' => $hint]);
        }
    }

    private function extractParameters(Node $node): array
    {
        $params = [];
        $paramList = $node->childByFieldName('parameters');
        if (!$paramList) return $params;

        foreach ($paramList->namedChildren() as $param) {
            if (!in_array($param->type(), ['simple_parameter', 'variadic_parameter', 'property_promotion_parameter'], true)) continue;
            $paramData = ['name' => '', 'type' => null, 'default' => null];
            if ($n = $param->childByFieldName('name')) $paramData['name'] = $n->text();
            if ($n = $param->childByFieldName('type')) $paramData['type'] = $n->text();
            if ($n = $param->childByFieldName('default_value')) $paramData['default'] = $n->text();
            $params[] = $paramData;
        }
        return $params;
    }

    private function extractReturnType(Node $node): ?string
    {
        $returnType = $node->childByFieldName('return_type');
        return $returnType ? $returnType->text() : null;
    }

    private function resolveClassLikeSymbolType(string $type): SymbolType
    {
        return match ($type) {
            'class' => SymbolType::ClassSymbol,
            'interface' => SymbolType::InterfaceSymbol,
            'trait' => SymbolType::TraitSymbol,
            default => throw new \InvalidArgumentException("Unsupported class-like symbol type: {$type}"),
        };
    }

    private function findDocblock(Node $node): ?string
    {
        $curr = $node->prevSibling();
        while ($curr) {
            if ($curr->type() === 'comment' && str_starts_with($curr->text(), '/**')) return $curr->text();
            if ($curr->isNamed()) break;
            $curr = $curr->prevSibling();
        }
        return null;
    }

    private function applyDeprecationFromDocblock(array &$symbol, ?string $docblock): void
    {
        if (!$docblock || !str_contains($docblock, '@deprecated')) return;
        $symbol['is_deprecated'] = 1;
        if (preg_match('/@deprecated in drupal:(\d+\.\d+\.\d+)/', $docblock, $match)) $symbol['deprecation_version'] = $match[1];
        if (preg_match('/@deprecated since Symfony (\d+\.\d+)/', $docblock, $match)) $symbol['deprecation_version'] = $match[1];
        if (preg_match('/removed from drupal:(\d+\.\d+\.\d+)/', $docblock, $match)) $symbol['removal_version'] = $match[1];
        if (preg_match('/Use (.+?) instead/', $docblock, $match)) $symbol['deprecation_message'] = trim($match[1]);
    }
}
