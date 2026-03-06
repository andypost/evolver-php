<?php

declare(strict_types=1);

namespace DrupalEvolver\TreeSitter;

class Node
{
    private ?FFIBinding $ffi;
    private ?\FFI\CData $node;
    private array $data;
    private ?string $source;
    private ?Node $parent = null;

    // For JSON tree-sitter output
    public function __construct($input, ?string $source = null, ?FFIBinding $ffi = null, ?Node $parent = null)
    {
        $this->parent = $parent;

        // Handle both FFI CData and JSON array input
        if (is_array($input)) {
            $this->data = $input;
            $this->source = $source;
            $this->ffi = null;
            $this->node = null;
        } else {
            // FFI CData TSNode
            $this->ffi = $ffi;
            $this->node = $input;
            $this->source = $source;
            $this->data = [];
        }
    }

    public function type(): string
    {
        if ($this->ffi !== null) {
            return $this->ffi->ts_node_type($this->node);
        }

        return $this->data['type'] ?? '';
    }

    public function text(): string
    {
        if ($this->ffi !== null) {
            $start = $this->ffi->ts_node_start_byte($this->node);
            $end = $this->ffi->ts_node_end_byte($this->node);
            return substr($this->source ?? '', $start, $end - $start);
        }

        if (!isset($this->data['startByte'], $this->data['endByte'], $this->data['source'])) {
            return '';
        }

        // Find the source text - tree-sitter CLI doesn't always include it
        if (isset($this->data['source'])) {
            return substr($this->data['source'], $this->data['startByte'], $this->data['endByte'] - $this->data['startByte']);
        }

        // For CLI output, we need to get source from somewhere
        // This is a limitation - CLI doesn't include source text
        // We'll need to extract it separately or pass it in
        return '';
    }

    public function startByte(): int
    {
        if ($this->ffi !== null) {
            return $this->ffi->ts_node_start_byte($this->node);
        }

        return $this->data['startByte'] ?? 0;
    }

    public function endByte(): int
    {
        if ($this->ffi !== null) {
            return $this->ffi->ts_node_end_byte($this->node);
        }

        return $this->data['endByte'] ?? 0;
    }

    public function startPoint(): array
    {
        if ($this->ffi !== null) {
            $point = $this->ffi->ts_node_start_point($this->node);
            return ['row' => $point->row, 'column' => $point->column];
        }

        return [
            'row' => $this->data['startPoint']['row'] ?? 0,
            'column' => $this->data['startPoint']['column'] ?? 0,
        ];
    }

    public function endPoint(): array
    {
        if ($this->ffi !== null) {
            $point = $this->ffi->ts_node_end_point($this->node);
            return ['row' => $point->row, 'column' => $point->column];
        }

        return [
            'row' => $this->data['endPoint']['row'] ?? 0,
            'column' => $this->data['endPoint']['column'] ?? 0,
        ];
    }

    public function isNamed(): bool
    {
        if ($this->ffi !== null) {
            return $this->ffi->ts_node_is_named($this->node);
        }

        return $this->data['isNamed'] ?? false;
    }

    public function namedChildCount(): int
    {
        if ($this->ffi !== null) {
            return $this->ffi->ts_node_named_child_count($this->node);
        }

        return count($this->data['children'] ?? array_filter($this->data['children'] ?? [], fn($c) => $c['isNamed'] ?? false));
    }

    public function namedChild(int $index): ?self
    {
        if ($this->ffi !== null) {
            $child = $this->ffi->ts_node_named_child($this->node, $index);
            if ($this->ffi->ts_node_is_null($child)) {
                return null;
            }
            return new self($child, $this->source, $this->ffi);
        }

        $namedChildren = array_values(array_filter($this->data['children'] ?? [], fn($c) => $c['isNamed'] ?? false));

        return isset($namedChildren[$index]) ? new self($namedChildren[$index], $this->source, null, $this) : null;
    }

    /**
     * @return \Generator<int, self>
     */
    public function children(): \Generator
    {
        if ($this->ffi !== null) {
            $count = $this->ffi->ts_node_child_count($this->node);
            for ($i = 0; $i < $count; $i++) {
                $child = $this->ffi->ts_node_child($this->node, $i);
                yield new self($child, $this->source, $this->ffi);
            }
            return;
        }

        foreach ($this->data['children'] ?? [] as $child) {
            yield new self($child, $this->source, null, $this);
        }
    }

    /**
     * @return \Generator<int, self>
     */
    public function namedChildren(): \Generator
    {
        if ($this->ffi !== null) {
            $count = $this->ffi->ts_node_named_child_count($this->node);
            for ($i = 0; $i < $count; $i++) {
                $child = $this->ffi->ts_node_named_child($this->node, $i);
                yield new self($child, $this->source, $this->ffi);
            }
            return;
        }

        foreach ($this->data['children'] ?? [] as $child) {
            $node = new self($child, $this->source, null, $this);
            if ($node->isNamed()) {
                yield $node;
            }
        }
    }

    public function childByFieldName(string $name): ?self
    {
        if ($this->ffi !== null) {
            $child = $this->ffi->ts_node_child_by_field_name($this->node, $name, strlen($name));
            if ($this->ffi->ts_node_is_null($child)) {
                return null;
            }
            return new self($child, $this->source, $this->ffi);
        }

        // For JSON, find by fieldName in children
        foreach ($this->data['children'] ?? [] as $child) {
            if (isset($child['fieldName']) && $child['fieldName'] === $name) {
                return new self($child, $this->source, null, $this);
            }
        }

        return null;
    }

    public function parent(): ?self
    {
        if ($this->ffi !== null) {
            $parent = $this->ffi->ts_node_parent($this->node);
            if ($this->ffi->ts_node_is_null($parent)) {
                return null;
            }
            return new self($parent, $this->source, $this->ffi);
        }

        return $this->parent;
    }

    public function nextSibling(): ?self
    {
        if ($this->ffi !== null) {
            $sibling = $this->ffi->ts_node_next_sibling($this->node);
            if ($this->ffi->ts_node_is_null($sibling)) {
                return null;
            }
            return new self($sibling, $this->source, $this->ffi);
        }

        return null; // Not implemented for CLI
    }

    public function prevSibling(): ?self
    {
        if ($this->ffi !== null) {
            $sibling = $this->ffi->ts_node_prev_sibling($this->node);
            if ($this->ffi->ts_node_is_null($sibling)) {
                return null;
            }
            return new self($sibling, $this->source, $this->ffi);
        }

        return null; // Not implemented for CLI
    }

    public function walk(callable $callback): void
    {
        $stack = [$this];

        while (!empty($stack)) {
            $node = array_pop($stack);
            $callback($node);

            // Convert generator to array to process in reverse
            $children = iterator_to_array($node->namedChildren());
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $stack[] = $children[$i];
            }
        }
    }

    public function sexp(): string
    {
        if ($this->ffi !== null) {
            $ptr = $this->ffi->ts_node_string($this->node);
            $str = \FFI::string($ptr);
            $this->ffi->free($ptr);
            return $str;
        }

        // For CLI, we'd need to reconstruct S-expression or store it
        return $this->data['sexp'] ?? '';
    }

    public function raw(): mixed
    {
        return $this->node ?? $this->data;
    }

    public function isCliMode(): bool
    {
        return $this->ffi === null;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function binding(): ?FFIBinding
    {
        return $this->ffi;
    }
}
