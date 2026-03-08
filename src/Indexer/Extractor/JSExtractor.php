<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\TreeSitter\Node;
use DrupalEvolver\TreeSitter\LanguageRegistry;
use DrupalEvolver\TreeSitter\Query;

class JSExtractor implements ExtractorInterface
{
    private ?Query $query = null;

    public function __construct(private LanguageRegistry $registry) {}

    public function extract(Node $root, string $source, string $filePath, ?string $absolutePath = null): array
    {
        $symbols = [];
        $query = $this->getQuery($root);

        foreach ($query->matches($root, $source) as $captures) {
            $node = $captures['item'] ?? null;
            if (!$node) continue;

            $type = $node->type();
            $nameNode = $captures['name'] ?? null;
            $name = $nameNode ? $nameNode->text() : 'anonymous';

            $symbolType = match($type) {
                'function_declaration' => 'function',
                'variable_declarator' => 'variable',
                'class_declaration' => 'class',
                'method_definition' => 'method',
                default => 'js_symbol',
            };

            $symbols[] = [
                'fqn' => $name,
                'name' => $name,
                'symbol_type' => $symbolType,
                'line_start' => $node->startPoint()['row'] + 1,
                'line_end' => $node->endPoint()['row'] + 1,
                'signature_json' => json_encode(['type' => $type]),
                'signature_hash' => hash('sha256', $node->text()),
                'language' => 'javascript',
            ];
        }

        return $symbols;
    }

    private function getQuery(Node $root): Query
    {
        if ($this->query !== null) {
            return $this->query;
        }

        $lang = $this->registry->loadLanguage('javascript');
        $pattern = '
            (function_declaration name: (identifier) @name) @item
            (variable_declarator name: (identifier) @name) @item
            (class_declaration name: (identifier) @name) @item
            (method_definition name: (property_identifier) @name) @item
        ';

        $this->query = new Query($root->binding(), $pattern, $lang);
        return $this->query;
    }
}
