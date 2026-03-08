<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Scanner;

use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Scanner\MatchCollector;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

final class MatchCollectorTest extends TestCase
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

    public function testCollectMatchesUsesPredicateFilteredNodes(): void
    {
        $source = <<<'PHP'
<?php

final class DemoUmamiProfileTest {
    protected function testConfig(): void {
        $activeConfigStorage = $this->container->get('config.storage');
        $this->updater->install('demo_umami');
        $this->archiver->add('umami.zip');
    }
}
PHP;

        $generator = new QueryGenerator();
        $changes = [
            [
                'id' => 1,
                'change_type' => 'deprecated_added',
                'old_fqn' => 'Drupal\\Core\\Updater\\Updater::install',
                'ts_query' => $generator->generate('deprecated_added', [
                    'name' => 'install',  // Just the method name, not Class::method
                    'symbol_type' => 'method',
                ])?->pattern,
                'fix_template' => null,
                'metadata' => [
                    'change_type' => 'deprecated_added',
                    'severity' => 'deprecation',
                    'old_fqn' => 'Drupal\\Core\\Updater\\Updater::install',
                ],
            ],
            [
                'id' => 2,
                'change_type' => 'method_removed',
                'old_fqn' => 'Drupal\\Core\\Archiver\\ArchiverInterface::add',
                'ts_query' => $generator->generate('method_removed', [
                    'name' => 'add',  // Just the method name
                    'symbol_type' => 'method',
                ])?->pattern,
                'fix_template' => null,
                'metadata' => [
                    'change_type' => 'method_removed',
                    'severity' => 'removal',
                    'old_fqn' => 'Drupal\\Core\\Archiver\\ArchiverInterface::add',
                ],
            ],
        ];

        $binding = $this->parser?->binding();
        if ($binding === null) {
            $this->markTestSkipped('FFI parser binding is unavailable.');
        }

        $collector = new MatchCollector($binding, $this->parser->registry());
        $tree = $this->parser->parse($source, 'php');

        $matches = array_values(iterator_to_array(
            $collector->collectMatches($tree->rootNode(), $source, 'php', $changes),
            false
        ));

        $this->assertCount(2, $matches);
        $this->assertSame([6, 7], array_map(fn($m) => $m->lineStart, $matches));
        $this->assertSame(['install', 'add'], array_map(fn($m) => $m->matchedSource, $matches));
    }

    public function testCollectMatchesUsesOnlyMatchingImportForClassRemoval(): void
    {
        $source = $this->fixtureSource('php/demo_umami_hooks_imports.php');
        $generator = new QueryGenerator();
        $changes = [
            [
                'id' => 1,
                'change_type' => 'class_removed',
                'old_fqn' => 'Drupal\\Core\\Updater\\Module',
                'ts_query' => $generator->generate('class_removed', [
                    'name' => 'Module',
                    'fqn' => 'Drupal\\Core\\Updater\\Module',
                    'symbol_type' => 'class',
                ])?->pattern,
                'fix_template' => null,
                'metadata' => [
                    'change_type' => 'class_removed',
                    'severity' => 'removal',
                    'old_fqn' => 'Drupal\\Core\\Updater\\Module',
                ],
            ],
        ];

        $binding = $this->parser?->binding();
        if ($binding === null) {
            $this->markTestSkipped('FFI parser binding is unavailable.');
        }

        $collector = new MatchCollector($binding, $this->parser->registry());
        $tree = $this->parser->parse($source, 'php');

        $matches = array_values(iterator_to_array(
            $collector->collectMatches($tree->rootNode(), $source, 'php', $changes),
            false
        ));

        $this->assertCount(1, $matches);
        $this->assertSame(6, $matches[0]->lineStart);
        $this->assertSame('Drupal\\Core\\Updater\\Module', $matches[0]->matchedSource);
    }

    private function fixtureSource(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/' . $relativePath);
    }
}
