<?php

declare(strict_types=1);

namespace DrupalEvolver\TreeSitter;

class Tree
{
    private array $data;
    private string $source;
    private ?FFIBinding $ffi;
    private $rootNode;

    public function __construct($input, string $source, ?FFIBinding $ffi = null)
    {
        $this->source = $source;
        $this->ffi = $ffi;

        // Handle both FFI CData and JSON array input
        if (is_array($input)) {
            $this->data = $input;
            $this->rootNode = new Node($this->data['rootNode']);
        } else {
            // FFI CData - assume it's a TSTree*
            $this->ffi = $ffi;
            $this->data = [];
            $node = $ffi->ts_tree_root_node($input);
            $this->rootNode = new Node($node, $source, $ffi);
        }
    }

    public function rootNode(): Node
    {
        return $this->rootNode;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function __destruct()
    {
        // FFI trees need cleanup, CLI data doesn't
        if ($this->ffi !== null && isset($this->ffi)) {
            // Tree cleanup handled by FFI GC when $ffi is destroyed
            // or explicitly if we were storing the tree CData
        }
    }
}
