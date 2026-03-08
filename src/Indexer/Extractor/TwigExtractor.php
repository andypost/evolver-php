<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer\Extractor;

use DrupalEvolver\Symbol\SymbolType;
use DrupalEvolver\TreeSitter\Node;
use DrupalEvolver\TreeSitter\LanguageRegistry;
use DrupalEvolver\TreeSitter\Query;

class TwigExtractor implements ExtractorInterface
{
    private ?Query $query = null;

    public function __construct(private LanguageRegistry $registry) {}

    public function extract(Node $root, string $source, string $filePath, ?string $absolutePath = null): array
    {
        $symbols = [];
        $query = $this->getQuery($root);

        $matches = $query->matches($root, $source);
        foreach ($matches as $captures) {
            $node = $captures['item'] ?? null;
            if (!$node) continue;

            $type = $node->type();
            $name = 'anonymous';
            $symbolType = SymbolType::TwigSymbol;

            if ($type === 'tag_statement') {
                foreach ($node->namedChildren() as $child) {
                    if ($child->type() === 'tag') {
                        $symbolType = $this->resolveTagType($child);
                    } elseif ($child->type() === 'string' || $child->type() === 'interpolated_string') {
                        $name = trim($child->text(), " '\"");
                    } elseif ($child->type() === 'variable' && $name === 'anonymous') {
                        $name = $child->text();
                    }
                }
                
                // Refine SDC references
                if (str_contains($name, ':')) {
                    $symbolType = match($symbolType) {
                        SymbolType::TwigInclude => SymbolType::SdcInclude,
                        SymbolType::TwigEmbed => SymbolType::SdcEmbed,
                        SymbolType::TwigComponent => SymbolType::SdcCall,
                        default => $symbolType,
                    };
                }
            } elseif ($type === 'output_directive') {
                $symbolType = SymbolType::TwigVariable;
                foreach ($node->namedChildren() as $child) {
                    if ($child->type() === 'variable') {
                        $name = $child->text();
                        break;
                    }
                }
            } elseif ($type === 'filter') {
                $symbolType = SymbolType::TwigFilter;
                foreach ($node->namedChildren() as $child) {
                    if ($child->type() === 'filter_identifier') {
                        $name = $child->text();
                        break;
                    }
                }
            } elseif ($type === 'function_call') {
                $symbolType = SymbolType::TwigFunction;
                foreach ($node->namedChildren() as $child) {
                    if ($child->type() === 'function_identifier') {
                        $name = $child->text();
                        break;
                    }
                }
                if ($name === 'component') {
                    // Try to find the component ID in arguments
                    foreach ($node->namedChildren() as $child) {
                        if ($child->type() === 'arguments') {
                            foreach ($child->namedChildren() as $arg) {
                                // Sometimes it is (argument (string))
                                // Let's just find the first string/interpolated_string inside arguments
                                $found = $this->findFirstString($arg);
                                if ($found) {
                                    $name = $found;
                                    $symbolType = SymbolType::SdcFunction;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }

            $symbols[] = [
                'fqn' => $name,
                'name' => $name,
                'symbol_type' => $symbolType->value,
                'line_start' => $node->startPoint()['row'] + 1,
                'line_end' => $node->endPoint()['row'] + 1,
                'signature_json' => json_encode(['type' => $type, 'raw' => $node->text()]),
                'signature_hash' => hash('sha256', $node->text()),
                'language' => 'twig',
            ];
        }

        return $symbols;
    }

    private function getQuery(Node $root): Query
    {
        if ($this->query !== null) {
            return $this->query;
        }

        $lang = $this->registry->loadLanguage('twig');
        
        $pattern = '
            (tag_statement) @item
            (output_directive) @item
            (function_call) @item
            (filter) @item
        ';

        $this->query = new Query($root->binding(), $pattern, $lang);
        return $this->query;
    }

    private function resolveTagType(Node $node): SymbolType
    {
        $text = trim($node->text());
        return match($text) {
            'include' => SymbolType::TwigInclude,
            'embed' => SymbolType::TwigEmbed,
            'extends' => SymbolType::TwigExtends,
            'component' => SymbolType::TwigComponent,
            'block' => SymbolType::TwigBlock,
            'set' => SymbolType::TwigSet,
            'for' => SymbolType::TwigFor,
            'if' => SymbolType::TwigIf,
            default => SymbolType::TwigTag,
        };
    }

    private function findFirstString(Node $node): ?string
    {
        if ($node->type() === 'string' || $node->type() === 'interpolated_string') {
            return trim($node->text(), " '\"");
        }
        foreach ($node->namedChildren() as $child) {
            $found = $this->findFirstString($child);
            if ($found) return $found;
        }
        return null;
    }
}
