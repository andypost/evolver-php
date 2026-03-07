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

    public function testFunctionCallRewrite(): void
    {
        $templateJson = json_encode([
            'type' => 'function_call_rewrite',
            'old' => 'check_plain',
            'new' => '\\Drupal\\Component\\Utility\\Html::escape',
        ]);

        $result = $this->template->apply('check_plain($text)', $templateJson);
        $this->assertSame('\\Drupal\\Component\\Utility\\Html::escape($text)', $result);
    }

    public function testFunctionCallRewriteWithMultipleArgs(): void
    {
        $templateJson = json_encode([
            'type' => 'function_call_rewrite',
            'old' => 'drupal_alter',
            'new' => '\\Drupal::moduleHandler()->alter',
        ]);

        $result = $this->template->apply("drupal_alter('baz', \$my_baz)", $templateJson);
        $this->assertSame("\\Drupal::moduleHandler()->alter('baz', \$my_baz)", $result);
    }

    public function testMethodChain(): void
    {
        $templateJson = json_encode([
            'type' => 'method_chain',
            'object' => '\\Drupal::entityTypeManager()',
            'chain' => ['getStorage', 'load'],
            'args_map' => ['getStorage' => [0], 'load' => [1]],
        ]);

        $result = $this->template->apply("entity_load('node', 123)", $templateJson);
        $this->assertSame("\\Drupal::entityTypeManager()->getStorage('node')->load(123)", $result);
    }

    public function testVariableAccess(): void
    {
        $templateJson = json_encode([
            'type' => 'variable_access',
            'property' => 'nid',
            'getter' => 'id',
        ]);

        $result = $this->template->apply('$node->nid', $templateJson);
        $this->assertSame('$node->id()', $result);
    }

    public function testVariableAccessDoesNotMatchMethodCall(): void
    {
        $templateJson = json_encode([
            'type' => 'variable_access',
            'property' => 'nid',
            'getter' => 'id',
        ]);

        // Should NOT rewrite already-method-call forms
        $result = $this->template->apply('$node->nid()', $templateJson);
        $this->assertSame('$node->nid()', $result);
    }

    public function testConstantReplace(): void
    {
        $templateJson = json_encode([
            'type' => 'constant_replace',
            'old' => 'LANGUAGE_NONE',
            'new' => '\\Drupal\\Core\\Language\\LanguageInterface::LANGCODE_NOT_SPECIFIED',
        ]);

        $result = $this->template->apply('$node->language[LANGUAGE_NONE]', $templateJson);
        $this->assertSame('$node->language[\\Drupal\\Core\\Language\\LanguageInterface::LANGCODE_NOT_SPECIFIED]', $result);
    }

    public function testConstantReplaceWordBoundary(): void
    {
        $templateJson = json_encode([
            'type' => 'constant_replace',
            'old' => 'CACHE_PERMANENT',
            'new' => '\\Drupal\\Core\\Cache\\Cache::PERMANENT',
        ]);

        // Should not match partial names
        $result = $this->template->apply('MY_CACHE_PERMANENT_FLAG', $templateJson);
        $this->assertSame('MY_CACHE_PERMANENT_FLAG', $result);
    }

    public function testGlobalReplace(): void
    {
        $templateJson = json_encode([
            'type' => 'global_replace',
            'variable' => 'user',
            'replacement' => '\\Drupal::currentUser()',
        ]);

        $result = $this->template->apply('global $user;', $templateJson);
        $this->assertSame('$user = \\Drupal::currentUser();', $result);
    }

    public function testGlobalReplaceGlobalsArray(): void
    {
        $templateJson = json_encode([
            'type' => 'global_replace',
            'variable' => 'user',
            'replacement' => '\\Drupal::currentUser()',
        ]);

        $result = $this->template->apply("\$GLOBALS['user']", $templateJson);
        $this->assertSame('\\Drupal::currentUser()', $result);
    }

    public function testInvalidTemplate(): void
    {
        $this->assertNull($this->template->apply('foo', 'not json'));
        $this->assertNull($this->template->apply('foo', json_encode(['type' => 'unknown'])));
    }
}
