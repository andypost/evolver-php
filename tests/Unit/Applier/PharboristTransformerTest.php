<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Applier;

use DrupalEvolver\Applier\PharboristTransformer;
use PHPUnit\Framework\TestCase;

final class PharboristTransformerTest extends TestCase
{
    private PharboristTransformer $transformer;

    protected function setUp(): void
    {
        if (!class_exists(\Pharborist\Parser::class)) {
            $this->markTestSkipped('Pharborist is not installed.');
        }

        $this->transformer = new PharboristTransformer();
    }

    public function testRenameFunctionCalls(): void
    {
        $source = <<<'PHP'
<?php

$safe = check_plain($text);
PHP;

        $result = $this->transformer->transform($source, [
            'action' => 'rename_function_calls',
            'old' => 'check_plain',
            'new' => '\Drupal\Component\Utility\Html::escape',
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString('\Drupal\Component\Utility\Html::escape($text)', $result);
    }

    public function testRewriteToMethodChain(): void
    {
        $source = <<<'PHP'
<?php

$node = entity_load('node', 123);
PHP;

        $result = $this->transformer->transform($source, [
            'action' => 'rewrite_to_method_chain',
            'function' => 'entity_load',
            'object' => '\Drupal::entityTypeManager()',
            'chain' => [
                ['method' => 'getStorage', 'args' => [0]],
                ['method' => 'load', 'args' => [1]],
            ],
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString("\$node = \\Drupal::entityTypeManager()->getStorage('node')->load(123);", $result);
    }

    public function testRewritePropertyAccess(): void
    {
        $source = <<<'PHP'
<?php

$nid = $node->nid;
$node->nid();
PHP;

        $result = $this->transformer->transform($source, [
            'action' => 'rewrite_property_access',
            'mappings' => [
                'nid' => 'id',
            ],
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString('$nid = $node->id();', $result);
        $this->assertStringContainsString('$node->nid();', $result);
    }

    public function testRewriteGlobalVariable(): void
    {
        $source = <<<'PHP'
<?php

global $user;
$account = $GLOBALS['user'];
PHP;

        $result = $this->transformer->transform($source, [
            'action' => 'rewrite_global_variable',
            'variable' => 'user',
            'replacement' => '\Drupal::currentUser()',
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString('$user = \Drupal::currentUser();', $result);
        $this->assertStringContainsString('$account = \Drupal::currentUser();', $result);
    }

    public function testAnnotationToAttributeConvertsSimpleDrupalPluginAnnotation(): void
    {
        $source = <<<'PHP'
<?php

namespace Drupal\demo\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * @Block(
 *   id = "demo_block"
 * )
 */
class DemoBlock extends BlockBase {}
PHP;

        $result = $this->transformer->transform($source, [
            'action' => 'annotation_to_attribute',
            'annotation' => 'Block',
            'attribute' => 'Block',
            'attribute_import' => 'Drupal\Core\Block\Attribute\Block',
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString("use Drupal\\Core\\Block\\Attribute\\Block;", $result);
        $this->assertStringContainsString('#[Block(id: "demo_block")]', $result);
        $this->assertStringNotContainsString('@Block(', $result);
    }

    public function testAnnotationToAttributePreservesUnrelatedDocblockText(): void
    {
        $source = <<<'PHP'
<?php

namespace Drupal\demo\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the demo block.
 *
 * @Block(
 *   id = "demo_block"
 * )
 */
class DemoBlock extends BlockBase {}
PHP;

        $result = $this->transformer->transform($source, [
            'action' => 'annotation_to_attribute',
            'annotation' => 'Block',
            'attribute' => 'Block',
            'attribute_import' => 'Drupal\Core\Block\Attribute\Block',
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString("Provides the demo block.", $result);
        $this->assertMatchesRegularExpression('/\/\*\*[\s\S]*Provides the demo block\.[\s\S]*\*\//', $result);
        $this->assertStringContainsString("#[Block(", $result);
    }
}
