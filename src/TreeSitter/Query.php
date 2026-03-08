<?php

declare(strict_types=1);

namespace DrupalEvolver\TreeSitter;

use RuntimeException;
use Generator;

class Query
{
    private const PREDICATE_DONE = 0;
    private const PREDICATE_CAPTURE = 1;
    private const PREDICATE_STRING = 2;

    private ?\FFI\CData $query = null;
    private FFIBinding $binding;

    /** @var array<int, list<array<string, mixed>>> */
    private array $predicatesByPattern = [];

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

        $this->predicatesByPattern = $this->compilePredicates();
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
                    $captureName = $this->captureNameForId((int) $capture->index, $nameLen);
                    $captures[$captureName] = new Node($this->copyTsNode($capture->node), $source, $this->binding);
                }

                if (!$this->passesPredicates((int) $match->pattern_index, $captures)) {
                    continue;
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

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function compilePredicates(): array
    {
        $ffi = $this->binding->ffi();
        $stepCount = $ffi->new('uint32_t');
        $patternCount = (int) $this->binding->ts_query_pattern_count($this->query);
        $compiled = [];

        for ($patternIndex = 0; $patternIndex < $patternCount; $patternIndex++) {
            $steps = $this->binding->ts_query_predicates_for_pattern(
                $this->query,
                $patternIndex,
                \FFI::addr($stepCount)
            );

            $count = (int) $stepCount->cdata;
            if ($count === 0) {
                continue;
            }

            $patternPredicates = [];
            $current = [];

            for ($i = 0; $i < $count; $i++) {
                $step = $steps[$i];
                $type = (int) $step->type;

                if ($type === self::PREDICATE_DONE) {
                    if ($current !== []) {
                        $patternPredicates[] = $this->compilePredicate($current);
                        $current = [];
                    }
                    continue;
                }

                if ($type === self::PREDICATE_CAPTURE) {
                    $current[] = [
                        'type' => 'capture',
                        'value' => $this->captureNameForId((int) $step->value_id),
                    ];
                    continue;
                }

                if ($type === self::PREDICATE_STRING) {
                    $current[] = [
                        'type' => 'string',
                        'value' => $this->stringValueForId((int) $step->value_id),
                    ];
                    continue;
                }

                throw new RuntimeException("Unsupported query predicate step type {$type}");
            }

            if ($current !== []) {
                $patternPredicates[] = $this->compilePredicate($current);
            }

            if ($patternPredicates !== []) {
                $compiled[$patternIndex] = $patternPredicates;
            }
        }

        return $compiled;
    }

    /**
     * @param list<array{type:string, value:string}> $steps
     * @return array<string, mixed>
     */
    private function compilePredicate(array $steps): array
    {
        $operator = array_shift($steps);
        if ($operator === null || $operator['type'] !== 'string') {
            throw new RuntimeException('Tree-sitter predicate must start with an operator string');
        }

        $name = ltrim($operator['value'], '#');

        return match ($name) {
            'eq?', 'not-eq?' => $this->compileEqualityPredicate($name, $steps),
            'match?', 'not-match?' => $this->compileRegexPredicate($name, $steps),
            default => throw new RuntimeException("Unsupported query predicate operator {$name}"),
        };
    }

    /**
     * @param list<array{type:string, value:string}> $steps
     * @return array<string, mixed>
     */
    private function compileEqualityPredicate(string $operator, array $steps): array
    {
        if (count($steps) !== 2 || $steps[0]['type'] !== 'capture') {
            throw new RuntimeException("Predicate {$operator} expects a capture followed by a capture or string");
        }

        if (!in_array($steps[1]['type'], ['capture', 'string'], true)) {
            throw new RuntimeException("Predicate {$operator} expects a capture or string operand");
        }

        return [
            'type' => 'eq',
            'negated' => $operator === 'not-eq?',
            'capture' => $steps[0]['value'],
            'operand' => $steps[1],
        ];
    }

    /**
     * @param list<array{type:string, value:string}> $steps
     * @return array<string, mixed>
     */
    private function compileRegexPredicate(string $operator, array $steps): array
    {
        if (count($steps) !== 2 || $steps[0]['type'] !== 'capture' || $steps[1]['type'] !== 'string') {
            throw new RuntimeException("Predicate {$operator} expects a capture and a regex string");
        }

        return [
            'type' => 'match',
            'negated' => $operator === 'not-match?',
            'capture' => $steps[0]['value'],
            'pattern' => $this->compileRegexPattern($steps[1]['value']),
        ];
    }

    /**
     * @param array<string, Node> $captures
     */
    private function passesPredicates(int $patternIndex, array $captures): bool
    {
        $predicates = $this->predicatesByPattern[$patternIndex] ?? [];
        foreach ($predicates as $predicate) {
            if (!$this->passesPredicate($predicate, $captures)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $predicate
     * @param array<string, Node> $captures
     */
    private function passesPredicate(array $predicate, array $captures): bool
    {
        $captureText = $this->captureText($captures, (string) $predicate['capture']);
        if ($captureText === null) {
            return false;
        }

        $matches = match ($predicate['type']) {
            'eq' => $this->matchesEqualityPredicate($captureText, $predicate, $captures),
            'match' => preg_match((string) $predicate['pattern'], $captureText) === 1,
            default => throw new RuntimeException('Unknown compiled predicate type'),
        };

        return ($predicate['negated'] ?? false) ? !$matches : $matches;
    }

    /**
     * @param array<string, mixed> $predicate
     * @param array<string, Node> $captures
     */
    private function matchesEqualityPredicate(string $captureText, array $predicate, array $captures): bool
    {
        $operand = $predicate['operand'] ?? null;
        if (!is_array($operand)) {
            return false;
        }

        $expected = $this->resolveOperandValue($operand, $captures);
        if ($expected === null) {
            return false;
        }

        return $captureText === $expected;
    }

    /**
     * @param array{type:string, value:string} $operand
     * @param array<string, Node> $captures
     */
    private function resolveOperandValue(array $operand, array $captures): ?string
    {
        return match ($operand['type']) {
            'string' => $operand['value'],
            'capture' => $this->captureText($captures, $operand['value']),
            default => null,
        };
    }

    /**
     * @param array<string, Node> $captures
     */
    private function captureText(array $captures, string $captureName): ?string
    {
        $node = $captures[$captureName] ?? null;
        return $node instanceof Node ? $node->text() : null;
    }

    private function compileRegexPattern(string $pattern): string
    {
        $compiled = '/' . str_replace('/', '\/', $pattern) . '/u';
        if (@preg_match($compiled, '') === false) {
            throw new RuntimeException("Invalid predicate regex: {$pattern}");
        }

        return $compiled;
    }

    private function captureNameForId(int $captureId, ?\FFI\CData $nameLen = null): string
    {
        $ffi = $this->binding->ffi();
        $nameLen ??= $ffi->new('uint32_t');
        $name = $this->binding->ts_query_capture_name_for_id(
            $this->query,
            $captureId,
            \FFI::addr($nameLen)
        );

        return $this->ffiString($name, (int) $nameLen->cdata);
    }

    private function stringValueForId(int $stringId): string
    {
        $ffi = $this->binding->ffi();
        $length = $ffi->new('uint32_t');
        $value = $this->binding->ts_query_string_value_for_id(
            $this->query,
            $stringId,
            \FFI::addr($length)
        );

        return $this->ffiString($value, (int) $length->cdata);
    }

    private function ffiString(mixed $value, int $length): string
    {
        if (is_string($value)) {
            return $length > 0 ? substr($value, 0, $length) : $value;
        }

        return \FFI::string($value, $length);
    }
}
