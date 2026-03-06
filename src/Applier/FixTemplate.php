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
}
