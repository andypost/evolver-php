<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Pattern;

use DrupalEvolver\Pattern\QueryGenerator;
use PHPUnit\Framework\TestCase;

class QueryGeneratorTest extends TestCase
{
    private QueryGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new QueryGenerator();
    }

    public function testFunctionRemoved(): void
    {
        $query = $this->generator->generate('function_removed', [
            'name' => 'drupal_render',
            'symbol_type' => 'function',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('function_call_expression', $query->pattern);
        $this->assertStringContainsString('drupal_render', $query->pattern);
    }

    public function testMethodRemoved(): void
    {
        $query = $this->generator->generate('method_removed', [
            'name' => 'MyClass::oldMethod',
            'symbol_type' => 'method',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('member_call_expression', $query->pattern);
        $this->assertStringContainsString('oldMethod', $query->pattern);
    }

    public function testClassRemoved(): void
    {
        $query = $this->generator->generate('class_removed', [
            'name' => 'OldClass',
            'fqn' => 'Drupal\\Core\\OldClass',
            'symbol_type' => 'class',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('qualified_name', $query->pattern);
        $this->assertStringContainsString('Drupal\\\\\\\\Core\\\\\\\\OldClass', $query->pattern);
        $this->assertStringNotContainsString('cls_short', $query->pattern);
    }

    public function testServiceRemoved(): void
    {
        $query = $this->generator->generate('service_removed', [
            'name' => 'old.service',
            'symbol_type' => 'service',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('string_content', $query->pattern);
        $this->assertStringContainsString('old.service', $query->pattern);
    }

    public function testSignatureChanged(): void
    {
        $query = $this->generator->generate('signature_changed', [
            'name' => 'some_func',
            'symbol_type' => 'function',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('function_call_expression', $query->pattern);
        $this->assertStringContainsString('arguments', $query->pattern);
    }

    public function testFunctionRenamed(): void
    {
        $query = $this->generator->generate('function_renamed', [
            'name' => 'drupal_render',
            'symbol_type' => 'function',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('function_call_expression', $query->pattern);
        $this->assertStringContainsString('drupal_render', $query->pattern);
    }

    public function testServiceClassChanged(): void
    {
        $query = $this->generator->generate('service_class_changed', [
            'name' => 'my.service',
            'symbol_type' => 'service',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('string_content', $query->pattern);
        $this->assertStringContainsString('my.service', $query->pattern);
    }

    public function testConfigKeyRemoved(): void
    {
        $query = $this->generator->generate('config_key_removed', [
            'name' => 'my_module.settings',
            'symbol_type' => 'config_schema',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('string_content', $query->pattern);
        $this->assertStringContainsString('my_module.settings', $query->pattern);
    }

    public function testModuleDependenciesChangedUsesSymbolQuery(): void
    {
        $query = $this->generator->generate('module_dependencies_changed', [
            'name' => 'example',
            'symbol_type' => 'module_info',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('string_content', $query->pattern);
        $this->assertStringContainsString('example', $query->pattern);
    }

    public function testConfigObjectChangedUsesSymbolQuery(): void
    {
        $query = $this->generator->generate('config_object_changed', [
            'name' => 'system.site',
            'symbol_type' => 'config_export',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('string_content', $query->pattern);
        $this->assertStringContainsString('system.site', $query->pattern);
    }

    public function testDeprecatedAddedUsesSymbolTypeQuery(): void
    {
        $query = $this->generator->generate('deprecated_added', [
            'name' => 'old_method',
            'symbol_type' => 'method',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('member_call_expression', $query->pattern);
        $this->assertStringContainsString('old_method', $query->pattern);
    }

    public function testClassRenamedMatchesFqnAndShortName(): void
    {
        $query = $this->generator->generate('class_renamed', [
            'name' => 'SomeClass',
            'fqn' => 'Drupal\\Core\\Old\\SomeClass',
            'symbol_type' => 'class',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('qualified_name', $query->pattern);
        $this->assertStringContainsString('#match?', $query->pattern);
        $this->assertStringContainsString('Drupal\\\\\\\\Core\\\\\\\\Old\\\\\\\\SomeClass', $query->pattern);
        $this->assertStringNotContainsString('cls_short', $query->pattern);
    }

    public function testGlobalReplaced(): void
    {
        $query = $this->generator->generate('global_replaced', [
            'name' => 'user',
            'symbol_type' => 'function',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('global_declaration', $query->pattern);
        $this->assertStringContainsString('$user', $query->pattern);
    }

    public function testConstantReplaced(): void
    {
        $query = $this->generator->generate('constant_replaced', [
            'name' => 'LANGUAGE_NONE',
            'symbol_type' => 'constant',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('LANGUAGE_NONE', $query->pattern);
    }

    public function testVariableAccessReplaced(): void
    {
        $query = $this->generator->generate('variable_access_replaced', [
            'name' => 'nid',
            'symbol_type' => 'function',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('member_access_expression', $query->pattern);
        $this->assertStringContainsString('nid', $query->pattern);
    }

    public function testFunctionCallRewrite(): void
    {
        $query = $this->generator->generate('function_call_rewrite', [
            'name' => 'check_plain',
            'symbol_type' => 'function',
        ]);

        $this->assertNotNull($query);
        $this->assertStringContainsString('function_call_expression', $query->pattern);
        $this->assertStringContainsString('check_plain', $query->pattern);
    }

    public function testUnknownChangeType(): void
    {
        $query = $this->generator->generate('unknown_type', [
            'name' => 'foo',
            'symbol_type' => 'function',
        ]);

        $this->assertNull($query);
    }
}
