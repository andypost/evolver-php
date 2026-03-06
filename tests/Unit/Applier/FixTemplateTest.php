<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Applier;

use DrupalEvolver\Applier\FixTemplate;
use PHPUnit\Framework\TestCase;

class FixTemplateTest extends TestCase
{
    private FixTemplate $template;

    protected function setUp(): void
    {
        $this->template = new FixTemplate();
    }

    public function testFunctionRename(): void
    {
        $templateJson = json_encode([
            'type' => 'function_rename',
            'old' => 'drupal_render',
            'new' => "\\Drupal::service('renderer')->render",
        ]);

        $result = $this->template->apply('drupal_render($element)', $templateJson);
        $this->assertSame("\\Drupal::service('renderer')->render(\$element)", $result);
    }

    public function testParameterInsert(): void
    {
        $templateJson = json_encode([
            'type' => 'parameter_insert',
            'position' => 1,
            'value' => 'NULL',
        ]);

        $result = $this->template->apply('some_func($a, $b)', $templateJson);
        $this->assertSame('some_func($a, NULL, $b)', $result);
    }

    public function testStringReplace(): void
    {
        $templateJson = json_encode([
            'type' => 'string_replace',
            'old' => 'old.service.name',
            'new' => 'new.service.name',
        ]);

        $result = $this->template->apply('old.service.name', $templateJson);
        $this->assertSame('new.service.name', $result);
    }

    public function testNamespaceMove(): void
    {
        $templateJson = json_encode([
            'type' => 'namespace_move',
            'old_namespace' => 'Drupal\\Core\\Old',
            'new_namespace' => 'Drupal\\Core\\New',
        ]);

        $result = $this->template->apply('use Drupal\\Core\\Old\\SomeClass;', $templateJson);
        $this->assertStringContainsString('Drupal\\Core\\New', $result);
    }

    public function testInvalidTemplate(): void
    {
        $this->assertNull($this->template->apply('foo', 'not json'));
        $this->assertNull($this->template->apply('foo', json_encode(['type' => 'unknown'])));
    }
}
