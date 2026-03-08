<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\TreeSitter;

use DrupalEvolver\Adapter\DrupalCoreAdapter;
use DrupalEvolver\Indexer\Extractor\PHPExtractor;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

class ParserForkSafetyTest extends TestCase
{
    private const SOURCE = '<?php class A { public function foo(int $x): int { return $x + 1; } } function bar(): void {}';

    protected function setUp(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
        }

        putenv('EVOLVER_USE_CLI=0');
        putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');

        try {
            new Parser();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Tree-sitter parser unavailable: ' . $e->getMessage());
        }
    }

    public function testChildCanCreateFreshParserWithoutManualResetAfterParentInitialization(): void
    {
        $this->requirePcntl();

        $parent = new Parser();
        $parentExtractor = new PHPExtractor($parent->registry(), new DrupalCoreAdapter());
        $parentTree = $parent->parse(self::SOURCE, 'php');
        $this->assertCount(3, $parentExtractor->extract($parentTree->rootNode(), self::SOURCE, 'fixture.php'));

        $outputFile = $this->tempFile('parser-fork-child-');

        try {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Fork failed');
            }

            if ($pid === 0) {
                try {
                    $parser = new Parser();
                    $extractor = new PHPExtractor($parser->registry(), new DrupalCoreAdapter());
                    $tree = $parser->parse(self::SOURCE, 'php');
                    $count = count($extractor->extract($tree->rootNode(), self::SOURCE, 'fixture.php'));
                    file_put_contents($outputFile, (string) $count);
                    exit($count === 3 ? 0 : 1);
                } catch (\Throwable $e) {
                    file_put_contents($outputFile, $e->getMessage());
                    exit(1);
                }
            }

            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), (string) file_get_contents($outputFile));
            $this->assertSame('3', trim((string) file_get_contents($outputFile)));
        } finally {
            @unlink($outputFile);
        }
    }

    public function testInheritedParserFailsAcrossPidBoundary(): void
    {
        $this->requirePcntl();

        $parser = new Parser();
        $warmupTree = $parser->parse(self::SOURCE, 'php');
        unset($warmupTree);

        $outputFile = $this->tempFile('parser-fork-inherited-');

        try {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Fork failed');
            }

            if ($pid === 0) {
                try {
                    $parser->parse(self::SOURCE, 'php');
                    file_put_contents($outputFile, 'no_error');
                    exit(1);
                } catch (\RuntimeException $e) {
                    file_put_contents($outputFile, $e->getMessage());
                    exit(str_contains($e->getMessage(), 'process-local') ? 0 : 1);
                } catch (\Throwable $e) {
                    file_put_contents($outputFile, $e->getMessage());
                    exit(1);
                }
            }

            pcntl_waitpid($pid, $status);
            $message = (string) file_get_contents($outputFile);

            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $message);
            $this->assertStringContainsString('process-local', $message);
        } finally {
            @unlink($outputFile);
        }
    }

    public function testFibersCanCreateFreshParsersWithoutManualResetAfterParentInitialization(): void
    {
        if (!class_exists(\Fiber::class)) {
            $this->markTestSkipped('Fibers are not available');
        }

        $parent = new Parser();
        $parentExtractor = new PHPExtractor($parent->registry(), new DrupalCoreAdapter());
        $parentTree = $parent->parse(self::SOURCE, 'php');
        $this->assertCount(3, $parentExtractor->extract($parentTree->rootNode(), self::SOURCE, 'fixture.php'));
        unset($parentTree);

        $fiberCount = 4;
        $iterationsPerFiber = 12;
        $fibers = [];

        for ($fiberIndex = 0; $fiberIndex < $fiberCount; $fiberIndex++) {
            $fibers[$fiberIndex] = new \Fiber(function () use ($fiberIndex, $iterationsPerFiber): array {
                $parser = new Parser();
                $extractor = new PHPExtractor($parser->registry(), new DrupalCoreAdapter());
                $counts = [];

                for ($iteration = 0; $iteration < $iterationsPerFiber; $iteration++) {
                    $tree = $parser->parse(self::SOURCE, 'php');
                    $counts[] = count($extractor->extract(
                        $tree->rootNode(),
                        self::SOURCE,
                        sprintf('fiber-%d.php', $fiberIndex)
                    ));
                    unset($tree);

                    if (($iteration + 1) % 4 === 0 && $iteration + 1 < $iterationsPerFiber) {
                        \Fiber::suspend();
                    }
                }

                return $counts;
            });
        }

        $results = [];
        $active = true;
        while ($active) {
            $active = false;

            foreach ($fibers as $index => $fiber) {
                if (array_key_exists($index, $results)) {
                    continue;
                }

                if (!$fiber->isStarted()) {
                    $fiber->start();
                    $active = true;
                } elseif ($fiber->isSuspended()) {
                    $fiber->resume();
                    $active = true;
                }

                if ($fiber->isTerminated()) {
                    $results[$index] = $fiber->getReturn();
                }
            }
        }

        $this->assertCount($fiberCount, $results);
        foreach ($results as $counts) {
            $this->assertCount($iterationsPerFiber, $counts);
            foreach ($counts as $count) {
                $this->assertSame(3, $count);
            }
        }
    }

    private function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            $this->fail('Failed to allocate temp file');
        }

        return $path;
    }

    private function requirePcntl(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available');
        }
    }
}
