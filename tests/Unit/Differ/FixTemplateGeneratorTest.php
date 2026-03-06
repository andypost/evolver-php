<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Differ;

use DrupalEvolver\Differ\FixTemplateGenerator;
use PHPUnit\Framework\TestCase;

class FixTemplateGeneratorTest extends TestCase
{
    private FixTemplateGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new FixTemplateGenerator();
    }

    public function testFunctionRenamedGeneratesFunctionRenameTemplate(): void
    {
        $templateJson = $this->generator->generate(
            'function_renamed',
            ['name' => 'old_func', 'fqn' => 'Drupal\\Core\\old_func'],
            ['name' => 'new_func', 'fqn' => 'Drupal\\Core\\new_func'],
        );

        $this->assertNotNull($templateJson);
        $template = json_decode((string) $templateJson, true);
        $this->assertSame('function_rename', $template['type']);
        $this->assertSame('old_func', $template['old']);
        $this->assertSame('new_func', $template['new']);
    }

    public function testServiceRenameGeneratesStringReplaceTemplate(): void
    {
        $templateJson = $this->generator->generate(
            'service_renamed',
            ['name' => 'old.service', 'fqn' => 'old.service'],
            ['name' => 'new.service', 'fqn' => 'new.service'],
        );

        $this->assertNotNull($templateJson);
        $template = json_decode((string) $templateJson, true);
        $this->assertSame('string_replace', $template['type']);
        $this->assertSame('old.service', $template['old']);
        $this->assertSame('new.service', $template['new']);
    }

    public function testClassNamespaceRenameGeneratesNamespaceMoveTemplate(): void
    {
        $templateJson = $this->generator->generate(
            'class_renamed',
            ['name' => 'SomeClass', 'fqn' => 'Drupal\\Core\\Old\\SomeClass'],
            ['name' => 'SomeClass', 'fqn' => 'Drupal\\Core\\New\\SomeClass'],
        );

        $this->assertNotNull($templateJson);
        $template = json_decode((string) $templateJson, true);
        $this->assertSame('namespace_move', $template['type']);
        $this->assertSame('Drupal\\Core\\Old', $template['old_namespace']);
        $this->assertSame('Drupal\\Core\\New', $template['new_namespace']);
        $this->assertSame('SomeClass', $template['class']);
    }

    public function testClassRenameInSameNamespaceFallsBackToStringReplace(): void
    {
        $templateJson = $this->generator->generate(
            'class_renamed',
            ['name' => 'OldClass', 'fqn' => 'Drupal\\Core\\OldClass'],
            ['name' => 'NewClass', 'fqn' => 'Drupal\\Core\\NewClass'],
        );

        $this->assertNotNull($templateJson);
        $template = json_decode((string) $templateJson, true);
        $this->assertSame('string_replace', $template['type']);
        $this->assertSame('Drupal\\Core\\OldClass', $template['old']);
        $this->assertSame('Drupal\\Core\\NewClass', $template['new']);
    }

    public function testSimpleParameterAddedGeneratesParameterInsertTemplate(): void
    {
        $templateJson = $this->generator->generate(
            'signature_changed',
            ['symbol_type' => 'function', 'name' => 'some_func', 'fqn' => 'some_func'],
            ['symbol_type' => 'function', 'name' => 'some_func', 'fqn' => 'some_func'],
            [[
                'type' => 'parameter_added',
                'position' => 2,
                'param' => ['name' => '$context'],
            ]],
        );

        $this->assertNotNull($templateJson);
        $template = json_decode((string) $templateJson, true);
        $this->assertSame('parameter_insert', $template['type']);
        $this->assertSame('some_func', $template['function']);
        $this->assertSame(2, $template['position']);
        $this->assertSame('NULL', $template['value']);
    }

    public function testComplexSignatureChangeDoesNotGenerateTemplate(): void
    {
        $templateJson = $this->generator->generate(
            'signature_changed',
            ['symbol_type' => 'function', 'name' => 'some_func', 'fqn' => 'some_func'],
            ['symbol_type' => 'function', 'name' => 'some_func', 'fqn' => 'some_func'],
            [
                ['type' => 'parameter_added', 'position' => 1],
                ['type' => 'parameter_type_changed', 'position' => 0, 'old_type' => 'string', 'new_type' => 'int'],
            ],
        );

        $this->assertNull($templateJson);
    }
}
