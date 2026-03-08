<?php

declare(strict_types=1);

namespace DrupalEvolver\Pattern;

use DrupalEvolver\Symbol\SymbolType;

class QueryGenerator
{
    public function generate(string $changeType, array $symbol): ?string
    {
        $type = SymbolType::fromSymbol($symbol);
        $name = $this->resolveName($symbol);
        if ($name === '') {
            return null;
        }

        if ($changeType === 'signature_changed') {
            if ($type === null) {
                return null;
            }

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

        if (in_array($changeType, ['library_removed', 'library_changed', 'library_css_removed', 'library_js_removed', 'library_dependency_removed', 'library_deprecated'], true)) {
            return $this->libraryReference($name);
        }

        if ($changeType === 'hook_to_attribute') {
            return $this->proceduralHookReference($name);
        }

        if ($changeType === 'event_removed') {
            return $this->eventReference($name);
        }

        if ($changeType === 'inheritance_impact') {
            return $this->overrideReference($symbol, $name);
        }

        if (in_array($changeType, [
            'module_info_changed',
            'module_dependencies_changed',
            'profile_info_changed',
            'profile_dependencies_changed',
            'theme_info_changed',
            'theme_base_changed',
            'theme_engine_info_changed',
            'link_menu_changed',
            'link_task_changed',
            'link_action_changed',
            'link_contextual_changed',
            'config_object_changed',
            'recipe_changed',
            'recipe_install_changed',
        ], true)) {
            return $this->queryBySymbol($symbol);
        }

        if ($changeType === 'deprecated_added') {
            return $this->queryBySymbol($symbol);
        }

        if ($changeType === 'global_replaced') {
            return $this->globalVariableReference($name);
        }

        if ($changeType === 'constant_replaced') {
            return $this->constantReference($name);
        }

        if ($changeType === 'variable_access_replaced') {
            return $this->propertyAccess($name);
        }

        if (in_array($changeType, ['sdc_include_removed', 'sdc_embed_removed', 'sdc_call_removed', 'sdc_function_removed'], true)) {
            return $this->twigReference($name);
        }

        if ($changeType === 'function_call_rewrite') {
            return $this->functionCall($name);
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

    private function signatureMatch(SymbolType $symbolType, string $name): ?string
    {
        $literal = $this->escapeLiteral($name);
        return match ($symbolType) {
            SymbolType::FunctionSymbol, SymbolType::Hook => "(function_call_expression function: (name) @fn (#eq? @fn \"{$literal}\") arguments: (arguments) @args)",
            SymbolType::Method => "(member_call_expression name: (name) @method (#eq? @method \"{$literal}\") arguments: (arguments) @args)",
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
        $symbolType = SymbolType::fromSymbol($symbol);
        $name = $this->resolveName($symbol);
        if ($symbolType === null || $name === '') {
            return null;
        }

        return match ($symbolType) {
            SymbolType::FunctionSymbol, SymbolType::Hook => $this->functionCall($name),
            SymbolType::Method => $this->methodCall($name),
            SymbolType::ClassSymbol, SymbolType::InterfaceSymbol, SymbolType::TraitSymbol, SymbolType::Constant => $this->classReference($symbol, $name),
            SymbolType::Service => $this->serviceReference($name),
            SymbolType::DrupalLibrary, SymbolType::LibraryUsage => $this->libraryReference($name),
            SymbolType::Route, SymbolType::DrupalRoute,
            SymbolType::Permission, SymbolType::DrupalPermission,
            SymbolType::ConfigSchema, SymbolType::Library,
            SymbolType::ModuleInfo, SymbolType::ThemeInfo, SymbolType::ProfileInfo, SymbolType::ThemeEngineInfo,
            SymbolType::LinkMenu, SymbolType::LinkTask, SymbolType::LinkAction, SymbolType::LinkContextual,
            SymbolType::Breakpoint, SymbolType::ConfigExport, SymbolType::RecipeManifest, SymbolType::SdcComponent => $this->stringReference($name),
            SymbolType::SdcInclude, SymbolType::SdcEmbed, SymbolType::SdcCall, SymbolType::SdcFunction,
            SymbolType::TwigInclude, SymbolType::TwigEmbed, SymbolType::TwigExtends, SymbolType::TwigComponent => $this->twigReference($name),
            SymbolType::PluginDefinition => $this->classReference($symbol, $name),
            SymbolType::EventSubscriber => $this->eventReference($name),
            default => null,
        };
    }

    private function eventReference(string $name): string
    {
        $literal = $this->escapeLiteral($name);
        return "[
            (string_content) @str (#eq? @str \"{$literal}\")
            (name) @const (#eq? @const \"{$literal}\")
        ] @item";
    }

    private function overrideReference(array $symbol, string $name): ?string
    {
        $parentSymbol = $symbol['parent_symbol'] ?? null;
        if (!$parentSymbol) return null;

        $methodName = $symbol['name'] ?? null;
        if (!$methodName) return null;

        $parentShort = basename(str_replace('\\', '/', $parentSymbol));
        $escapedParent = $this->escapeLiteral($parentShort);
        $escapedMethod = $this->escapeLiteral($methodName);

        // Find classes extending the parent and declaring the method
        return "(class_declaration 
            (base_clause (name) @base (#eq? @base \"{$escapedParent}\"))
            (declaration_list (method_declaration name: (name) @method (#eq? @method \"{$escapedMethod}\")))
        ) @item";
    }

    private function twigReference(string $name): string
    {
        $literal = $this->escapeLiteral($name);
        return "[
            (tag_statement) 
            (output_directive)
        ] @item";
    }

    /**
     * Match global variable declarations and $GLOBALS access.
     *
     * global $user; OR $GLOBALS['user']
     */
    private function globalVariableReference(string $name): string
    {
        $literal = $this->escapeLiteral($name);
        return "(global_declaration (variable_name) @var (#eq? @var \"\${$literal}\"))";
    }

    /**
     * Match constant references (bare identifiers like LANGUAGE_NONE).
     */
    private function constantReference(string $name): string
    {
        $literal = $this->escapeLiteral($name);
        return "(name) @const (#eq? @const \"{$literal}\")";
    }

    /**
     * Match property access patterns like $node->nid.
     */
    private function propertyAccess(string $name): string
    {
        $literal = $this->escapeLiteral($name);
        return "(member_access_expression name: (name) @prop (#eq? @prop \"{$literal}\"))";
    }

    /**
     * Match library references in attach_library() calls and #attached arrays.
     * Library names follow the pattern 'module/library_name'.
     */
    private function libraryReference(string $name): string
    {
        $literal = $this->escapeLiteral($name);
        return "(string_content) @lib (#eq? @lib \"{$literal}\")";
    }

    /**
     * Match procedural hook function definitions (e.g. mymodule_form_alter).
     * Used for hook→attribute modernization suggestions.
     */
    private function proceduralHookReference(string $name): string
    {
        // Match function calls to the hook name (core invoking it)
        // and function definitions implementing it
        $literal = $this->escapeLiteral($name);
        $regex = ".*_{$literal}$";
        $regexLiteral = $this->escapeLiteral($regex);
        return "(function_definition name: (name) @fn (#match? @fn \"{$regexLiteral}\"))";
    }

    private function escapeLiteral(string $value): string
    {
        return addcslashes($value, "\\\"");
    }
}
