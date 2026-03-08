<?php

declare(strict_types=1);

namespace DrupalEvolver\Symbol;

enum SymbolType: string
{
    case ClassSymbol = 'class';
    case InterfaceSymbol = 'interface';
    case TraitSymbol = 'trait';
    case FunctionSymbol = 'function';
    case Method = 'method';
    case Constant = 'constant';
    case Service = 'service';
    case EventSubscriber = 'event_subscriber';
    case Hook = 'hook';
    case DrupalEvent = 'drupal_event';
    case PluginDefinition = 'plugin_definition';
    case DrupalLibrary = 'drupal_library';
    case Library = 'library';
    case LibraryUsage = 'library_usage';
    case Variable = 'variable';
    case JsSymbol = 'js_symbol';
    case DrupalPermission = 'drupal_permission';
    case Permission = 'permission';
    case DrupalRoute = 'drupal_route';
    case Route = 'route';
    case ConfigSchema = 'config_schema';
    case ConfigExport = 'config_export';
    case ModuleInfo = 'module_info';
    case ThemeInfo = 'theme_info';
    case ProfileInfo = 'profile_info';
    case ThemeEngineInfo = 'theme_engine_info';
    case LinkMenu = 'link_menu';
    case LinkTask = 'link_task';
    case LinkAction = 'link_action';
    case LinkContextual = 'link_contextual';
    case Breakpoint = 'breakpoint';
    case RecipeManifest = 'recipe_manifest';
    case SdcComponent = 'sdc_component';
    case SdcInclude = 'sdc_include';
    case SdcEmbed = 'sdc_embed';
    case SdcCall = 'sdc_call';
    case SdcFunction = 'sdc_function';
    case TwigInclude = 'twig_include';
    case TwigEmbed = 'twig_embed';
    case TwigExtends = 'twig_extends';
    case TwigComponent = 'twig_component';
    case TwigFunction = 'twig_function';
    case TwigTag = 'twig_tag';
    case TwigBlock = 'twig_block';
    case TwigSet = 'twig_set';
    case TwigFor = 'twig_for';
    case TwigIf = 'twig_if';
    case TwigSymbol = 'twig_symbol';
    case TwigVariable = 'twig_variable';
    case TwigFilter = 'twig_filter';
    case CssSelector = 'css_selector';

    /**
     * @param array<string, mixed> $symbol
     */
    public static function fromSymbol(array $symbol): ?self
    {
        $type = $symbol['symbol_type'] ?? null;

        if ($type instanceof self) {
            return $type;
        }

        if (!is_string($type) || $type === '') {
            return null;
        }

        return self::tryFrom($type);
    }

    /**
     * @param array<string, mixed> $symbol
     */
    public static function valueFromSymbol(array $symbol, string $default = ''): string
    {
        $type = $symbol['symbol_type'] ?? null;

        if ($type instanceof self) {
            return $type->value;
        }

        if (is_string($type)) {
            return $type;
        }

        return $default;
    }

    public function isClassLike(): bool
    {
        return match ($this) {
            self::ClassSymbol, self::InterfaceSymbol, self::TraitSymbol => true,
            default => false,
        };
    }

    public function isHookLike(): bool
    {
        return $this === self::Hook;
    }

    public static function isHookLikeValue(self|string|null $type): bool
    {
        $value = match (true) {
            $type instanceof self => $type->value,
            is_string($type) => $type,
            default => '',
        };

        return $value !== '' && str_contains($value, 'hook');
    }
}
