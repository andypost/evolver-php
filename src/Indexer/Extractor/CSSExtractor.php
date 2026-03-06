<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\TreeSitter\Node;
use DrupalEvolver\TreeSitter\LanguageRegistry;
use DrupalEvolver\TreeSitter\Query;

class CSSExtractor implements ExtractorInterface
{
    private ?Query $query = null;

    public function __construct(private LanguageRegistry $registry) {}

    public function extract(Node $root, string $source, string $filePath): array
    {
        $symbols = [];
        $query = $this->getQuery($root);

        foreach ($query->matches($root, $source) as $captures) {
            $node = $captures['item'] ?? null;
            if (!$node) continue;

            $name = $node->text();

            $symbols[] = [
                'fqn' => $name,
                'name' => $name,
                'symbol_type' => 'css_selector',
                'line_start' => $node->startPoint()['row'] + 1,
                'line_end' => $node->endPoint()['row'] + 1,
                'signature_json' => json_encode(['type' => 'selector']),
                'signature_hash' => hash('sha256', $name),
                'language' => 'css',
            ];
        }

        return $symbols;
    }

    private function getQuery(Node $root): Query
    {
        if ($this->query !== null) {
            return $this->query;
        }

        $lang = $this->registry->loadLanguage('css');
        $pattern = '
            (class_selector) @item
            (id_selector) @item
        ';

        $this->query = new Query($root->binding(), $pattern, $lang);
        return $this->query;
    }
}
