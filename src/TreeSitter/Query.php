<?php

declare(strict_types=1);

namespace DrupalEvolver\TreeSitter;

use RuntimeException;
use Generator;

class Query
{
    private ?\FFI\CData $query = null;
    private FFIBinding $binding;

    public function __construct(
        FFIBinding $binding,
        string $pattern,
        \FFI\CData $language,
    ) {
        $this->binding = $binding;
        $ffi = $binding->ffi();

        $errorOffset = $ffi->new('uint32_t');
        $errorType = $ffi->new('uint32_t');

        // Cast language pointer to core FFI TSLanguage*
        $language = $ffi->cast('const TSLanguage *', $language);

        $this->query = $this->binding->ts_query_new(
            $language,
            $pattern,
            strlen($pattern),
            \FFI::addr($errorOffset),
            \FFI::addr($errorType)
        );

        if ($this->query === null) {
            $offset = (int)$errorOffset->cdata;
            $type = (int)$errorType->cdata;
            $msg = "Failed to create query. Error at offset {$offset}, type {$type}";
            $msg .= "\nPattern: " . $pattern;
            throw new RuntimeException($msg);
        }
    }

    /**
     * @return Generator<int, array<string, Node>>
     */
    #[\NoDiscard]
    public function matches(Node $rootNode, string $source): Generator
    {
        $ffi = $this->binding->ffi();
        $cursor = $this->binding->ts_query_cursor_new();
        $this->binding->ts_query_cursor_exec($cursor, $this->query, $rootNode->raw());

        $match = $ffi->new('TSQueryMatch');
        $nameLen = $ffi->new('uint32_t');

        try {
            while ($this->binding->ts_query_cursor_next_match($cursor, \FFI::addr($match))) {
                $captures = [];
                for ($i = 0; $i < $match->capture_count; $i++) {
                    $capture = $match->captures[$i];
                    $name = $this->binding->ts_query_capture_name_for_id(
                        $this->query,
                        $capture->index,
                        \FFI::addr($nameLen)
                    );
                    $captureName = (string)$name;
                    $captures[$captureName] = new Node($this->copyTsNode($capture->node), $source, $this->binding);
                }
                yield $captures;
            }
        } finally {
            $this->binding->ts_query_cursor_delete($cursor);
        }
    }

    private function copyTsNode(\FFI\CData $node): \FFI\CData
    {
        $ffi = $this->binding->ffi();
        $copy = $ffi->new('TSNode');
        $copy->context[0] = $node->context[0];
        $copy->context[1] = $node->context[1];
        $copy->context[2] = $node->context[2];
        $copy->context[3] = $node->context[3];
        $copy->id = $node->id;
        $copy->tree = $node->tree;

        return $copy;
    }

    public function __destruct()
    {
        if (isset($this->query) && $this->query !== null) {
            $this->binding->ts_query_delete($this->query);
        }
    }
}
