<?php

declare(strict_types=1);

namespace DrupalEvolver\TreeSitter;

class FFIBinding
{
    private \FFI $ffi;
    private int $ownerPid;
    private string $libPath;
    private static ?FFIBinding $instance = null;

    private function __construct(string $libPath, ?\FFI $ffi = null)
    {
        $this->ownerPid = self::currentPid();
        $this->libPath = $libPath;

        if ($ffi !== null) {
            $this->ffi = $ffi;
            return;
        }

        $cdef = <<<'CDEF'
        typedef uint16_t TSSymbol;
        typedef uint16_t TSFieldId;
        typedef struct TSLanguage TSLanguage;
        typedef struct TSParser TSParser;
        typedef struct TSTree TSTree;
        typedef struct TSQuery TSQuery;
        typedef struct TSQueryCursor TSQueryCursor;

        typedef struct {
            uint32_t row;
            uint32_t column;
        } TSPoint;

        typedef struct {
            uint32_t context[4];
            const void *id;
            const TSTree *tree;
        } TSNode;

        typedef struct {
            TSPoint start_point;
            TSPoint end_point;
            uint32_t start_byte;
            uint32_t end_byte;
        } TSRange;

        typedef struct {
            TSNode node;
            uint32_t index;
        } TSQueryCapture;

        typedef struct {
            uint32_t id;
            uint16_t pattern_index;
            uint16_t capture_count;
            const TSQueryCapture *captures;
        } TSQueryMatch;

        // Parser
        TSParser *ts_parser_new(void);
        void ts_parser_delete(TSParser *self);
        bool ts_parser_set_language(TSParser *self, const TSLanguage *language);
        TSTree *ts_parser_parse_string(TSParser *self, const TSTree *old_tree, const char *string, uint32_t length);

        // Tree
        TSNode ts_tree_root_node(const TSTree *self);
        void ts_tree_delete(TSTree *self);
        TSTree *ts_tree_copy(const TSTree *self);

        // Node
        const char *ts_node_type(TSNode self);
        TSSymbol ts_node_symbol(TSNode self);
        uint32_t ts_node_start_byte(TSNode self);
        uint32_t ts_node_end_byte(TSNode self);
        TSPoint ts_node_start_point(TSNode self);
        TSPoint ts_node_end_point(TSNode self);
        char *ts_node_string(TSNode self);
        bool ts_node_is_null(TSNode self);
        bool ts_node_is_named(TSNode self);
        bool ts_node_is_missing(TSNode self);
        bool ts_node_has_error(TSNode self);
        uint32_t ts_node_child_count(TSNode self);
        TSNode ts_node_child(TSNode self, uint32_t child_index);
        uint32_t ts_node_named_child_count(TSNode self);
        TSNode ts_node_named_child(TSNode self, uint32_t child_index);
        TSNode ts_node_child_by_field_name(TSNode self, const char *name, uint32_t name_length);
        TSNode ts_node_parent(TSNode self);
        TSNode ts_node_next_sibling(TSNode self);
        TSNode ts_node_prev_sibling(TSNode self);
        TSNode ts_node_next_named_sibling(TSNode self);
        TSNode ts_node_prev_named_sibling(TSNode self);
        bool ts_node_eq(TSNode self, TSNode other);

        // Query
        TSQuery *ts_query_new(const TSLanguage *language, const char *source, uint32_t source_len, uint32_t *error_offset, uint32_t *error_type);
        void ts_query_delete(TSQuery *self);
        uint32_t ts_query_capture_count(const TSQuery *self);
        const char *ts_query_capture_name_for_id(const TSQuery *self, uint32_t index, uint32_t *length);

        // Query Cursor
        TSQueryCursor *ts_query_cursor_new(void);
        void ts_query_cursor_delete(TSQueryCursor *self);
        void ts_query_cursor_exec(TSQueryCursor *self, const TSQuery *query, TSNode node);
        bool ts_query_cursor_next_match(TSQueryCursor *self, TSQueryMatch *match);

        // Memory
        void free(void *ptr);
        CDEF;

        $this->ffi = \FFI::cdef($cdef, $libPath);
    }

    public static function create(string $libPath): self
    {
        if (self::$instance === null || self::$instance->needsRefresh($libPath)) {
            self::$instance = self::build($libPath);
        }

        return self::$instance;
    }

    /**
     * Diagnostic escape hatch for explicit rebinding.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function ffi(): \FFI
    {
        return $this->ffi;
    }

    public function ownerPid(): int
    {
        return $this->ownerPid;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->ffi->$name(...$arguments);
    }

    private static function build(string $libPath): self
    {
        try {
            // Try to use preloaded scope first (from ffi.preload).
            $ffi = \FFI::scope('tree-sitter');
            return new self($libPath, $ffi);
        } catch (\Throwable) {
            return new self($libPath);
        }
    }

    private static function currentPid(): int
    {
        return getmypid() ?: 0;
    }

    private function needsRefresh(string $libPath): bool
    {
        return $this->ownerPid !== self::currentPid()
            || $this->libPath !== $libPath;
    }
}
