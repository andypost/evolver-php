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

        $this->assertStringContainsString('function_call_expression', $query);
        $this->assertStringContainsString('drupal_render', $query);
    }

    public function testMethodRemoved(): void
    {
        $query = $this->generator->generate('method_removed', [
            'name' => 'MyClass::oldMethod',
            'symbol_type' => 'method',
        ]);

        $this->assertStringContainsString('member_call_expression', $query);
        $this->assertStringContainsString('oldMethod', $query);
    }

    public function testClassRemoved(): void
    {
        $query = $this->generator->generate('class_removed', [
            'name' => 'OldClass',
            'fqn' => 'Drupal\\Core\\OldClass',
            'symbol_type' => 'class',
        ]);

        $this->assertStringContainsString('qualified_name', $query);
        $this->assertStringContainsString('Drupal\\\\\\\\Core\\\\\\\\OldClass', $query);
        $this->assertStringNotContainsString('cls_short', $query);
    }

    public function testServiceRemoved(): void
    {
        $query = $this->generator->generate('service_removed', [
            'name' => 'old.service',
            'symbol_type' => 'service',
        ]);

        $this->assertStringContainsString('string_content', $query);
        $this->assertStringContainsString('old.service', $query);
    }

    public function testSignatureChanged(): void
    {
        $query = $this->generator->generate('signature_changed', [
            'name' => 'some_func',
            'symbol_type' => 'function',
        ]);

        $this->assertStringContainsString('function_call_expression', $query);
        $this->assertStringContainsString('arguments', $query);
    }

    public function testFunctionRenamed(): void
    {
        $query = $this->generator->generate('function_renamed', [
            'name' => 'drupal_render',
            'symbol_type' => 'function',
        ]);

        $this->assertStringContainsString('function_call_expression', $query);
        $this->assertStringContainsString('drupal_render', $query);
    }

    public function testServiceClassChanged(): void
    {
        $query = $this->generator->generate('service_class_changed', [
            'name' => 'my.service',
            'symbol_type' => 'service',
        ]);

        $this->assertStringContainsString('string_content', $query);
        $this->assertStringContainsString('my.service', $query);
    }

    public function testConfigKeyRemoved(): void
    {
        $query = $this->generator->generate('config_key_removed', [
            'name' => 'my_module.settings',
            'symbol_type' => 'config_schema',
        ]);

        $this->assertStringContainsString('string_content', $query);
        $this->assertStringContainsString('my_module.settings', $query);
    }

    public function testDeprecatedAddedUsesSymbolTypeQuery(): void
    {
        $query = $this->generator->generate('deprecated_added', [
            'name' => 'old_method',
            'symbol_type' => 'method',
        ]);

        $this->assertStringContainsString('member_call_expression', $query);
        $this->assertStringContainsString('old_method', $query);
    }

    public function testClassRenamedMatchesFqnAndShortName(): void
    {
        $query = $this->generator->generate('class_renamed', [
            'name' => 'SomeClass',
            'fqn' => 'Drupal\\Core\\Old\\SomeClass',
            'symbol_type' => 'class',
        ]);

        $this->assertStringContainsString('qualified_name', $query);
        $this->assertStringContainsString('#match?', $query);
        $this->assertStringContainsString('Drupal\\\\\\\\Core\\\\\\\\Old\\\\\\\\SomeClass', $query);
        $this->assertStringNotContainsString('cls_short', $query);
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
