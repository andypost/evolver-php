<?php

declare(strict_types=1);

namespace DrupalEvolver\Pattern;

class QueryGenerator
{
    public function generate(string $changeType, array $symbol): ?string
    {
        $type = (string) ($symbol['symbol_type'] ?? '');
        $name = $this->resolveName($symbol);
        if ($name === '') {
            return null;
        }

        if ($changeType === 'signature_changed') {
            return $this->signatureMatch($type, $name);
        }

        if (in_array($changeType, ['service_removed', 'service_renamed', 'service_class_changed', 'service_changed'], true)) {
            return $this->serviceReference($name);
        }

        if (in_array($changeType, ['route_removed', 'route_renamed', 'route_changed'], true)) {
            return $this->stringReference($name);
        }

        if (in_array($changeType, ['permission_removed', 'permission_renamed'], true)) {
            return $this->stringReference($name);
        }

        if (in_array($changeType, ['config_key_removed', 'config_key_renamed', 'config_key_changed'], true)) {
            return $this->stringReference($name);
        }

        if (in_array($changeType, ['library_removed', 'library_changed'], true)) {
            return $this->stringReference($name);
        }

        if ($changeType === 'deprecated_added') {
            return $this->queryBySymbol($symbol);
        }

        if (str_ends_with($changeType, '_removed') || str_ends_with($changeType, '_renamed')) {
            return $this->queryBySymbol($symbol);
        }

        return null;
    }

    private function functionCall(string $name): string
    {
        $literal = $this->escapeLiteral($name);
        return "(function_call_expression function: (name) @fn (#eq? @fn \"{$literal}\"))";
    }

    private function methodCall(string $name): string
    {
        // Extract method name from FQN like "Class::method"
        $parts = explode('::', $name);
        $methodName = end($parts);
        $literal = $this->escapeLiteral($methodName);
        return "(member_call_expression name: (name) @method (#eq? @method \"{$literal}\"))";
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function classReference(array $symbol, string $name): string
    {
        $fqn = ltrim((string) ($symbol['fqn'] ?? ''), '\\');
        if ($fqn !== '' && str_contains($fqn, '\\')) {
            $regex = '^\\\\?' . preg_quote($fqn, '/') . '$';
            $regexLiteral = $this->escapeLiteral($regex);

            // Match qualified names in specific contexts to reduce noise
            return "[
                (namespace_use_clause (qualified_name) @cls_fqn)
                (object_creation_expression (qualified_name) @cls_fqn)
                (class_constant_access_expression (qualified_name) @cls_fqn)
                (scoped_call_expression (qualified_name) @cls_fqn)
                (base_clause (qualified_name) @cls_fqn)
            ] (#match? @cls_fqn \"{$regexLiteral}\")";
        }

        $literal = $this->escapeLiteral($name);
        return "[
            (namespace_use_clause (name) @cls)
            (object_creation_expression (name) @cls)
            (class_constant_access_expression (name) @cls)
            (scoped_call_expression (name) @cls)
            (base_clause (name) @cls)
        ] (#eq? @cls \"{$literal}\")";
    }

    private function serviceReference(string $name): string
    {
        $literal = $this->escapeLiteral($name);
        return "(string_content) @svc (#eq? @svc \"{$literal}\")";
    }

    private function stringReference(string $name): string
    {
        $literal = $this->escapeLiteral($name);
        return "(string_content) @str (#eq? @str \"{$literal}\")";
    }

    private function signatureMatch(string $symbolType, string $name): ?string
    {
        $literal = $this->escapeLiteral($name);
        return match ($symbolType) {
            'function', 'hook' => "(function_call_expression function: (name) @fn (#eq? @fn \"{$literal}\") arguments: (arguments) @args)",
            'method' => "(member_call_expression name: (name) @method (#eq? @method \"{$literal}\") arguments: (arguments) @args)",
            default => null,
        };
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
            $segments = explode('::', $name);
            $name = (string) end($segments);
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function queryBySymbol(array $symbol): ?string
    {
        $symbolType = (string) ($symbol['symbol_type'] ?? '');
        $name = $this->resolveName($symbol);
        if ($name === '') {
            return null;
        }

        return match ($symbolType) {
            'function', 'hook' => $this->functionCall($name),
            'method' => $this->methodCall($name),
            'class', 'interface', 'trait', 'constant' => $this->classReference($symbol, $name),
            'service' => $this->serviceReference($name),
            'route', 'permission', 'config_schema', 'library',
            'module_info', 'theme_info',
            'link_menu', 'link_task', 'link_action', 'link_contextual',
            'breakpoint' => $this->stringReference($name),
            default => null,
        };
    }

    private function escapeLiteral(string $value): string
    {
        return addcslashes($value, "\\\"");
    }
}
