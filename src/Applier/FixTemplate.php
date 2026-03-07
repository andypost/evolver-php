<?php

declare(strict_types=1);

namespace DrupalEvolver\Applier;

class FixTemplate
{
    #[\NoDiscard]
    public function apply(string $matchedSource, string $templateJson): ?string
    {
        $template = json_decode($templateJson, true);
        if (!$template) {
            return null;
        }

        return match ($template['type'] ?? '') {
            'function_rename' => $this->applyFunctionRename($matchedSource, $template),
            'parameter_insert' => $this->applyParameterInsert($matchedSource, $template),
            'string_replace' => $this->applyStringReplace($matchedSource, $template),
            'namespace_move' => $this->applyNamespaceMove($matchedSource, $template),
            'function_call_rewrite' => $this->applyFunctionCallRewrite($matchedSource, $template),
            'method_chain' => $this->applyMethodChain($matchedSource, $template),
            'variable_access' => $this->applyVariableAccess($matchedSource, $template),
            'constant_replace' => $this->applyConstantReplace($matchedSource, $template),
            'global_replace' => $this->applyGlobalReplace($matchedSource, $template),
            default => null,
        };
    }

    private function applyFunctionRename(string $source, array $template): string
    {
        $old = $template['old'] ?? '';
        $new = $template['new'] ?? '';
        return str_replace($old, $new, $source);
    }

    private function applyParameterInsert(string $source, array $template): string
    {
        $position = $template['position'] ?? 0;
        $value = $template['value'] ?? 'NULL';

        // Find the arguments list and insert at position
        if (preg_match('/\(([^)]*)\)/', $source, $m, PREG_OFFSET_CAPTURE)) {
            $argsStr = $m[1][0];
            $argsOffset = $m[1][1];

            $args = array_map('trim', explode(',', $argsStr));
            array_splice($args, $position, 0, [$value]);

            $newArgs = implode(', ', $args);
            return substr($source, 0, $argsOffset) . $newArgs . substr($source, $argsOffset + strlen($argsStr));
        }

        return $source;
    }

    private function applyStringReplace(string $source, array $template): string
    {
        $old = $template['old'] ?? '';
        $new = $template['new'] ?? '';
        return str_replace($old, $new, $source);
    }

    private function applyNamespaceMove(string $source, array $template): string
    {
        $oldNs = $template['old_namespace'] ?? '';
        $newNs = $template['new_namespace'] ?? '';
        return str_replace(
            str_replace('\\', '\\\\', $oldNs),
            str_replace('\\', '\\\\', $newNs),
            str_replace($oldNs, $newNs, $source)
        );
    }

    /**
     * Rewrite a function call to a new name, preserving arguments.
     *
     * Template: {"type":"function_call_rewrite","old":"check_plain","new":"\\Drupal\\Component\\Utility\\Html::escape"}
     * Input:  check_plain($text)
     * Output: \Drupal\Component\Utility\Html::escape($text)
     */
    private function applyFunctionCallRewrite(string $source, array $template): string
    {
        $old = $template['old'] ?? '';
        $new = $template['new'] ?? '';
        if ($old === '' || $new === '') {
            return $source;
        }

        // Replace the function name portion before the opening parenthesis
        $pos = strpos($source, $old);
        if ($pos === false) {
            return $source;
        }

        return substr($source, 0, $pos) . $new . substr($source, $pos + strlen($old));
    }

    /**
     * Build a method chain from config.
     *
     * Template: {"type":"method_chain","object":"\\Drupal::entityTypeManager()","chain":["getStorage","load"],"args_map":{"getStorage":[0],"load":[1]}}
     * Input:  entity_load('node', 123)
     * Output: \Drupal::entityTypeManager()->getStorage('node')->load(123)
     */
    private function applyMethodChain(string $source, array $template): string
    {
        $object = $template['object'] ?? '';
        $chain = $template['chain'] ?? [];
        $argsMap = $template['args_map'] ?? [];

        if ($object === '' || empty($chain)) {
            return $source;
        }

        // Extract arguments from the matched function call
        $args = $this->extractArguments($source);

        $result = $object;
        foreach ($chain as $method) {
            $methodArgs = [];
            $argIndices = $argsMap[$method] ?? [];
            foreach ($argIndices as $idx) {
                if (isset($args[$idx])) {
                    $methodArgs[] = $args[$idx];
                }
            }
            $result .= '->' . $method . '(' . implode(', ', $methodArgs) . ')';
        }

        return $result;
    }

    /**
     * Replace property access with method call.
     *
     * Template: {"type":"variable_access","property":"nid","getter":"id","setter":"setId"}
     * Input:  $node->nid
     * Output: $node->id()
     */
    private function applyVariableAccess(string $source, array $template): string
    {
        $property = $template['property'] ?? '';
        $getter = $template['getter'] ?? '';

        if ($property === '' || $getter === '') {
            return $source;
        }

        // Match ->property (not followed by parenthesis, i.e. not already a method call)
        $pattern = '/(->' . preg_quote($property, '/') . ')(?!\s*\()/';
        return preg_replace($pattern, '->' . $getter . '()', $source) ?? $source;
    }

    /**
     * Replace a constant with its new form.
     *
     * Template: {"type":"constant_replace","old":"LANGUAGE_NONE","new":"\\Drupal\\Core\\Language\\LanguageInterface::LANGCODE_NOT_SPECIFIED"}
     */
    private function applyConstantReplace(string $source, array $template): string
    {
        $old = $template['old'] ?? '';
        $new = $template['new'] ?? '';
        if ($old === '' || $new === '') {
            return $source;
        }

        // Word-boundary replacement to avoid partial matches
        $pattern = '/\b' . preg_quote($old, '/') . '\b/';
        return preg_replace($pattern, $new, $source) ?? $source;
    }

    /**
     * Replace global variable usage.
     *
     * Template: {"type":"global_replace","variable":"user","replacement":"\\Drupal::currentUser()"}
     * Input:  global $user;
     * Output: $user = \Drupal::currentUser();
     */
    private function applyGlobalReplace(string $source, array $template): string
    {
        $variable = $template['variable'] ?? '';
        $replacement = $template['replacement'] ?? '';
        if ($variable === '' || $replacement === '') {
            return $source;
        }

        // global $var; → $var = replacement;
        $globalPattern = '/global\s+\$' . preg_quote($variable, '/') . '\s*;/';
        $replaced = preg_replace($globalPattern, '$' . $variable . ' = ' . $replacement . ';', $source);

        // $GLOBALS['var'] → replacement
        $globalsPattern = '/\$GLOBALS\s*\[\s*[\'"]' . preg_quote($variable, '/') . '[\'"]\s*\]/';
        $replaced = preg_replace($globalsPattern, $replacement, $replaced ?? $source);

        return $replaced ?? $source;
    }

    /**
     * Extract arguments from a function call string.
     *
     * @return string[] Individual argument strings, trimmed
     */
    private function extractArguments(string $source): array
    {
        // Find content between the outermost parentheses
        $openPos = strpos($source, '(');
        if ($openPos === false) {
            return [];
        }

        $depth = 0;
        $argStart = $openPos + 1;
        $args = [];
        $len = strlen($source);

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $arg = trim(substr($source, $argStart, $i - $argStart));
                    if ($arg !== '') {
                        $args[] = $arg;
                    }
                    break;
                }
            } elseif ($ch === ',' && $depth === 1) {
                $args[] = trim(substr($source, $argStart, $i - $argStart));
                $argStart = $i + 1;
            }
        }

        return $args;
    }
}
