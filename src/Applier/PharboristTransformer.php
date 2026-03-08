<?php

declare(strict_types=1);

namespace DrupalEvolver\Applier;

use Pharborist\Filter;
use Pharborist\Functions\FunctionCallNode;
use Pharborist\Objects\ClassMethodCallNode;
use Pharborist\Objects\ObjectMethodCallNode;
use Pharborist\Parser;

/**
 * AST transformation bridge using Pharborist.
 *
 * Handles complex rewrites that byte-offset string-replace cannot do:
 * - Function call argument rewriting (reordering, wrapping)
 * - Method chain construction from function calls
 * - $form_state array→method conversion
 * - Property access → method call conversion
 */
class PharboristTransformer
{
    /**
     * Apply a pharborist transformation script to source code.
     *
     * @param string $source Full PHP source code of the file
     * @param array  $script Transformation descriptor from fix_template JSON
     * @return string|null Transformed source, or null on failure
     */
    #[\NoDiscard]
    public function transform(string $source, array $script): ?string
    {
        $action = $script['action'] ?? '';

        try {
            return match ($action) {
                'rename_function_calls' => $this->renameFunctionCalls($source, $script),
                'rewrite_to_method_chain' => $this->rewriteToMethodChain($source, $script),
                'rewrite_property_access' => $this->rewritePropertyAccess($source, $script),
                'rewrite_global_variable' => $this->rewriteGlobalVariable($source, $script),
                'annotation_to_attribute' => $this->annotationToAttribute($source, $script),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Rename function calls: old_func(...) → new_func(...)
     *
     * Script: {"action":"rename_function_calls","old":"check_plain","new":"\\Drupal\\Component\\Utility\\Html::escape"}
     */
    private function renameFunctionCalls(string $source, array $script): ?string
    {
        $oldName = $script['old'] ?? '';
        $newName = $script['new'] ?? '';
        if ($oldName === '' || $newName === '') {
            return null;
        }

        $tree = Parser::parseSource($source);
        $calls = $tree->find(Filter::isFunctionCall($oldName));

        if ($calls->count() === 0) {
            return null;
        }

        foreach ($calls as $call) {
            if (!$call instanceof FunctionCallNode) {
                continue;
            }

            $args = $call->getArguments()->toArray();
            $argsText = [];
            foreach ($args as $arg) {
                $argsText[] = $arg->getText();
            }

            $newCallText = $newName . '(' . implode(', ', $argsText) . ')';
            $replacement = Parser::parseExpression($newCallText);
            $call->replaceWith($replacement);
        }

        return $tree->getText();
    }

    /**
     * Rewrite a function call to a method chain.
     *
     * Script: {
     *   "action": "rewrite_to_method_chain",
     *   "function": "entity_load",
     *   "object": "\\Drupal::entityTypeManager()",
     *   "chain": [
     *     {"method": "getStorage", "args": [0]},
     *     {"method": "load", "args": [1]}
     *   ]
     * }
     */
    private function rewriteToMethodChain(string $source, array $script): ?string
    {
        $funcName = $script['function'] ?? '';
        $object = $script['object'] ?? '';
        $chain = $script['chain'] ?? [];

        if ($funcName === '' || $object === '' || empty($chain)) {
            return null;
        }

        $tree = Parser::parseSource($source);
        $calls = $tree->find(Filter::isFunctionCall($funcName));

        if ($calls->count() === 0) {
            return null;
        }

        foreach ($calls as $call) {
            if (!$call instanceof FunctionCallNode) {
                continue;
            }

            $args = $call->getArguments()->toArray();
            $argsText = [];
            foreach ($args as $arg) {
                $argsText[] = $arg->getText();
            }

            // Build chain expression
            $expr = $object;
            foreach ($chain as $step) {
                $method = $step['method'] ?? '';
                $argIndices = $step['args'] ?? [];
                $stepArgs = [];
                foreach ($argIndices as $idx) {
                    if (isset($argsText[$idx])) {
                        $stepArgs[] = $argsText[$idx];
                    }
                }
                $expr .= '->' . $method . '(' . implode(', ', $stepArgs) . ')';
            }

            $replacement = Parser::parseExpression($expr);
            $call->replaceWith($replacement);
        }

        return $tree->getText();
    }

    /**
     * Rewrite property access to method calls.
     *
     * Script: {
     *   "action": "rewrite_property_access",
     *   "variable_pattern": "node",
     *   "mappings": {"nid": "id", "title": "getTitle", "status": "isPublished"}
     * }
     */
    private function rewritePropertyAccess(string $source, array $script): ?string
    {
        $mappings = $script['mappings'] ?? [];
        if (empty($mappings)) {
            return null;
        }

        $modified = false;
        foreach ($mappings as $property => $method) {
            $pattern = '/(\$\w+)->' . preg_quote((string) $property, '/') . '(?!\s*\()/';
            $replacement = '$1->' . $method . '()';
            $newSource = preg_replace($pattern, $replacement, $source);
            if ($newSource !== null && $newSource !== $source) {
                $source = $newSource;
                $modified = true;
            }
        }

        return $modified ? $source : null;
    }

    /**
     * Rewrite global variable usage.
     *
     * Script: {
     *   "action": "rewrite_global_variable",
     *   "variable": "user",
     *   "replacement": "\\Drupal::currentUser()"
     * }
     */
    private function rewriteGlobalVariable(string $source, array $script): ?string
    {
        $variable = $script['variable'] ?? '';
        $replacement = $script['replacement'] ?? '';
        if ($variable === '' || $replacement === '') {
            return null;
        }

        $modified = false;

        // global $var; → $var = replacement;
        $pattern = '/global\s+\$' . preg_quote($variable, '/') . '\s*;/';
        $newSource = preg_replace($pattern, '$' . $variable . ' = ' . $replacement . ';', $source);
        if ($newSource !== null && $newSource !== $source) {
            $source = $newSource;
            $modified = true;
        }

        // $GLOBALS['var'] → replacement
        $pattern = '/\$GLOBALS\s*\[\s*[\'"]' . preg_quote($variable, '/') . '[\'"]\s*\]/';
        $newSource = preg_replace($pattern, $replacement, $source);
        if ($newSource !== null && $newSource !== $source) {
            $source = $newSource;
            $modified = true;
        }

        return $modified ? $source : null;
    }

    /**
     * Rewrite a Drupal-style docblock plugin annotation to a PHP 8 attribute.
     *
     * Script: {
     *   "action": "annotation_to_attribute",
     *   "annotation": "Block",
     *   "attribute": "Block",
     *   "attribute_import": "Drupal\\Core\\Block\\Attribute\\Block"
     * }
     */
    private function annotationToAttribute(string $source, array $script): ?string
    {
        $annotation = ltrim((string) ($script['annotation'] ?? ''), '@');
        $attributeImport = ltrim((string) ($script['attribute_import'] ?? ''), '\\');
        $attribute = (string) ($script['attribute'] ?? '');

        if ($annotation === '') {
            return null;
        }

        if ($attribute === '' && $attributeImport !== '') {
            $parts = explode('\\', $attributeImport);
            $attribute = (string) end($parts);
        }

        if ($attribute === '') {
            return null;
        }

        $annotationNeedle = '@' . $annotation;
        $annotationPos = strpos($source, $annotationNeedle);
        if ($annotationPos === false) {
            return null;
        }

        $docblockStart = strrpos(substr($source, 0, $annotationPos), '/**');
        $docblockEnd = strpos($source, '*/', $annotationPos);
        if ($docblockStart === false || $docblockEnd === false || $docblockEnd < $annotationPos) {
            return null;
        }

        $openParen = strpos($source, '(', $annotationPos + strlen($annotationNeedle));
        if ($openParen === false || $openParen > $docblockEnd) {
            return null;
        }

        $closeParen = $this->findBalancedClosingParenthesis($source, $openParen);
        if ($closeParen === null || $closeParen > $docblockEnd) {
            return null;
        }

        $annotationArguments = trim(substr($source, $openParen + 1, $closeParen - $openParen - 1));
        $attributeArguments = $this->convertAnnotationArguments($annotationArguments);

        $docblock = substr($source, $docblockStart, $docblockEnd + 2 - $docblockStart);
        $updatedDocblock = $this->removeAnnotationFromDocblock($docblock, $annotation);

        $attributeLine = '#[' . $attribute;
        if ($attributeArguments !== '') {
            $attributeLine .= '(' . $attributeArguments . ')';
        }
        $attributeLine .= ']';

        $replacement = $updatedDocblock !== ''
            ? $updatedDocblock . "\n" . $attributeLine
            : $attributeLine;

        $updatedSource = substr($source, 0, $docblockStart) . $replacement . substr($source, $docblockEnd + 2);
        if ($attributeImport !== '') {
            $updatedSource = $this->ensureUseImport($updatedSource, $attributeImport);
        }

        return $updatedSource !== $source ? $updatedSource : null;
    }

    private function findBalancedClosingParenthesis(string $source, int $openParen): ?int
    {
        $depth = 1;
        $length = strlen($source);

        for ($i = $openParen + 1; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function convertAnnotationArguments(string $arguments): string
    {
        $normalized = preg_replace('/^\s*\*\s?/m', '', $arguments) ?? $arguments;
        $normalized = preg_replace('/\s*=\s*/', ': ', $normalized) ?? $normalized;
        $normalized = preg_replace('/,\s*$/m', ',', $normalized) ?? $normalized;
        $normalized = preg_replace('/\n{2,}/', "\n", $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function removeAnnotationFromDocblock(string $docblock, string $annotation): string
    {
        $lines = preg_split('/\R/', $docblock);
        if ($lines === false) {
            return $docblock;
        }

        $startLine = null;
        $endLine = null;
        $depth = 0;

        foreach ($lines as $index => $line) {
            if ($startLine === null && str_contains($line, '@' . $annotation . '(')) {
                $startLine = $index;
                $depth = substr_count($line, '(') - substr_count($line, ')');
                if ($depth <= 0) {
                    $endLine = $index;
                    break;
                }
                continue;
            }

            if ($startLine !== null) {
                $depth += substr_count($line, '(') - substr_count($line, ')');
                if ($depth <= 0) {
                    $endLine = $index;
                    break;
                }
            }
        }

        if ($startLine === null || $endLine === null) {
            return $docblock;
        }

        array_splice($lines, $startLine, $endLine - $startLine + 1);
        $lines = $this->normalizeDocblockLines($lines);

        if (count($lines) <= 2) {
            return '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function normalizeDocblockLines(array $lines): array
    {
        if (count($lines) <= 2) {
            return $lines;
        }

        $first = array_shift($lines);
        $last = array_pop($lines);

        $body = [];
        $previousBlank = true;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $isBlank = $trimmed === '' || $trimmed === '*';
            if ($isBlank) {
                if ($previousBlank) {
                    continue;
                }
                $body[] = ' *';
                $previousBlank = true;
                continue;
            }

            $body[] = rtrim($line);
            $previousBlank = false;
        }

        while ($body !== [] && (trim((string) $body[0]) === '' || trim((string) $body[0]) === '*')) {
            array_shift($body);
        }

        while ($body !== [] && (trim((string) end($body)) === '' || trim((string) end($body)) === '*')) {
            array_pop($body);
        }

        if ($body === []) {
            return [$first, $last];
        }

        return array_merge([$first], $body, [$last]);
    }

    private function ensureUseImport(string $source, string $import): string
    {
        $useStatement = 'use ' . $import . ';';
        if (str_contains($source, $useStatement)) {
            return $source;
        }

        if (preg_match_all('/^use\s+[^;]+;\s*$/m', $source, $matches, PREG_OFFSET_CAPTURE) === 1 && !empty($matches[0])) {
            $lastUse = end($matches[0]);
            if ($lastUse !== false) {
                $insertAt = $lastUse[1] + strlen($lastUse[0]);
                return substr($source, 0, $insertAt) . "\n" . $useStatement . substr($source, $insertAt);
            }
        }

        if (preg_match('/^namespace\s+[^;]+;\s*$/m', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
            $insertAt = $match[0][1] + strlen($match[0][0]);
            return substr($source, 0, $insertAt) . "\n\n" . $useStatement . substr($source, $insertAt);
        }

        if (str_starts_with($source, "<?php\n")) {
            return "<?php\n\n" . $useStatement . substr($source, strlen("<?php"));
        }

        return $useStatement . "\n" . $source;
    }
}
