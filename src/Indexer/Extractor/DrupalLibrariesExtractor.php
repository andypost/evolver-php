<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\TreeSitter\Node;
use DrupalEvolver\TreeSitter\LanguageRegistry;
use DrupalEvolver\TreeSitter\Query;

class DrupalLibrariesExtractor implements ExtractorInterface
{
    private ?Query $query = null;

    public function __construct(private LanguageRegistry $registry) {}

    public function extract(Node $root, string $source, string $filePath): array
    {
        $symbols = [];
        $query = $this->getQuery($root);

        foreach ($query->matches($root, $source) as $captures) {
            $keyNode = $captures['key'] ?? null;
            $valNode = $captures['value'] ?? null;
            if (!$keyNode || !$valNode) continue;

            $libraryName = trim($keyNode->text(), "'\" ");
            $symbol = [
                'fqn' => $libraryName,
                'name' => $libraryName,
                'symbol_type' => 'drupal_library',
                'line_start' => $keyNode->startPoint()['row'] + 1,
                'line_end' => $valNode->endPoint()['row'] + 1,
                'signature_json' => json_encode(['type' => 'library']),
                'signature_hash' => hash('sha256', $libraryName),
                'language' => 'drupal_libraries',
            ];

            // Look for 'deprecated' key inside the library definition
            // We traverse the block_mapping manually for reliability
            if ($valNode->type() === 'block_node') {
                $mapping = $valNode->namedChild(0);
                if ($mapping && $mapping->type() === 'block_mapping') {
                    foreach ($mapping->namedChildren() as $pair) {
                        if ($pair->type() === 'block_mapping_pair') {
                            $k = $pair->childByFieldName('key');
                            $v = $pair->childByFieldName('value');
                            if ($k && $v) {
                                $keyText = trim($k->text(), "'\" ");
                                if ($keyText === 'deprecated' || $keyText === 'moved_files') {
                                    if ($keyText === 'deprecated') {
                                        $msg = trim($v->text(), "'\" ");
                                        $symbol['is_deprecated'] = 1;
                                        $symbol['deprecation_message'] = $msg;
                                    } else {
                                        // moved_files is a mapping, check if it has entries
                                        $symbol['is_deprecated'] = 1;
                                        $symbol['deprecation_message'] = "Library moved or has moved files: " . trim($v->text(), "'\" ");
                                    }
                                    
                                    $msgForRegex = $symbol['deprecation_message'];
                                    if (preg_match('/deprecated in drupal:(\d+\.\d+\.\d+)/', $msgForRegex, $m)) {
                                        $symbol['deprecation_version'] = $m[1];
                                    }
                                    if (preg_match('/removed from drupal:(\d+\.\d+\.\d+)/', $msgForRegex, $m)) {
                                        $symbol['removal_version'] = $m[1];
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $symbols[] = $symbol;
        }

        return $symbols;
    }

    private function getQuery(Node $root): Query
    {
        if ($this->query !== null) {
            return $this->query;
        }

        $lang = $this->registry->loadLanguage('drupal_libraries');
        // Capture library key and its definition block at the root level
        $pattern = '(document (block_node (block_mapping (block_mapping_pair key: (flow_node) @key value: (_) @value))))';

        $this->query = new Query($root->binding(), $pattern, $lang);
        return $this->query;
    }
}
