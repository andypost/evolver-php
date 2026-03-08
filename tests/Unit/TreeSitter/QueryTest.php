<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\TreeSitter;

use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\TreeSitter\Parser;
use DrupalEvolver\TreeSitter\Query;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    private ?Parser $parser = null;

    protected function setUp(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
        }

        putenv('EVOLVER_USE_CLI=0');
        putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');

        try {
            $this->parser = new Parser();
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'libtree-sitter.so not found')) {
                $this->markTestSkipped('tree-sitter libraries not found: ' . $e->getMessage());
            }

            throw $e;
        }
    }

    public function testMatchesApplyEqPredicate(): void
    {
        $source = <<<'PHP'
<?php

final class Demo {
    public function testConfig(): void {
        $activeConfigStorage = $this->container->get('config.storage');
        $this->updater->install('demo_umami');
    }
}
PHP;

        $matches = $this->runQuery(
            '(member_call_expression name: (name) @method (#eq? @method "install"))',
            $source
        );

        $this->assertCount(1, $matches);
        $this->assertSame('install', $matches[0]['method']->text());
    }

    public function testMatchesApplyMatchPredicate(): void
    {
        $source = <<<'PHP'
<?php

function migrate_target_legacy(): void {}
function keep_current(): void {}
PHP;

        $matches = $this->runQuery(
            '(function_definition name: (name) @fn (#match? @fn "^migrate_.*_legacy$"))',
            $source
        );

        $this->assertCount(1, $matches);
        $this->assertSame('migrate_target_legacy', $matches[0]['fn']->text());
    }

    public function testMatchesClassReferenceOnlyForMatchingImport(): void
    {
        $source = $this->fixtureSource('php/demo_umami_hooks_imports.php');
        $generator = new QueryGenerator();

        $query = $generator->generate('class_removed', [
            'name' => 'Module',
            'fqn' => 'Drupal\\Core\\Updater\\Module',
            'symbol_type' => 'class',
        ]);

        $this->assertNotNull($query);

        $matches = $this->runQuery($query->pattern, $source);

        $this->assertCount(1, $matches);
        $this->assertSame('Drupal\\Core\\Updater\\Module', $matches[0]['cls_fqn']->text());
    }

    /**
     * @return list<array<string, \DrupalEvolver\TreeSitter\Node>>
     */
    private function runQuery(string $pattern, string $source): array
    {
        $binding = $this->parser?->binding();
        if ($binding === null) {
            $this->markTestSkipped('FFI parser binding is unavailable.');
        }

        $tree = $this->parser->parse($source, 'php');
        $language = $this->parser->registry()->loadLanguage('php');
        $query = new Query($binding, $pattern, $language);

        return array_values(iterator_to_array($query->matches($tree->rootNode(), $source), false));
    }

    private function fixtureSource(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/' . $relativePath);
    }
}
