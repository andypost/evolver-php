<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Memory;

use DrupalEvolver\Differ\FixTemplateGenerator;
use DrupalEvolver\Differ\RenameMatcher;
use DrupalEvolver\Differ\SignatureDiffer;
use DrupalEvolver\Differ\VersionDiffer;
use DrupalEvolver\Differ\YAMLDiffer;
use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Scanner\MatchCollector;
use DrupalEvolver\Scanner\ProjectScanner;
use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use DrupalEvolver\TreeSitter\Query;
use PHPUnit\Framework\TestCase;

class MemoryLeakTest extends TestCase
{
    private const PHP_SOURCE = <<<'PHP'
<?php

function old_func($a) {
    return $a + 1;
}

function keep_func($a, $b) {
    return old_func($a) + $b;
}

class SampleRunner {
    public function run(): void {
        old_func(1);
        old_func(2);
        keep_func(3, 4);
    }
}
PHP;

    private const FUNCTION_CALL_QUERY = '(function_call_expression function: (name) @fn (#eq? @fn "old_func") arguments: (arguments) @args)';

    protected function setUp(): void
    {
        if (!extension_loaded('meminfo')) {
            $this->markTestSkipped('meminfo extension not loaded');
        }
    }

    public function testParserTreeMemoryStability(): void
    {
        $parser = $this->createParserOrSkip();

        // Warm caches before measuring.
        $tree = $parser->parse(self::PHP_SOURCE, 'php');
        $root = $tree->rootNode();
        $this->assertSame('program', $root->type());
        $this->assertNotSame('', $root->sexp());
        unset($root, $tree);
        gc_collect_cycles();

        $this->assertRssGrowthStaysBelow(
            8 * 1024 * 1024,
            200,
            function () use ($parser): void {
                $tree = $parser->parse(self::PHP_SOURCE, 'php');
                $root = $tree->rootNode();
                $root->type();
                $root->sexp();
                $root->walk(static function ($node): void {
                    $node->type();
                });
                unset($root, $tree);
            },
            'Repeated parse/tree traversal leaked memory'
        );
    }

    public function testQueryConstructionAndMatchingMemoryStability(): void
    {
        $parser = $this->createParserOrSkip();
        $binding = $parser->binding();
        if ($binding === null) {
            $this->markTestSkipped('FFI parser binding is unavailable.');
        }

        $language = $parser->registry()->loadLanguage('php');

        // Warm up query construction and cursor execution.
        $tree = $parser->parse(self::PHP_SOURCE, 'php');
        $query = new Query($binding, self::FUNCTION_CALL_QUERY, $language);
        $warmupMatches = iterator_to_array($query->matches($tree->rootNode(), self::PHP_SOURCE));
        $this->assertNotEmpty($warmupMatches);
        unset($warmupMatches, $query, $tree);
        gc_collect_cycles();

        $this->assertRssGrowthStaysBelow(
            10 * 1024 * 1024,
            150,
            function () use ($parser, $binding, $language): void {
                $tree = $parser->parse(self::PHP_SOURCE, 'php');
                $query = new Query($binding, self::FUNCTION_CALL_QUERY, $language);
                $matches = iterator_to_array($query->matches($tree->rootNode(), self::PHP_SOURCE));
                foreach ($matches as $captures) {
                    foreach ($captures as $node) {
                        $node->text();
                        $node->startByte();
                        $node->endByte();
                    }
                }
                unset($matches, $query, $tree);
            },
            'Repeated query construction/matching leaked memory'
        );
    }

