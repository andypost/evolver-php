<?php

declare(strict_types=1);

namespace DrupalEvolver\Pattern;

use DrupalEvolver\Symbol\SymbolType;

class QueryGenerator
{
    public const QUERY_VERSION = 2;

    public function generate(string $changeType, array $symbol): ?QueryPattern
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
            return QueryPattern::serviceReference($name);
        }

        if (in_array($changeType, ['route_removed', 'route_renamed', 'route_changed'], true)) {
            return QueryPattern::stringReference($name);
        }

        if (in_array($changeType, ['permission_removed', 'permission_renamed'], true)) {
            return QueryPattern::stringReference($name);
        }

        if (in_array($changeType, ['config_key_removed', 'config_key_renamed', 'config_key_changed'], true)) {
            return QueryPattern::stringReference($name);
        }

        if (in_array($changeType, ['library_removed', 'library_changed', 'library_css_removed', 'library_js_removed', 'library_dependency_removed', 'library_deprecated'], true)) {
            return QueryPattern::libraryReference($name);
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
            return QueryPattern::globalVariableReference($name);
        }

        if ($changeType === 'constant_replaced') {
            return QueryPattern::constantReference($name);
        }

        if ($changeType === 'variable_access_replaced') {
            return QueryPattern::propertyAccess($name);
        }

        if (in_array($changeType, ['sdc_include_removed', 'sdc_embed_removed', 'sdc_call_removed', 'sdc_function_removed'], true)) {
            return $this->twigReference($name);
        }

        if ($changeType === 'function_call_rewrite') {
            return QueryPattern::functionCall($name);
        }

        if (str_ends_with($changeType, '_removed') || str_ends_with($changeType, '_renamed')) {
            return $this->queryBySymbol($symbol);
        }

        return null;
    }

    private function functionCall(string $name): QueryPattern
    {
        return QueryPattern::functionCall($name);
    }

    private function methodCall(string $name): QueryPattern
    {
        return QueryPattern::methodCall($name);
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function classReference(array $symbol, string $name): QueryPattern
    {
        $fqn = ltrim((string) ($symbol['fqn'] ?? ''), '\\');
        if ($fqn !== '' && str_contains($fqn, '\\')) {
            return QueryPattern::classReference($fqn);
        }

        return QueryPattern::classReference($name);
    }

    private function serviceReference(string $name): QueryPattern
    {
        return QueryPattern::serviceReference($name);
    }

    private function stringReference(string $name): QueryPattern
    {
        return QueryPattern::stringReference($name);
    }

    private function signatureMatch(SymbolType $symbolType, string $name): ?QueryPattern
    {
        return match ($symbolType) {
            SymbolType::FunctionSymbol, SymbolType::Hook => QueryPattern::signatureMatch($name),
            SymbolType::Method => QueryPattern::signatureMatch($name),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function queryBySymbol(array $symbol): ?QueryPattern
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
            SymbolType::DrupalLibrary, SymbolType::LibraryUsage => QueryPattern::libraryReference($name),
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

    private function eventReference(string $name): QueryPattern
    {
        return QueryPattern::eventReference($name);
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function overrideReference(array $symbol, string $name): QueryPattern
    {
        $parentSymbol = $symbol['parent_symbol'] ?? null;
        if (!$parentSymbol) {
            return QueryPattern::create('', 'inheritance_impact');
        }

        $methodName = $symbol['name'] ?? null;
        if (!$methodName) {
            return QueryPattern::create('', 'inheritance_impact');
        }

        $parentShort = basename(str_replace('\\', '/', $parentSymbol));
        return QueryPattern::overrideReference($parentShort, $methodName);
    }

    private function twigReference(string $name): QueryPattern
    {
        return QueryPattern::twigReference($name);
    }

    /**
     * Match procedural hook function definitions (e.g. mymodule_form_alter).
     * Used for hook→attribute modernization suggestions.
     */
    private function proceduralHookReference(string $name): QueryPattern
    {
        $literal = self::escapeLiteral($name);
        $regex = ".*_{$literal}$";
        $regexLiteral = self::escapeLiteral($regex);
        $pattern = "(function_definition name: (name) @fn (#match? @fn \"{$regexLiteral}\"))";
        
        return QueryPattern::create($pattern, 'hook_to_attribute', "Procedural hook {$name}");
    }

    /**
     * Escape a literal for use in tree-sitter query.
     */
    private static function escapeLiteral(string $value): string
    {
        return addcslashes($value, "\\\"");
    }
}
