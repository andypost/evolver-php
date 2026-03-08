<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\Adapter\EcosystemAdapterInterface;
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
                    'class', 'interface', 'trait' => $this->extractClassLike($node, $captureName),
                    'method' => $this->extractMethod($node, $source, $filePath),
                    'constant' => $this->extractConstant($node, $source, $filePath),
                    'attribute' => $this->extractHook($node, $source, $filePath),
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
            $this->extractClassLike($node, str_replace('_declaration', '', $type));
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

    private function extractClassLike(Node $node, string $type): void
    {
        $name = $node->childByFieldName('name');
        if (!$name) {
            return;
        }

        $shortName = $name->text();
        $fqn = $this->currentNamespace ? $this->currentNamespace . '\\' . $shortName : $shortName;

        // Set context for methods/constants
        $this->currentClassFqn = $fqn;
        $this->currentClassEndByte = $node->endByte();

        $parentClass = null;
        $interfaces = null;

        if ($type === 'class') {
            $baseClause = $node->childByFieldName('base_clause');
            if ($baseClause) {
                $parentClass = preg_replace('/^extends\s+/', '', $baseClause->text());
            }
            $implementsClause = $node->childByFieldName('interfaces');
            if ($implementsClause) {
                $interfaces = preg_replace('/^implements\s+/', '', $implementsClause->text());
            }
        }

        $docblock = $this->findDocblock($node);
        $sigJson = $type === 'class' ? json_encode(['parent' => $parentClass, 'interfaces' => $interfaces]) : null;
        $hashData = $type === 'class' ? "class|{$fqn}|{$parentClass}|{$interfaces}" : "{$type}|{$fqn}";

        $symbol = [
            'language' => 'php',
            'symbol_type' => $type,
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
            'symbol_type' => 'function',
            'fqn' => $fqn,
            'name' => $shortName,
            'namespace' => $this->currentNamespace ?: null,
            'signature_json' => json_encode(['params' => $params, 'return_type' => $returnType]),
            'signature_hash' => hash('sha256', "function|{$fqn}|" . json_encode($params) . "|{$returnType}"),
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
        if (!$this->adapter->isHookFile($filePath)) {
            return;
        }

        if ($symbol['namespace'] !== null) {
            return;
        }

        $hookName = $this->adapter->extractHookName($symbol['name'], $filePath);
        if ($hookName !== null) {
            $this->symbols[] = array_merge($symbol, [
                'symbol_type' => 'hook',
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
            'symbol_type' => 'method',
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
                $name = $child->childByFieldName('name');
                if (!$name) {
                    continue;
                }

                $shortName = $name->text();
                $parentClass = $this->currentClassFqn;
                $fqn = $parentClass
                    ? $parentClass . '::' . $shortName
                    : ($this->currentNamespace ? $this->currentNamespace . '\\' . $shortName : $shortName);

                $this->symbols[] = [
                    'language' => 'php',
                    'symbol_type' => 'constant',
                    'fqn' => $fqn,
                    'name' => $shortName,
                    'namespace' => $this->currentNamespace ?: null,
                    'parent_symbol' => $parentClass,
                    'signature_hash' => hash('sha256', "constant|{$fqn}"),
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
        $name = $node->namedChild(0);
        if (!$name || $name->text() !== 'Hook') {
            return;
        }

        $args = $node->childByFieldName('parameters');
        if (!$args) {
            return;
        }

        $hookName = '';
        foreach ($args->namedChildren() as $arg) {
            if ($arg->type() === 'argument') {
                $val = $arg->namedChild(0);
                if ($val && $val->type() === 'string') {
                    $hookName = trim($val->text(), "'\"");
                } else {
                    $hookName = trim($arg->text(), "'\"");
                }
                break;
            }
        }

        if (!$hookName) {
            return;
        }

        $this->symbols[] = [
            'language' => 'php',
            'symbol_type' => 'hook',
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

    private function checkDeprecation(Node $node, string $source): void
    {
        $fn = $node->childByFieldName('function');
        if (!$fn) {
            return;
        }

        $fnText = $fn->text();
        if (!in_array($fnText, ['trigger_error', '@trigger_error', 'trigger_deprecation'], true)) {
            return;
        }

        $args = $node->childByFieldName('arguments');
        if (!$args) {
            return;
        }

        $argText = $args->text();

        if ($fnText === 'trigger_deprecation') {
            // Symfony: trigger_deprecation('symfony/pkg', '7.1', 'The "%s" ... is deprecated')
            $depVersion = preg_match("/,\s*['\"](\d+\.\d+)['\"],/", $argText, $m) ? $m[1] : null;
            $remVersion = null;
        } else {
            // Drupal: trigger_error('... is deprecated in drupal:X.Y.Z and is removed from drupal:X.Y.Z ...')
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
            if ($hint) {
                $lastSymbol['metadata_json'] = json_encode(['replacement_hint' => $hint]);
            }
        }
    }

    private function extractParameters(Node $node): array
    {
        $params = [];
        $paramList = $node->childByFieldName('parameters');
        if (!$paramList) {
            return $params;
        }

        foreach ($paramList->namedChildren() as $param) {
            if ($param->type() !== 'simple_parameter' && $param->type() !== 'variadic_parameter' && $param->type() !== 'property_promotion_parameter') {
                continue;
            }

            $paramData = ['name' => '', 'type' => null, 'default' => null];
            $nameNode = $param->childByFieldName('name');
            if ($nameNode) $paramData['name'] = $nameNode->text();

            $typeNode = $param->childByFieldName('type');
            if ($typeNode) $paramData['type'] = $typeNode->text();

            $defaultNode = $param->childByFieldName('default_value');
            if ($defaultNode) $paramData['default'] = $defaultNode->text();

            $params[] = $paramData;
        }

        return $params;
    }

    private function extractReturnType(Node $node): ?string
    {
        $returnType = $node->childByFieldName('return_type');
        return $returnType ? $returnType->text() : null;
    }

    private function findDocblock(Node $node): ?string
    {
        $prev = $node->prevSibling();
        if ($prev && $prev->type() === 'comment') {
            $text = $prev->text();
            if (str_starts_with($text, '/**')) {
                return $text;
            }
        }
        return null;
    }

    private function applyDeprecationFromDocblock(array &$symbol, ?string $docblock): void
    {
        if (!$docblock || !str_contains($docblock, '@deprecated')) {
            return;
        }

        $symbol['is_deprecated'] = 1;
        if (preg_match('/@deprecated in drupal:(\d+\.\d+\.\d+)/', $docblock, $match)) $symbol['deprecation_version'] = $match[1];
        if (preg_match('/@deprecated since Symfony (\d+\.\d+)/', $docblock, $match)) $symbol['deprecation_version'] = $match[1];
        if (preg_match('/removed from drupal:(\d+\.\d+\.\d+)/', $docblock, $match)) $symbol['removal_version'] = $match[1];
        if (preg_match('/Use (.+?) instead/', $docblock, $match)) $symbol['deprecation_message'] = trim($match[1]);
    }
}
