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
}