    public function testMatchCollectorMemoryStability(): void
    {
        $parser = $this->createParserOrSkip();
        $binding = $parser->binding();
        if ($binding === null) {
            $this->markTestSkipped('FFI parser binding is unavailable.');
        }

        $collector = new MatchCollector($binding, $parser->registry());
        $changes = [[
            'id' => 1,
            'change_type' => 'function_removed',
            'old_fqn' => 'old_func',
            'ts_query' => self::FUNCTION_CALL_QUERY,
            'fix_template' => null,
        ]];

        // Warm caches in MatchCollector (language + query cache).
        $tree = $parser->parse(self::PHP_SOURCE, 'php');
        $warmup = iterator_to_array($collector->collectMatches($tree->rootNode(), self::PHP_SOURCE, 'php', $changes));
        $this->assertNotEmpty($warmup);
        unset($warmup, $tree);
        gc_collect_cycles();

        $this->assertRssGrowthStaysBelow(
            10 * 1024 * 1024,
            200,
            function () use ($parser, $collector, $changes): void {
                $tree = $parser->parse(self::PHP_SOURCE, 'php');
                $matches = iterator_to_array($collector->collectMatches($tree->rootNode(), self::PHP_SOURCE, 'php', $changes));
                foreach ($matches as $match) {
                    $match['matched_source'] ?? null;
                    $match['byte_start'] ?? null;
                    $match['byte_end'] ?? null;
                }
                unset($matches, $tree);
            },
            'Repeated MatchCollector scans leaked memory'
        );
    }

    public function testIndexingMemoryStability(): void
    {
        $parser = $this->createParserOrSkip();
        $path = __DIR__ . '/../../../src';

        // Warm parser/grammar caches before measuring. Each measured iteration
        // uses a fresh in-memory database so RSS growth reflects retained state,
        // not intentionally accumulated SQLite data.
        $api = new DatabaseApi(':memory:');
        $indexer = new CoreIndexer($parser, $api);
        $indexer->setWorkerCount(1);
        $indexer->index($path, '1.0.0', null);
        unset($indexer, $api);
        gc_collect_cycles();

        $this->assertPhpHeapGrowthStaysBelow(
            8 * 1024 * 1024,
            4,
            function (int $iteration) use ($parser, $path): void {
                $api = new DatabaseApi(':memory:');
                $indexer = new CoreIndexer($parser, $api);
                $indexer->setWorkerCount(1);
                $tag = '1.0.' . ($iteration + 1);
                $indexer->index($path, $tag, null);
                unset($indexer, $api);
            },
            'Repeated indexing leaked memory'
        );
    }

    public function testProjectScannerMemoryStability(): void
    {
        $parser = $this->createParserOrSkip();
        $binding = $parser->binding();
        if ($binding === null) {
            $this->markTestSkipped('FFI parser binding is unavailable.');
        }

        [$api, $projectDir] = $this->createProjectScannerFixture();
        $scanner = new ProjectScanner(
            $parser,
            $api,
            new MatchCollector($binding, $parser->registry()),
        );
        $scanner->setWorkerCount(1);

        try {
            $scanRunId = $scanner->scan($projectDir, '1.1.0', '1.0.0');
            $this->assertGreaterThan(0, $scanRunId);
            $project = $api->projects()->findByPath($projectDir);
            $this->assertNotNull($project);

            $pending = $api->matches()->findPending((int) $project['id']);
            $this->assertCount(6, $pending);
            gc_collect_cycles();

            $this->assertRssGrowthStaysBelow(
                12 * 1024 * 1024,
                30,
                function () use ($scanner, $projectDir): void {
                    (void) $scanner->scan($projectDir, '1.1.0', '1.0.0');
                },
                'Repeated project scans leaked memory'
            );
        } finally {
            $this->removeDir($projectDir);
        }
    }

    public function testVersionDifferMemoryStability(): void
    {
        [$api, $differ] = $this->createVersionDifferFixture();

        $warmupChanges = $differ->diff('10.0.0', '10.1.0');
        $this->assertNotEmpty($warmupChanges);
        gc_collect_cycles();

        $this->assertPhpHeapGrowthStaysBelow(
            10 * 1024 * 1024,
            12,
            function () use ($differ): void {
                $changes = $differ->diff('10.0.0', '10.1.0');
                $this->assertNotEmpty($changes);
            },
            'Repeated version diffs leaked memory'
        );

        $fromVersion = $api->versions()->findByTag('10.0.0');
        $toVersion = $api->versions()->findByTag('10.1.0');
        $this->assertNotNull($fromVersion);
        $this->assertNotNull($toVersion);

        $storedCount = $api->changes()->countByVersions((int) $fromVersion['id'], (int) $toVersion['id']);
        $this->assertGreaterThan(0, $storedCount);
    }

