<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

class FixTemplateGenerator
{
    /**
     * @param array<string, mixed> $oldSymbol
     * @param array<string, mixed>|null $newSymbol
     * @param array<int, array<string, mixed>>|null $diffDetails
     */
    public function generate(
        string $changeType,
        array $oldSymbol,
        ?array $newSymbol = null,
        ?array $diffDetails = null,
    ): ?string {
        return match ($changeType) {
            'function_renamed' => $this->functionRename($oldSymbol, $newSymbol),
            'method_renamed',
            'class_renamed',
            'interface_renamed',
            'trait_renamed',
            'constant_renamed',
            'service_renamed',
            'route_renamed',
            'permission_renamed',
            'config_key_renamed',
            'library_renamed' => $this->renameTemplate($changeType, $oldSymbol, $newSymbol),
            'signature_changed' => $this->parameterInsert($oldSymbol, $diffDetails),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $oldSymbol
     * @param array<string, mixed>|null $newSymbol
     */
    private function functionRename(array $oldSymbol, ?array $newSymbol): ?string
    {
        if ($newSymbol === null) {
            return null;
        }

        $oldName = $this->resolveName($oldSymbol);
        $newName = $this->resolveName($newSymbol);
        if ($oldName === '' || $newName === '' || $oldName === $newName) {
            return null;
        }

        return $this->json([
            'type' => 'function_rename',
            'old' => $oldName,
            'new' => $newName,
        ]);
    }

    /**
     * @param array<string, mixed> $oldSymbol
     * @param array<string, mixed>|null $newSymbol
     */
    private function renameTemplate(string $changeType, array $oldSymbol, ?array $newSymbol): ?string
    {
        if ($newSymbol === null) {
            return null;
        }

        if (in_array($changeType, ['class_renamed', 'interface_renamed', 'trait_renamed'], true)) {
            $namespaceMove = $this->namespaceMove($oldSymbol, $newSymbol);
            if ($namespaceMove !== null) {
                return $namespaceMove;
            }
        }

        $preferFqn = in_array($changeType, ['class_renamed', 'interface_renamed', 'trait_renamed'], true);
        return $this->stringReplace($oldSymbol, $newSymbol, $preferFqn);
    }

    /**
     * @param array<string, mixed> $oldSymbol
     * @param array<string, mixed>|null $newSymbol
     */
    private function stringReplace(array $oldSymbol, ?array $newSymbol, bool $preferFqn = false): ?string
    {
        if ($newSymbol === null) {
            return null;
        }

        $oldValue = $this->resolveReplaceValue($oldSymbol, $preferFqn);
        $newValue = $this->resolveReplaceValue($newSymbol, $preferFqn);
        if ($oldValue === '' || $newValue === '' || $oldValue === $newValue) {
            return null;
        }

        return $this->json([
            'type' => 'string_replace',
            'old' => $oldValue,
            'new' => $newValue,
        ]);
    }

    /**
     * @param array<string, mixed> $oldSymbol
     * @param array<string, mixed>|null $newSymbol
     */
    private function namespaceMove(array $oldSymbol, ?array $newSymbol): ?string
    {
        if ($newSymbol === null) {
            return null;
        }

        $oldFqn = ltrim((string) ($oldSymbol['fqn'] ?? ''), '\\');
        $newFqn = ltrim((string) ($newSymbol['fqn'] ?? ''), '\\');
        if ($oldFqn === '' || $newFqn === '') {
            return null;
        }

        $oldShort = $this->resolveName($oldSymbol);
        $newShort = $this->resolveName($newSymbol);
        if ($oldShort === '' || $newShort === '' || $oldShort !== $newShort) {
            return null;
        }

        $oldNamespace = $this->extractNamespace($oldFqn);
        $newNamespace = $this->extractNamespace($newFqn);
        if ($oldNamespace === '' || $newNamespace === '' || $oldNamespace === $newNamespace) {
            return null;
        }

        return $this->json([
            'type' => 'namespace_move',
            'old_namespace' => $oldNamespace,
            'new_namespace' => $newNamespace,
            'class' => $oldShort,
        ]);
    }

    /**
     * @param array<string, mixed> $oldSymbol
     * @param array<int, array<string, mixed>>|null $diffDetails
     */
    private function parameterInsert(array $oldSymbol, ?array $diffDetails): ?string
    {
        if ($diffDetails === null || empty($diffDetails)) {
            return null;
        }

        if (($oldSymbol['symbol_type'] ?? '') !== 'function' && ($oldSymbol['symbol_type'] ?? '') !== 'method') {
            return null;
        }

        $parameterAdded = array_values(array_filter(
            $diffDetails,
            static fn(array $change): bool => ($change['type'] ?? '') === 'parameter_added'
        ));

        $otherChanges = array_values(array_filter(
            $diffDetails,
            static fn(array $change): bool => ($change['type'] ?? '') !== 'parameter_added'
        ));

        // Keep this conservative: only generate template for simple additive signature change.
        if (count($parameterAdded) !== 1 || !empty($otherChanges)) {
            return null;
        }

        $position = $parameterAdded[0]['position'] ?? null;
        if (!is_int($position)) {
            return null;
        }

        return $this->json([
            'type' => 'parameter_insert',
            'function' => $this->resolveName($oldSymbol),
            'position' => $position,
            'value' => 'NULL',
        ]);
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function resolveName(array $symbol): string
    {
        $name = (string) ($symbol['name'] ?? '');
        if ($name !== '') {
            return $name;
        }

        $fqn = (string) ($symbol['fqn'] ?? '');
        if ($fqn === '') {
            return '';
        }

        $parts = explode('\\', $fqn);
        $name = (string) end($parts);
        if (str_contains($name, '::')) {
            $method = explode('::', $name);
            $name = (string) end($method);
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function resolveReplaceValue(array $symbol, bool $preferFqn = false): string
    {
        $fqn = (string) ($symbol['fqn'] ?? '');
        $name = (string) ($symbol['name'] ?? '');

        if ($preferFqn && $fqn !== '') {
            return ltrim($fqn, '\\');
        }

        if ($name !== '') {
            return $name;
        }

        return $fqn;
    }

    private function extractNamespace(string $fqn): string
    {
        if (!str_contains($fqn, '\\')) {
            return '';
        }

        $parts = explode('\\', $fqn);
        array_pop($parts);
        return implode('\\', $parts);
    }

    /**
     * @param array<string, mixed> $template
     */
    private function json(array $template): string
    {
        return (string) json_encode($template, JSON_UNESCAPED_SLASHES);
    }
}
