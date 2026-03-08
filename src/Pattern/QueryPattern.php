<?php

declare(strict_types=1);

namespace DrupalEvolver\Pattern;

/**
 * Value object representing a tree-sitter query pattern.
 */
final class QueryPattern
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $changeType,
        public readonly ?string $description = null,
        public readonly int $version = QueryGenerator::QUERY_VERSION,
    ) {}

    /**
     * Create a query pattern.
     */
    public static function create(
        string $pattern,
        string $changeType,
        ?string $description = null,
    ): self {
        return new self($pattern, $changeType, $description);
    }

    /**
     * Create a function call pattern.
     */
    public static function functionCall(string $functionName): self
    {
        $literal = self::escapeLiteral($functionName);
        return new self(
            pattern: "(function_call_expression function: (name) @fn (#eq? @fn \"{$literal}\"))",
            changeType: 'function_call_rewrite',
            description: "Function call to {$functionName}",
        );
    }

    /**
     * Create a method call pattern.
     */
    public static function methodCall(string $methodName): self
    {
        $literal = self::escapeLiteral($methodName);
        return new self(
            pattern: "(member_call_expression name: (name) @method (#eq? @method \"{$literal}\"))",
            changeType: 'method_call_rewrite',
            description: "Method call to {$methodName}",
        );
    }

    /**
     * Create a service reference pattern.
     */
    public static function serviceReference(string $serviceName): self
    {
        $literal = self::escapeLiteral($serviceName);
        return new self(
            pattern: "(string_content) @svc (#eq? @svc \"{$literal}\")",
            changeType: 'service_reference',
            description: "Service reference to {$serviceName}",
        );
    }

    /**
     * Create a string reference pattern.
     */
    public static function stringReference(string $value): self
    {
        $literal = self::escapeLiteral($value);
        return new self(
            pattern: "(string_content) @str (#eq? @str \"{$literal}\")",
            changeType: 'string_match',
            description: "String value: {$value}",
        );
    }

    /**
     * Create a class reference pattern.
     */
    public static function classReference(string $fqn): self
    {
        $literal = self::escapeLiteral($fqn);
        $regex = '^\\\\?' . preg_quote($fqn, '/') . '$';
        $regexLiteral = self::escapeLiteral($regex);

        $patterns = [
            "(namespace_use_clause (qualified_name) @cls_fqn (#match? @cls_fqn \"{$regexLiteral}\"))",
            "(object_creation_expression (qualified_name) @cls_fqn (#match? @cls_fqn \"{$regexLiteral}\"))",
            "(class_constant_access_expression (qualified_name) @cls_fqn (#match? @cls_fqn \"{$regexLiteral}\"))",
            "(scoped_call_expression (qualified_name) @cls_fqn (#match? @cls_fqn \"{$regexLiteral}\"))",
            "(base_clause (qualified_name) @cls_fqn (#match? @cls_fqn \"{$regexLiteral}\"))",
        ];

        return new self(
            pattern: implode("\n", $patterns),
            changeType: 'class_reference',
            description: "Class reference to {$fqn}",
        );
    }

    /**
     * Create a library reference pattern.
     */
    public static function libraryReference(string $libraryName): self
    {
        $literal = self::escapeLiteral($libraryName);
        return new self(
            pattern: "(string_content) @lib (#eq? @lib \"{$literal}\")",
            changeType: 'library_reference',
            description: "Library reference to {$libraryName}",
        );
    }

    /**
     * Create a signature match pattern.
     */
    public static function signatureMatch(string $functionName, bool $withArgs = true): self
    {
        $literal = self::escapeLiteral($functionName);
        $argsPattern = $withArgs ? ' arguments: (arguments) @args' : '';
        
        return new self(
            pattern: "(function_call_expression function: (name) @fn (#eq? @fn \"{$literal}\"){$argsPattern})",
            changeType: 'signature_changed',
            description: "Function signature match for {$functionName}",
        );
    }

    /**
     * Create a global variable reference pattern.
     */
    public static function globalVariableReference(string $variableName): self
    {
        $literal = self::escapeLiteral($variableName);
        return new self(
            pattern: "(global_declaration (variable_name) @var (#eq? @var \"\${$literal}\"))",
            changeType: 'global_replaced',
            description: "Global variable \${$variableName}",
        );
    }

    /**
     * Create a constant reference pattern.
     */
    public static function constantReference(string $constantName): self
    {
        $literal = self::escapeLiteral($constantName);
        return new self(
            pattern: "(name) @const (#eq? @const \"{$literal}\")",
            changeType: 'constant_replaced',
            description: "Constant {$constantName}",
        );
    }

    /**
     * Create a property access pattern.
     */
    public static function propertyAccess(string $propertyName): self
    {
        $literal = self::escapeLiteral($propertyName);
        return new self(
            pattern: "(member_access_expression name: (name) @prop (#eq? @prop \"{$literal}\"))",
            changeType: 'variable_access_replaced',
            description: "Property access ->{$propertyName}",
        );
    }

    /**
     * Create an event reference pattern.
     */
    public static function eventReference(string $eventName): self
    {
        $literal = self::escapeLiteral($eventName);
        $pattern = "[
            (string_content) @str (#eq? @str \"{$literal}\")
            (name) @const (#eq? @const \"{$literal}\")
        ] @item";
        
        return new self(
            pattern: $pattern,
            changeType: 'event_removed',
            description: "Event {$eventName}",
        );
    }

    /**
     * Create a class override reference pattern.
     */
    public static function overrideReference(string $parentClass, string $methodName): self
    {
        $escapedParent = self::escapeLiteral($parentClass);
        $escapedMethod = self::escapeLiteral($methodName);

        $pattern = "(class_declaration
            (base_clause (name) @base (#eq? @base \"{$escapedParent}\"))
            (declaration_list (method_declaration name: (name) @method (#eq? @method \"{$escapedMethod}\")))
        ) @item";
        
        return new self(
            pattern: $pattern,
            changeType: 'inheritance_impact',
            description: "Method {$methodName} overriding {$parentClass}",
        );
    }

    /**
     * Create a Twig reference pattern.
     */
    public static function twigReference(string $componentName): self
    {
        $literal = self::escapeLiteral($componentName);
        $pattern = "[
            (tag_statement)
            (output_directive)
        ] @item";
        
        return new self(
            pattern: $pattern,
            changeType: 'sdc_reference',
            description: "Twig component {$componentName}",
        );
    }

    /**
     * Escape a literal for use in tree-sitter query.
     */
    private static function escapeLiteral(string $value): string
    {
        return addcslashes($value, "\\\"");
    }

    /**
     * Convert to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pattern' => $this->pattern,
            'change_type' => $this->changeType,
            'description' => $this->description,
            'version' => $this->version,
        ];
    }

    /**
     * Check if this pattern is valid.
     */
    public function isValid(): bool
    {
        return $this->pattern !== '' && $this->changeType !== '';
    }

    /**
     * Get the pattern hash for caching.
     */
    public function getHash(): string
    {
        return hash('sha256', $this->pattern . '|' . $this->changeType . '|' . $this->version);
    }

    /**
     * Convert to string for array storage.
     */
    public function __toString(): string
    {
        return $this->pattern;
    }
}