    private function createParserOrSkip(): Parser
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
        }

        putenv('EVOLVER_USE_CLI=0');
        putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');

        try {
            return new Parser();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Tree-sitter parser unavailable: ' . $e->getMessage());
        }
    }

    /**
     * @return array{DatabaseApi, string}
     */
    private function createProjectScannerFixture(): array
    {
        $api = new DatabaseApi(':memory:');
        $queryGenerator = new QueryGenerator();

        $fromId = $api->versions()->create('1.0.0', 1, 0, 0);
        $toId = $api->versions()->create('1.1.0', 1, 1, 0);
        $fileId = $api->files()->create($fromId, 'core/lib/old.php', 'php', 'old-hash', null, null, 5, 150);

        $oldSymbol = [
            'id' => $api->symbols()->create([
                'version_id' => $fromId,
                'file_id' => $fileId,
                'language' => 'php',
                'symbol_type' => 'function',
                'fqn' => 'old_func',
                'name' => 'old_func',
                'signature_hash' => 'old-func-hash',
                'signature_json' => '{"params":[{"name":"$a","type":"int"}],"return_type":"int"}',
                'source_text' => 'function old_func(int $a): int { return $a + 1; }',
            ]),
            'symbol_type' => 'function',
            'fqn' => 'old_func',
            'name' => 'old_func',
        ];

        $changeId = $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_symbol_id' => $oldSymbol['id'],
            'old_fqn' => 'old_func',
            'ts_query' => $queryGenerator->generate('function_removed', $oldSymbol),
        ]);
        $this->assertGreaterThan(0, $changeId);

        $projectDir = $this->createTempDir('evolver-scan-mem-');
        file_put_contents($projectDir . '/alpha.php', "<?php\nold_func(1);\nold_func(2);\n");
        file_put_contents($projectDir . '/beta.php', "<?php\nif (true) {\n    old_func(3);\n    old_func(4);\n}\n");
        file_put_contents($projectDir . '/gamma.php', "<?php\nold_func(5);\nold_func(6);\n");
        file_put_contents($projectDir . '/ignore.txt', "old_func(7)\n");

        return [$api, $projectDir];
    }

    /**
     * @return array{DatabaseApi, VersionDiffer}
     */
    private function createVersionDifferFixture(): array
    {
        $api = new DatabaseApi(':memory:');
        $differ = new VersionDiffer(
            $api,
            new SignatureDiffer(),
            new RenameMatcher(),
            new YAMLDiffer(),
            new FixTemplateGenerator(),
            new QueryGenerator(),
        );
        $differ->setWorkerCount(1);

        $fromId = $api->versions()->create('10.0.0', 10, 0, 0);
        $toId = $api->versions()->create('10.1.0', 10, 1, 0);
        $oldFileId = $api->files()->create($fromId, 'core/old.php', 'php', 'old-hash', null, null, 10, 100);
        $newFileId = $api->files()->create($toId, 'core/new.php', 'php', 'new-hash', null, null, 10, 100);

        for ($i = 0; $i < 140; $i++) {
            $shortName = 'Service' . $i;
            $oldClassId = $api->symbols()->create([
                'version_id' => $fromId,
                'file_id' => $oldFileId,
                'language' => 'php',
                'symbol_type' => 'class',
                'fqn' => 'Drupal\\Legacy\\' . $shortName,
                'name' => $shortName,
                'signature_hash' => 'class-old-' . $i,
                'signature_json' => '{"parent":null,"interfaces":[]}',
                'source_text' => 'class ' . $shortName . ' {}',
            ]);
            $this->assertGreaterThan(0, $oldClassId);

            $newClassId = $api->symbols()->create([
                'version_id' => $toId,
                'file_id' => $newFileId,
                'language' => 'php',
                'symbol_type' => 'class',
                'fqn' => 'Drupal\\Modern\\' . $shortName,
                'name' => $shortName,
                'signature_hash' => 'class-new-' . $i,
                'signature_json' => '{"parent":null,"interfaces":[]}',
                'source_text' => 'class ' . $shortName . ' {}',
            ]);
            $this->assertGreaterThan(0, $newClassId);
        }

        for ($i = 0; $i < 40; $i++) {
            $fqn = 'Drupal\\Tools\\sig_changed_' . $i;
            $oldFunctionId = $api->symbols()->create([
                'version_id' => $fromId,
                'file_id' => $oldFileId,
                'language' => 'php',
                'symbol_type' => 'function',
                'fqn' => $fqn,
                'name' => 'sig_changed_' . $i,
                'signature_hash' => 'sig-old-' . $i,
                'signature_json' => '{"params":[{"name":"$a","type":"string"}],"return_type":null}',
                'source_text' => 'function sig_changed_' . $i . '(string $a) {}',
            ]);
            $this->assertGreaterThan(0, $oldFunctionId);
            $newFunctionId = $api->symbols()->create([
                'version_id' => $toId,
                'file_id' => $newFileId,
                'language' => 'php',
                'symbol_type' => 'function',
                'fqn' => $fqn,
                'name' => 'sig_changed_' . $i,
                'signature_hash' => 'sig-new-' . $i,
                'signature_json' => '{"params":[{"name":"$a","type":"string"},{"name":"$context","type":"array"}],"return_type":null}',
                'source_text' => 'function sig_changed_' . $i . '(string $a, array $context) {}',
            ]);
            $this->assertGreaterThan(0, $newFunctionId);
        }

        for ($i = 0; $i < 20; $i++) {
            $deprecatedId = $api->symbols()->create([
                'version_id' => $fromId,
                'file_id' => $oldFileId,
                'language' => 'php',
                'symbol_type' => 'function',
                'fqn' => 'Drupal\\Legacy\\deprecated_' . $i,
                'name' => 'deprecated_' . $i,
                'signature_hash' => 'deprecated-' . $i,
                'signature_json' => '{"params":[],"return_type":null}',
                'source_text' => 'function deprecated_' . $i . '() {}',
                'is_deprecated' => 1,
                'deprecation_message' => 'Use modern API ' . $i,
            ]);
            $this->assertGreaterThan(0, $deprecatedId);
        }

        return [$api, $differ];
    }

    private function assertRssGrowthStaysBelow(
        int $maxGrowthBytes,
        int $iterations,
        callable $operation,
        string $message,
    ): void {
        gc_collect_cycles();
        $initialRss = $this->currentResidentSetSize();

        for ($i = 0; $i < $iterations; $i++) {
            $operation($i);
            if (($i + 1) % 10 === 0) {
                gc_collect_cycles();
            }
        }

        gc_collect_cycles();
        $finalRss = $this->currentResidentSetSize();
        $rssGrowth = $finalRss - $initialRss;

        $this->assertLessThan(
            $maxGrowthBytes,
            $rssGrowth,
            sprintf(
                '%s (RSS grew by %d bytes, limit %d bytes)',
                $message,
                $rssGrowth,
                $maxGrowthBytes
            )
        );
    }

    private function currentResidentSetSize(): int
    {
        $status = @file_get_contents('/proc/self/status');
        if (is_string($status) && preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $matches) === 1) {
            return (int) $matches[1] * 1024;
        }

        return memory_get_usage(true);
    }

    private function assertPhpHeapGrowthStaysBelow(
        int $maxGrowthBytes,
        int $iterations,
        callable $operation,
        string $message,
    ): void {
        gc_collect_cycles();
        $initialUsage = memory_get_usage(true);

        for ($i = 0; $i < $iterations; $i++) {
            $operation($i);
            gc_collect_cycles();
        }

        gc_collect_cycles();
        $finalUsage = memory_get_usage(true);
        $usageGrowth = $finalUsage - $initialUsage;

        $this->assertLessThan(
            $maxGrowthBytes,
            $usageGrowth,
            sprintf(
                '%s (PHP heap grew by %d bytes, limit %d bytes)',
                $message,
                $usageGrowth,
                $maxGrowthBytes
            )
        );
    }

    private function createTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        $dir = $base . '/' . $prefix . uniqid('', true);
        mkdir($dir, 0777, true);
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
